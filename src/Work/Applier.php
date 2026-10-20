<?php

namespace Ernestdefoe\Salvage\Work;

use Carbon\Carbon;
use Ernestdefoe\Salvage\Link;
use Ernestdefoe\Salvage\Tracker;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Writes a decision into the posts that hold the link, 100 posts at a time.
 *
 * The stored XML is edited in place (Xml::rewrite) and saved with a plain
 * UPDATE: no re-parse, no edit stamp, no model events. Running it twice is
 * harmless: a post that already carries the decision is left alone, so an
 * interrupted run simply carries on next time.
 */
class Applier
{
    public const CHUNK = 100;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Factory $filesystem,
    ) {
    }

    /**
     * @return bool whether every post was done before the deadline
     */
    public function apply(Link $link, float $deadline): bool
    {
        $after = 0;

        do {
            if (microtime(true) > $deadline) {
                return false;
            }

            $posts = $this->db->table('salvage_link_post')
                ->join('posts', 'posts.id', '=', 'salvage_link_post.post_id')
                ->where('salvage_link_post.link_id', $link->id)
                ->where('salvage_link_post.post_id', '>', $after)
                ->orderBy('salvage_link_post.post_id')
                ->limit(self::CHUNK)
                ->get(['posts.id', 'posts.content']);

            foreach ($posts as $post) {
                $after = (int) $post->id;
                $xml = (string) $post->content;
                $new = $this->rewrite($link, $xml);

                // Only if nobody edited the post since it was read: an edit
                // goes through the save listener, which applies this decision itself.
                if ($new !== $xml) {
                    $this->db->table('posts')->where('id', $post->id)->where('content', $post->content)->update(['content' => $new]);
                }
            }
        } while ($posts->count() === self::CHUNK);

        $this->finish($link);

        return true;
    }

    public function rewrite(Link $link, string $xml): string
    {
        return Tracker::applyTo($xml, new Collection([$link]));
    }

    private function finish(Link $link): void
    {
        $link->apply_pending = false;
        $link->prior = null;

        if ($link->action === 'restore') {
            $path = $link->mirror_path;

            $link->action = null;
            $link->replacement = null;
            $link->replacement_hash = null;
            $link->prior = null;
            $link->mirror_path = null;
            // Mirroring is not tried again by itself after it was undone.
            $link->mirror_tries = 255;
            $link->status = 'pending';
            $link->fail_count = 0;
            $link->next_check_at = Carbon::now();

            if ($path !== null && ! Link::query()->where('mirror_path', $path)->whereKeyNot($link->id)->exists()) {
                $this->filesystem->disk(Mirror::DISK)->delete($path);
            }
        }

        $link->save();
    }
}
