<?php

namespace Ernestdefoe\Salvage;

use Carbon\Carbon;
use Ernestdefoe\Salvage\Content\Xml;
use Ernestdefoe\Salvage\Net\Refused;
use Ernestdefoe\Salvage\Net\UrlGuard;
use Flarum\Foundation\Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Knows which URLs each post holds. Database only: nothing here touches the
 * network, so it is safe to run inside a request.
 */
class Tracker
{
    /** A post with more URLs than this is tracked for its first ones only. */
    public const MAX_PER_POST = 100;

    private ?string $ownHost = null;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Settings $settings,
        private readonly Config $config,
    ) {
    }

    /**
     * Applies every decision already made about the post's URLs to its XML.
     *
     * @return array{xml: string, ids: list<int>}
     */
    public function prepare(string $xml): array
    {
        $links = $this->links([Xml::urls($xml)]);

        return ['xml' => self::applyTo($xml, $links), 'ids' => $links->pluck('id')->map(fn ($id) => (int) $id)->values()->all()];
    }

    /**
     * Records the posts' URLs in bulk (the backfill scan). A post that holds a
     * URL staff already decided about is queued for rewriting.
     *
     * @param array<int, string|null> $contents post id => stored XML
     */
    public function record(array $contents): void
    {
        $found = array_map(fn ($xml) => Xml::urls($xml), $contents);
        $links = $this->links($found);
        $byUrl = [];

        foreach ($links as $link) {
            $byUrl[$link->url] = $link->id;

            if ($link->replacement !== null) {
                $byUrl[$link->replacement] = $link->id;
            }
        }

        $rows = [];

        foreach ($found as $postId => $urls) {
            foreach (array_merge($urls['links'], $urls['images']) as $url) {
                if (isset($byUrl[$url])) {
                    $rows[$byUrl[$url].':'.$postId] = ['link_id' => $byUrl[$url], 'post_id' => $postId];
                }
            }
        }

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            $this->db->table('salvage_link_post')->insertOrIgnore($chunk);
        }

        $decided = $links->filter(fn (Link $link) => in_array($link->action, ['mirror', 'replace', 'unlink'], true))->pluck('id')->all();

        if ($decided !== []) {
            Link::query()->whereIn('id', $decided)->update(['apply_pending' => true]);
        }
    }

    /**
     * @param list<int> $ids
     */
    public function sync(int $postId, array $ids): void
    {
        $table = $this->db->table('salvage_link_post');
        $current = $table->where('post_id', $postId)->pluck('link_id')->map(fn ($id) => (int) $id)->all();

        if ($gone = array_diff($current, $ids)) {
            $this->db->table('salvage_link_post')->where('post_id', $postId)->whereIn('link_id', $gone)->delete();
        }

        if ($new = array_diff($ids, $current)) {
            $this->db->table('salvage_link_post')->insertOrIgnore(array_map(fn ($id) => ['link_id' => $id, 'post_id' => $postId], array_values($new)));
        }
    }

    /**
     * @param Collection<int, Link> $links
     */
    public static function applyTo(string $xml, Collection $links): string
    {
        $replace = [];
        $unwrap = [];

        foreach ($links as $link) {
            // A post may already carry the replacement of an earlier decision.
            $carried = array_filter([$link->url, $link->replacement, $link->prior]);

            if (($link->action === 'mirror' || $link->action === 'replace') && $link->replacement !== null) {
                foreach ($carried as $url) {
                    $replace[$url] = $link->replacement;
                }
            } elseif ($link->action === 'restore') {
                foreach ($carried as $url) {
                    $replace[$url] = $link->url;
                }
            } elseif ($link->action === 'unlink') {
                foreach ($carried as $url) {
                    $unwrap[$url] = true;
                }
            }
        }

        return $replace === [] && $unwrap === [] ? $xml : Xml::rewrite($xml, $replace, $unwrap);
    }

    /**
     * Finds (by URL or by a replacement already written into a post) and
     * creates the rows for every URL found. Two queries, three when some
     * are new.
     *
     * @param array<int|string, array{links: list<string>, images: list<string>}> $found
     *
     * @return Collection<int, Link>
     */
    private function links(array $found): Collection
    {
        $kinds = [];

        foreach ($found as $urls) {
            $count = 0;

            foreach (['images' => 'image', 'links' => 'link'] as $key => $kind) {
                foreach ($urls[$key] as $url) {
                    if (++$count > self::MAX_PER_POST) {
                        break 2;
                    }

                    // An image anywhere makes it an image, so it gets mirrored.
                    $kinds[$url] = ($kinds[$url] ?? $kind) === 'image' ? 'image' : $kind;
                }
            }
        }

        if ($kinds === []) {
            return new Collection();
        }

        $hashes = array_map(fn ($url) => Link::hash((string) $url), array_keys($kinds));
        $existing = $this->find($hashes);
        $known = [];
        $nowImages = [];

        foreach ($existing as $link) {
            $known[$link->url] = true;

            if ($link->replacement !== null) {
                $known[$link->replacement] = true;
            }

            if ($link->kind === 'link' && ($kinds[$link->url] ?? null) === 'image' && $link->action === null) {
                $nowImages[] = $link->id;
                $link->kind = 'image';
            }
        }

        if ($nowImages !== []) {
            Link::query()->whereIn('id', $nowImages)->update(['kind' => 'image']);
        }

        $new = array_diff_key($kinds, $known);

        if ($new === []) {
            return $existing;
        }

        $now = Carbon::now();
        $rows = [];

        foreach ($new as $url => $kind) {
            $rows[] = $this->newRow((string) $url, $kind, $now);
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $this->db->table('salvage_links')->insertOrIgnore($chunk);
        }

        return $existing->merge($this->find(array_column($rows, 'url_hash')))->unique('id')->values();
    }

    /**
     * @param list<string> $hashes
     *
     * @return Collection<int, Link>
     */
    private function find(array $hashes): Collection
    {
        return Link::query()
            ->whereIn('url_hash', $hashes)
            ->orWhereIn('replacement_hash', $hashes)
            ->get(['id', 'url', 'kind', 'action', 'replacement', 'prior']);
    }

    /**
     * @return array<string, mixed>
     */
    private function newRow(string $url, string $kind, Carbon $now): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $skip = $this->skipReason($url, $host);

        return [
            'url' => $url,
            'url_hash' => Link::hash($url),
            'host' => substr($host, 0, 255),
            'kind' => $kind,
            'status' => $skip === null ? 'pending' : 'skipped',
            'error' => $skip,
            'next_check_at' => $skip === null ? $now : null,
            'created_at' => $now,
        ];
    }

    /**
     * Why a URL is never fetched, or null when it may be.
     */
    public function skipReason(string $url, string $host): ?string
    {
        $host = rtrim($host, '.');
        $this->ownHost ??= strtolower((string) parse_url((string) $this->config->url(), PHP_URL_HOST));

        if ($host === $this->ownHost) {
            return 'own_site';
        }

        foreach ($this->settings->skipDomains() as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return 'skip_list';
            }
        }

        try {
            UrlGuard::check($url);
        } catch (Refused $e) {
            return $e->reason;
        }

        return null;
    }
}
