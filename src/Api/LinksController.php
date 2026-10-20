<?php

namespace Ernestdefoe\Salvage\Api;

use Ernestdefoe\Salvage\Link;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/salvage/links?show=broken&kind=image&q=example.com&page=2
 *
 * Admins only. Twenty links a page, each with how many posts hold it and the
 * first of them, in three queries whatever the page holds.
 */
class LinksController implements RequestHandlerInterface
{
    public const PER_PAGE = 20;

    public const VIEWS = ['broken', 'failing', 'decided', 'mirrored', 'ignored', 'skipped', 'all'];

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $params = $request->getQueryParams();
        $view = in_array($params['show'] ?? null, self::VIEWS, true) ? $params['show'] : 'broken';
        $page = max(1, min(10000, (int) ($params['page'] ?? 1)));
        $query = Link::query();

        self::view($query, $view);

        if (in_array($params['kind'] ?? null, Link::KINDS, true)) {
            $query->where('kind', $params['kind']);
        }

        $host = strtolower(trim((string) Arr::get($params, 'q', '')));

        if ($host !== '') {
            // A host filter: that host or its subdomains. Host characters only,
            // so nothing typed here can act as a LIKE wildcard.
            preg_match('/^[a-z0-9.-]{1,253}$/', $host)
                ? $query->where(fn (Builder $q) => $q->where('host', $host)->orWhere('host', 'like', '%.'.$host))
                : $query->whereRaw('1 = 0');
        }

        $total = (clone $query)->count();
        $links = $query->orderByDesc($view === 'broken' ? 'broken_at' : 'id')->orderByDesc('id')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();

        $usage = $links->isEmpty() ? collect() : $this->db->table('salvage_link_post')
            ->whereIn('link_id', $links->pluck('id'))
            ->groupBy('link_id')
            ->selectRaw('link_id, count(*) as posts, min(post_id) as first_post')
            ->get()->keyBy('link_id');

        $firsts = $usage->isEmpty() ? collect() : Posts::describe($this->db, $usage->pluck('first_post')->all());

        return new JsonResponse([
            'total' => $total,
            'perPage' => self::PER_PAGE,
            'links' => $links->map(fn (Link $link) => self::serialize(
                $link,
                (int) ($usage[$link->id]->posts ?? 0),
                $firsts[(int) ($usage[$link->id]->first_post ?? 0)] ?? null
            ))->all(),
        ]);
    }

    /**
     * @param Builder<Link> $query
     */
    public static function view(Builder $query, string $view): void
    {
        match ($view) {
            'broken' => $query->where('status', 'broken')->whereNull('action'),
            'failing' => $query->where('fail_count', '>', 0)->where('status', '!=', 'broken')->whereNull('action'),
            'decided' => $query->whereIn('action', ['replace', 'unlink', 'restore']),
            'mirrored' => $query->where('action', 'mirror'),
            'ignored' => $query->where('action', 'ignore'),
            'skipped' => $query->where('status', 'skipped'),
            default => null,
        };
    }

    /**
     * @param array<string, mixed>|null $firstPost
     *
     * @return array<string, mixed>
     */
    public static function serialize(Link $link, int $posts, ?array $firstPost): array
    {
        return [
            'id' => $link->id,
            'url' => $link->url,
            'host' => $link->host,
            'kind' => $link->kind,
            'status' => $link->status,
            'httpStatus' => $link->http_status,
            'error' => $link->error,
            'failCount' => $link->fail_count,
            'action' => $link->action,
            'replacement' => $link->replacement,
            'applying' => $link->apply_pending,
            'archiveUrl' => $link->archive_url,
            'archiveChecked' => $link->archive_checked_at !== null,
            'canMirror' => $link->kind === 'image' && $link->action === null,
            'checkedAt' => $link->checked_at?->toIso8601String(),
            'brokenAt' => $link->broken_at?->toIso8601String(),
            'posts' => $posts,
            'firstPost' => $firstPost,
        ];
    }
}
