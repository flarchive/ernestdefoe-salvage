<?php

namespace Ernestdefoe\Salvage\Api;

use Carbon\Carbon;
use Ernestdefoe\Salvage\Link;
use Ernestdefoe\Salvage\Work\Applier;
use Ernestdefoe\Salvage\Work\Flagger;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/salvage/links/{id} {"action": "...", "replacement": "https://..."}
 *
 *   replace   point the link at another URL (the visible text stays)
 *   archive   point it at its archive.org copy
 *   unlink    keep the text, drop the link (or the image, keeping its alt text)
 *   ignore    stop reporting and checking it
 *   restore   undo replace/archive/mirror: posts get the original URL back
 *   recheck   check it again on the next round
 *   mirror    try mirroring an image again
 *
 * Posts are rewritten here for up to five seconds (database only); a link in
 * more posts than that finishes in the scheduled rounds. Admins only.
 */
class DecideController implements RequestHandlerInterface
{
    public const ACTIONS = ['replace', 'archive', 'unlink', 'ignore', 'restore', 'recheck', 'mirror'];

    public function __construct(
        private readonly Applier $applier,
        private readonly Flagger $flagger,
        private readonly TranslatorInterface $translator,
        private readonly ConnectionInterface $db,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $link = Link::query()->findOrFail((int) Arr::get($request->getQueryParams(), 'id'));
        $body = (array) $request->getParsedBody();
        $action = Arr::get($body, 'action');

        if (! in_array($action, self::ACTIONS, true)) {
            $this->fail('unknown_action');
        }

        if ($link->apply_pending) {
            $this->fail('still_applying');
        }

        match ($action) {
            'replace' => $this->replace($link, self::validUrl(Arr::get($body, 'replacement')) ?? $this->fail('bad_url')),
            'archive' => $this->replace($link, $link->archive_url ?? $this->fail('no_archive')),
            'unlink' => $link->action === 'mirror' ? $this->fail('undo_first') : $this->decide($link, 'unlink'),
            'ignore' => $link->action === null ? $this->decide($link, 'ignore') : $this->fail('undo_first'),
            'restore' => $this->restore($link),
            'recheck' => $this->recheck($link),
            'mirror' => $this->retryMirror($link),
        };

        if ($link->apply_pending) {
            $this->applier->apply($link, microtime(true) + 5);
        }

        if (in_array($link->action, ['replace', 'unlink', 'ignore'], true)) {
            $this->flagger->clear($link->id);
        }

        $usage = $this->db->table('salvage_link_post')->where('link_id', $link->id)
            ->selectRaw('count(*) as posts, min(post_id) as first_post')->first();
        $first = $usage && $usage->first_post ? Posts::describe($this->db, [$usage->first_post]) : [];

        return new JsonResponse(LinksController::serialize($link->refresh(), (int) ($usage->posts ?? 0), $first[(int) ($usage->first_post ?? 0)] ?? null));
    }

    /**
     * http(s) only, no control characters, and a URL PHP itself accepts:
     * the value goes into posts' href and src attributes as it is.
     */
    public static function validUrl(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return null;
        }

        return preg_match('~^https?://~i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }

    private function replace(Link $link, string $url): void
    {
        if ($url === $link->url) {
            $this->fail('bad_url');
        }

        // A mirror's file and an unlinked post's structure are undone first.
        if ($link->action === 'unlink' || $link->action === 'mirror') {
            $this->fail('undo_first');
        }

        $link->setReplacement($url);
        $this->decide($link, 'replace');
    }

    private function decide(Link $link, string $action): void
    {
        $link->action = $action;
        $link->apply_pending = $action !== 'ignore';
        $link->next_check_at = null;
        $link->save();
    }

    private function restore(Link $link): void
    {
        match ($link->action) {
            'replace', 'mirror' => $this->decide($link, 'restore'),
            'ignore' => $this->recheck($link),
            default => $this->fail('cannot_restore'),
        };
    }

    private function recheck(Link $link): void
    {
        if ($link->action !== null && $link->action !== 'ignore') {
            $this->fail('undo_first');
        }

        // A link still on the skip list is skipped again by the next round.
        $link->action = null;
        $link->fail_count = 0;
        $link->status = 'pending';
        $link->error = null;
        $link->next_check_at = Carbon::now();
        $link->save();
    }

    private function retryMirror(Link $link): void
    {
        if ($link->kind !== 'image' || $link->action !== null) {
            $this->fail('undo_first');
        }

        $link->mirror_tries = 0;
        $link->next_check_at = Carbon::now();
        $link->save();
    }

    private function fail(string $key): never
    {
        throw new ValidationException(['action' => $this->translator->trans("ernestdefoe-salvage.api.errors.$key")]);
    }
}
