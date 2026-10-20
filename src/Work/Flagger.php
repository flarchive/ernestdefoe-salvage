<?php

namespace Ernestdefoe\Salvage\Work;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Illuminate\Database\ConnectionInterface;

/**
 * Optional: puts posts with a newly broken link in the moderators' flag
 * queue (flarum/flags), one flag per post naming its broken links. Hidden
 * posts are left out, a post is never flagged twice, and a round flags at
 * most a hundred posts, so one dead site cannot bury the queue.
 */
class Flagger
{
    public const TYPE = 'salvage';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ExtensionManager $extensions,
    ) {
    }

    /**
     * @param list<int> $linkIds
     */
    public function flag(array $linkIds, int $cap): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $rows = $this->db->table('salvage_link_post')
            ->join('posts', 'posts.id', '=', 'salvage_link_post.post_id')
            ->join('salvage_links', 'salvage_links.id', '=', 'salvage_link_post.link_id')
            ->whereIn('salvage_link_post.link_id', $linkIds)
            // Already dealt with (an automatic archive.org copy) in this round.
            ->whereNull('salvage_links.action')
            ->where('posts.type', 'comment')
            ->whereNull('posts.hidden_at')
            ->whereNotExists(fn ($q) => $q->from('flags')->whereColumn('flags.post_id', 'posts.id')->where('flags.type', self::TYPE))
            ->orderBy('posts.id')
            ->limit($cap * 5)
            ->get(['posts.id as post_id', 'salvage_links.url']);

        $urls = [];

        foreach ($rows as $row) {
            if (count($urls) < $cap || isset($urls[$row->post_id])) {
                $urls[$row->post_id][] = $row->url;
            }
        }

        $now = Carbon::now();
        $flags = [];

        foreach ($urls as $postId => $list) {
            $flags[] = [
                'type' => self::TYPE,
                'post_id' => $postId,
                'user_id' => null,
                'reason' => null,
                'reason_detail' => mb_substr(implode("\n", array_unique($list)), 0, 2000),
                'created_at' => $now,
            ];
        }

        if ($flags !== []) {
            $this->db->table('flags')->insert($flags);
        }

        return count($flags);
    }

    /**
     * Once staff have dealt with a link, its flags have done their job,
     * unless the post still holds another broken link nobody decided on.
     */
    public function clear(int $linkId): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->db->table('flags')
            ->where('type', self::TYPE)
            ->whereIn('post_id', fn ($q) => $q->from('salvage_link_post')->select('post_id')->where('link_id', $linkId))
            ->whereNotExists(fn ($q) => $q->from('salvage_link_post as other')
                ->join('salvage_links', 'salvage_links.id', '=', 'other.link_id')
                ->whereColumn('other.post_id', 'flags.post_id')
                ->where('other.link_id', '!=', $linkId)
                ->where('salvage_links.status', 'broken')
                ->whereNull('salvage_links.action'))
            ->delete();
    }

    private function enabled(): bool
    {
        return $this->extensions->isEnabled('flarum-flags');
    }
}
