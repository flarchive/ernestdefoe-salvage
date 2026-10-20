<?php

namespace Ernestdefoe\Salvage\Listener;

use Ernestdefoe\Salvage\Tracker;
use Flarum\Post\CommentPost;
use Psr\Log\LoggerInterface;
use Throwable;
use WeakMap;

/**
 * When a post is written or edited: re-applies decisions already made about
 * its URLs (a mirrored image, a replaced or unlinked dead link) to the new
 * XML before it is saved, then records which URLs the post holds.
 *
 * Database only, a few queries and only for posts that contain links or
 * images. Never fatal: if anything here fails, the post saves as written.
 */
class TrackPostLinks
{
    /**
     * What saving() found, for saved(). Static: the event dispatcher builds
     * a fresh listener for each event. Weak: a post that is never saved
     * after all leaves nothing behind.
     *
     * @var WeakMap<CommentPost, list<int>>|null
     */
    private static ?WeakMap $pending = null;

    public function __construct(private readonly Tracker $tracker, private readonly LoggerInterface $log)
    {
        self::$pending ??= new WeakMap();
    }

    public function saving(CommentPost $post): void
    {
        if (! $post->isDirty('content')) {
            return;
        }

        try {
            $xml = $post->getAttributes()['content'] ?? null;

            if (! is_string($xml) || ! str_starts_with($xml, '<r')) {
                // Plain text holds no links; an edit may have removed them all.
                self::$pending[$post] = [];

                return;
            }

            $result = $this->tracker->prepare($xml);

            if ($result['xml'] !== $xml) {
                $post->setParsedContentAttribute($result['xml']);
            }

            self::$pending[$post] = $result['ids'];
        } catch (Throwable $e) {
            $this->log->error('[salvage] '.$e->getMessage());
        }
    }

    public function saved(CommentPost $post): void
    {
        if (! isset(self::$pending[$post])) {
            return;
        }

        $ids = self::$pending[$post];
        unset(self::$pending[$post]);

        if ($ids === [] && $post->wasRecentlyCreated) {
            return;
        }

        try {
            $this->tracker->sync($post->id, $ids);
        } catch (Throwable $e) {
            $this->log->error('[salvage] '.$e->getMessage());
        }
    }
}
