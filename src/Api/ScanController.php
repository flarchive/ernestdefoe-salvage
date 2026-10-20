<?php

namespace Ernestdefoe\Salvage\Api;

use Ernestdefoe\Salvage\Settings;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/salvage/scan {"start": true|false}: starts (from the first post)
 * or stops the scan of existing posts. The scan itself runs in the scheduled
 * rounds, never in this request.
 */
class ScanController implements RequestHandlerInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $this->settings->setScanCursor(Arr::get((array) $request->getParsedBody(), 'start') ? 0 : null);

        return new EmptyResponse(204);
    }
}
