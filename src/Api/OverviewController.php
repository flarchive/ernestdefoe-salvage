<?php

namespace Ernestdefoe\Salvage\Api;

use Ernestdefoe\Salvage\Link;
use Ernestdefoe\Salvage\Settings;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/salvage/overview: the admin page's numbers and health checks.
 */
class OverviewController implements RequestHandlerInterface
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Settings $settings,
        private readonly Cache $cache,
        private readonly ExtensionManager $extensions,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $counts = [];

        foreach (LinksController::VIEWS as $view) {
            if ($view !== 'all') {
                $query = Link::query();
                LinksController::view($query, $view);
                $counts[$view] = $query->count();
            }
        }

        $counts['all'] = Link::query()->count();
        $counts['pending'] = Link::query()->where('status', 'pending')->count();

        $cursor = $this->settings->scanCursor();
        $lastRun = $this->cache->get('flarum:schedule:last_run');

        return new JsonResponse([
            'counts' => $counts,
            'scan' => $cursor === null ? null : [
                'done' => $this->db->table('posts')->where('type', 'comment')->where('id', '<=', $cursor)->count(),
                'total' => $this->db->table('posts')->where('type', 'comment')->count(),
            ],
            'schedulerLastRun' => $lastRun instanceof \DateTimeInterface ? $lastRun->format(\DateTimeInterface::ATOM) : null,
            'fofUpload' => $this->extensions->isEnabled('fof-upload'),
            'flags' => $this->extensions->isEnabled('flarum-flags'),
        ]);
    }
}
