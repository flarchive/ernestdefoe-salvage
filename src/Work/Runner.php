<?php

namespace Ernestdefoe\Salvage\Work;

use Carbon\Carbon;
use Ernestdefoe\Salvage\Link;
use Ernestdefoe\Salvage\Settings;
use Ernestdefoe\Salvage\Tracker;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Database\ConnectionInterface;

/**
 * One bounded round of work, started every five minutes by the scheduler
 * through the queue, so it never runs inside a page request.
 *
 * Bounded every way: one round at a time (a lock), ~50 seconds, a request
 * budget per round, at most three requests to any one host per round, a
 * host that times out or refuses is left alone for the rest of the round,
 * and failing links back off for hours to days. A site that dies takes a
 * handful of requests to notice, not one per post that links to it.
 */
class Runner
{
    public const LOCK = 'ernestdefoe-salvage.work';
    public const QUEUED = 'ernestdefoe-salvage.queued';
    public const SECONDS = 50;
    public const PER_HOST = 3;
    public const SCAN_CHUNK = 500;
    public const FLAGS_PER_RUN = 100;
    public const CLEANUP_WINDOW = 2000;
    public const CLEANUP_CURSOR = 'ernestdefoe-salvage.cleanup_cursor';
    private const HOST_DOWN = ['dns', 'timeout', 'connect', 'tls'];

    public function __construct(
        private readonly Settings $settings,
        private readonly Tracker $tracker,
        private readonly Checker $checker,
        private readonly Mirror $mirror,
        private readonly Archive $archive,
        private readonly Applier $applier,
        private readonly Flagger $flagger,
        private readonly Cache $cache,
        private readonly ConnectionInterface $db,
        private readonly Factory $filesystem,
    ) {
    }

    /**
     * @return array<string, int>|null what the round did, or null when another round holds the lock
     */
    public function run(): ?array
    {
        $this->cache->forget(self::QUEUED);

        $store = $this->cache->getStore();
        // Every store Flarum ships (file, redis, database, array) can lock.
        $lock = $store instanceof LockProvider ? $store->lock(self::LOCK, 600) : null;

        if ($lock !== null && ! $lock->get()) {
            return null;
        }

        try {
            $deadline = microtime(true) + self::SECONDS;

            $scanned = $this->scan(microtime(true) + 10);
            $applied = $this->applyPending($deadline);
            $network = $this->network($deadline);

            return [
                'scanned' => $scanned,
                // Again: what this round mirrored or archived reaches the posts now, not in five minutes.
                'applied' => $applied + $this->applyPending($deadline + 10),
                ...$network,
                'removed' => $this->cleanup(),
            ];
        } finally {
            $lock?->release();
        }
    }

    /**
     * The backfill: existing posts, 500 at a time. Database only.
     */
    private function scan(float $until): int
    {
        $cursor = $this->settings->scanCursor();
        $scanned = 0;

        while ($cursor !== null && microtime(true) < $until) {
            $posts = $this->db->table('posts')
                ->where('type', 'comment')
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit(self::SCAN_CHUNK)
                ->pluck('content', 'id');

            $this->tracker->record($posts->all());
            $scanned += $posts->count();
            $cursor = $posts->count() < self::SCAN_CHUNK ? null : (int) $posts->keys()->last();
            $this->settings->setScanCursor($cursor);
        }

        return $scanned;
    }

    private function applyPending(float $deadline): int
    {
        $done = 0;

        foreach (Link::query()->where('apply_pending', true)->orderBy('id')->limit(50)->get() as $link) {
            if (! $this->applier->apply($link, $deadline)) {
                break;
            }

            $done++;
        }

        return $done;
    }

    /**
     * @return array{requests: int, broken: int}
     */
    private function network(float $deadline): array
    {
        $checking = $this->settings->on(Settings::CHECK_LINKS);
        $mirroringOn = $this->settings->on(Settings::MIRROR_IMAGES);
        $mirroring = $mirroringOn && ! $this->mirror->overDailyCap();
        $budget = $this->settings->int(Settings::PER_RUN, 5, 200);
        $spent = 0;
        $newlyBroken = [];

        if (! $checking && ! $mirroring) {
            return ['requests' => 0, 'broken' => 0];
        }

        $due = Link::query()->where('next_check_at', '<=', Carbon::now())->orderBy('next_check_at')->limit($budget * 4);

        if (! $checking) {
            $due->where('kind', 'image')->whereNull('action')->where('mirror_tries', '<', Mirror::MAX_TRIES);
        } elseif ($mirroringOn && ! $mirroring) {
            // Images waiting for tomorrow's allowance must not crowd out the links.
            $due->where(fn ($q) => $q->where('kind', 'link')->orWhereNotNull('action')->orWhere('mirror_tries', '>=', Mirror::MAX_TRIES));
        }

        $perHost = [];
        $down = [];

        foreach ($due->get() as $link) {
            if ($spent >= $budget || microtime(true) > $deadline) {
                break;
            }

            if (isset($down[$link->host]) || ($perHost[$link->host] ?? 0) >= self::PER_HOST) {
                continue;
            }

            // The skip list may have grown since the link was first seen.
            if ($reason = $this->tracker->skipReason($link->url, $link->host)) {
                $link->status = 'skipped';
                $link->error = $reason;
                $link->next_check_at = null;
                $link->save();

                continue;
            }

            $mirrorable = $mirroringOn && $link->kind === 'image' && $link->action === null && $link->mirror_tries < Mirror::MAX_TRIES;

            // Past today's mirroring allowance an image waits for tomorrow's,
            // rather than being checked and put off for a month.
            if ($mirrorable && ! $mirroring) {
                continue;
            }

            $perHost[$link->host] = ($perHost[$link->host] ?? 0) + 1;
            $wasBroken = $link->status === 'broken';

            if ($mirrorable) {
                $spent += $this->mirror->mirror($link);
                $mirroring = ! $this->mirror->overDailyCap();
            } elseif ($checking) {
                $spent += $this->checker->check($link);
            } else {
                continue;
            }

            if (in_array($link->error, self::HOST_DOWN, true)) {
                $down[$link->host] = true;
            }

            if (! $wasBroken && $link->status === 'broken') {
                $newlyBroken[] = $link->id;
            }
        }

        if ($checking && $this->settings->on(Settings::ARCHIVE)) {
            $spent += $this->archive(min(self::PER_HOST, max(0, $budget - $spent)), $deadline);
        }

        if ($newlyBroken !== [] && $this->settings->on(Settings::FLAG_POSTS)) {
            $this->flagger->flag($newlyBroken, self::FLAGS_PER_RUN);
        }

        return ['requests' => $spent, 'broken' => count($newlyBroken)];
    }

    private function archive(int $budget, float $deadline): int
    {
        $spent = 0;
        $auto = $this->settings->on(Settings::AUTO_ARCHIVE);

        $links = $budget < 1 ? [] : Link::query()
            ->where('status', 'broken')
            ->whereNull('action')
            ->whereNull('archive_checked_at')
            ->orderBy('broken_at')
            ->limit($budget)
            ->get();

        foreach ($links as $link) {
            if (microtime(true) > $deadline || $this->archive->paused()) {
                break;
            }

            $spent += $this->archive->lookup($link);

            if ($auto && $link->archive_url !== null) {
                $link->action = 'replace';
                $link->setReplacement($link->archive_url);
                $link->apply_pending = true;
                $link->next_check_at = null;
                $link->save();
                $this->flagger->clear($link->id);
            }
        }

        return $spent;
    }

    /**
     * Links no post holds any more, a day after they were first seen (so a
     * post being edited does not lose its history), with their mirror files
     * when nothing else uses them.
     */
    private function cleanup(): int
    {
        // A window of ids per round, so the sweep costs the same on a forum
        // with a million links as on one with a hundred.
        $from = (int) $this->cache->get(self::CLEANUP_CURSOR, 0);
        $to = $from + self::CLEANUP_WINDOW;
        $this->cache->forever(self::CLEANUP_CURSOR, $to > (int) Link::query()->max('id') ? 0 : $to);

        $orphans = Link::query()
            ->where('id', '>', $from)
            ->where('id', '<=', $to)
            ->whereDoesntHave('posts')
            ->where('created_at', '<', Carbon::now()->subDay())
            ->get(['id', 'mirror_path']);

        if ($orphans->isEmpty()) {
            return 0;
        }

        Link::query()->whereIn('id', $orphans->pluck('id'))->delete();

        $paths = $orphans->pluck('mirror_path')->filter()->unique()->values();

        if ($paths->isNotEmpty()) {
            $kept = Link::query()->whereIn('mirror_path', $paths)->pluck('mirror_path')->all();
            $disk = $this->filesystem->disk(Mirror::DISK);

            foreach ($paths->diff($kept) as $path) {
                $disk->delete($path);
            }
        }

        return $orphans->count();
    }
}
