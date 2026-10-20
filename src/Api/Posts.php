<?php

namespace Ernestdefoe\Salvage\Api;

use Illuminate\Database\ConnectionInterface;

final class Posts
{
    /**
     * Where each post is, for links in the admin page: one query.
     *
     * @param list<int|string> $ids
     *
     * @return array<int, array{id: int, number: int, discussionId: int, title: string, hidden: bool}>
     */
    public static function describe(ConnectionInterface $db, array $ids): array
    {
        $rows = $db->table('posts')
            ->join('discussions', 'discussions.id', '=', 'posts.discussion_id')
            ->whereIn('posts.id', $ids)
            ->get(['posts.id', 'posts.number', 'posts.discussion_id', 'posts.hidden_at', 'discussions.title', 'discussions.hidden_at as discussion_hidden_at', 'discussions.is_private']);

        $posts = [];

        foreach ($rows as $row) {
            $posts[(int) $row->id] = [
                'id' => (int) $row->id,
                'number' => (int) $row->number,
                'discussionId' => (int) $row->discussion_id,
                'title' => (string) $row->title,
                'hidden' => $row->hidden_at !== null || $row->discussion_hidden_at !== null || (bool) $row->is_private,
            ];
        }

        return $posts;
    }
}
