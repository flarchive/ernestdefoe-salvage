<?php

use Ernestdefoe\Salvage\Api;
use Ernestdefoe\Salvage\Console\WorkCommand;
use Ernestdefoe\Salvage\Listener\TrackPostLinks;
use Ernestdefoe\Salvage\Provider;
use Ernestdefoe\Salvage\Settings;
use Ernestdefoe\Salvage\Work\Mirror;
use Flarum\Extend;
use Flarum\Foundation\Paths;
use Flarum\Http\UrlGenerator;
use Flarum\Post\CommentPost;
use Illuminate\Console\Scheduling\Event;

$settings = new Extend\Settings();

foreach (Settings::DEFAULTS as $key => $value) {
    $settings->default($key, $value);
}

return [
    // Only for flarum/flags: how a "broken link" flag reads. Without Flags,
    // the forum loads nothing of Salvage's.
    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-flags', fn () => [
            (new Extend\Frontend('forum'))
                ->js(__DIR__.'/js/dist/forum.js'),
        ]),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    $settings,

    (new Extend\ServiceProvider())
        ->register(Provider::class),

    // Eloquent's own events, so posts saved by any code path are covered,
    // not only those saved through the API. Resolved only when a post saves.
    (new Extend\Event())
        ->listen('eloquent.saving: '.CommentPost::class, [TrackPostLinks::class, 'saving'])
        ->listen('eloquent.saved: '.CommentPost::class, [TrackPostLinks::class, 'saved']),

    // Mirrored images when FoF Upload is not used. A Flarum disk, so a forum
    // can point it at S3 or anywhere else like its other disks.
    (new Extend\Filesystem())
        ->disk(Mirror::DISK, fn (Paths $paths, UrlGenerator $url) => [
            'root' => "$paths->public/assets/salvage",
            'url' => $url->to('forum')->path('assets/salvage'),
            'visibility' => 'public',
        ]),

    (new Extend\Routes('api'))
        ->get('/salvage/overview', 'salvage.overview', Api\OverviewController::class)
        ->get('/salvage/links', 'salvage.links', Api\LinksController::class)
        ->get('/salvage/links/{id:\d+}/posts', 'salvage.links.posts', Api\LinkPostsController::class)
        ->post('/salvage/links/{id:\d+}', 'salvage.links.decide', Api\DecideController::class)
        ->post('/salvage/scan', 'salvage.scan', Api\ScanController::class),

    (new Extend\Console())
        ->command(WorkCommand::class)
        ->schedule(WorkCommand::class, function (Event $event): void {
            $event->everyFiveMinutes();
        }),
];
