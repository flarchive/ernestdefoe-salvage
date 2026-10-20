<?php

namespace Ernestdefoe\Salvage;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Post\Post;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One distinct URL found in posts.
 *
 * @property int         $id
 * @property string      $url
 * @property string      $url_hash
 * @property string      $host
 * @property string      $kind
 * @property string      $status
 * @property int|null    $http_status
 * @property string|null $error
 * @property int         $fail_count
 * @property string|null $action
 * @property string|null $replacement
 * @property string|null $replacement_hash
 * @property string|null $prior
 * @property bool        $apply_pending
 * @property int         $mirror_tries
 * @property string|null $mirror_path
 * @property string|null $archive_url
 * @property Carbon|null $archive_checked_at
 * @property Carbon|null $checked_at
 * @property Carbon|null $next_check_at
 * @property Carbon|null $broken_at
 * @property Carbon      $created_at
 * @property int|null    $posts_count
 */
class Link extends AbstractModel
{
    public const KINDS = ['link', 'image'];
    public const STATUSES = ['pending', 'ok', 'broken', 'blocked', 'skipped'];
    public const ACTIONS = ['mirror', 'replace', 'unlink', 'ignore'];

    protected $table = 'salvage_links';

    public $timestamps = true;

    public const UPDATED_AT = null;

    protected $casts = [
        'http_status' => 'integer',
        'fail_count' => 'integer',
        'mirror_tries' => 'integer',
        'apply_pending' => 'boolean',
        'archive_checked_at' => 'datetime',
        'checked_at' => 'datetime',
        'next_check_at' => 'datetime',
        'broken_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public static function hash(string $url): string
    {
        return sha1($url);
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'salvage_link_post');
    }

    public function setReplacement(?string $url): void
    {
        // Posts already rewritten carry the old replacement until the new
        // decision has been applied to them.
        if ($this->replacement !== null && $this->replacement !== $url) {
            $this->prior = $this->replacement;
        }

        $this->replacement = $url;
        $this->replacement_hash = $url === null ? null : self::hash($url);
    }
}
