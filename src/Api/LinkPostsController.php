<?php

namespace Ernestdefoe\Salvage\Api;

use Ernestdefoe\Salvage\Link;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/salvage/links/{id}/posts?page=2: every post holding a link, twenty
 * at a time. Admins only.
 */
class LinkPostsController implements RequestHandlerInterface
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $link = Link::query()->findOrFail((int) Arr::get($request->getQueryParams(), 'id'));
        $page = max(1, min(10000, (int) Arr::get($request->getQueryParams(), 'page', 1)));

        $ids = $this->db->table('salvage_link_post')
            ->where('link_id', $link->id)
            ->orderBy('post_id')
            ->offset(($page - 1) * LinksController::PER_PAGE)
            ->limit(LinksController::PER_PAGE + 1)
            ->pluck('post_id')->all();

        return new JsonResponse([
            'posts' => array_values(Posts::describe($this->db, array_slice($ids, 0, LinksController::PER_PAGE))),
            'more' => count($ids) > LinksController::PER_PAGE,
        ]);
    }
}
