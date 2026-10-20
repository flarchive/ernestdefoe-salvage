<?php

namespace Ernestdefoe\Salvage\Tests\integration;

use Carbon\Carbon;
use Ernestdefoe\Salvage\Link;
use Ernestdefoe\Salvage\Settings;
use Ernestdefoe\Salvage\Work\Archive;
use Ernestdefoe\Salvage\Work\Mirror;
use Ernestdefoe\Salvage\Work\Runner;
use Illuminate\Contracts\Filesystem\Factory;
use PHPUnit\Framework\Attributes\Test;

class WorkTest extends SalvageTestCase
{
    private function disk()
    {
        return $this->app()->getContainer()->make(Factory::class)->disk(Mirror::DISK);
    }

    private function post(string $content): int
    {
        $response = $this->reply($content);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) $this->json($response)['data']['id'];
    }

    #[Test]
    public function mirrors_an_image_reencoded_and_rewrites_the_posts(): void
    {
        $this->setting(Settings::USE_FOF_UPLOAD, false);
        $this->boot();

        // A PNG with HTML and a script appended: a polyglot.
        $payload = self::png().'<html><script>alert(document.cookie)</script></html>';
        $this->http->respond('https://img.example/cat.png', 200, ['content-type' => 'image/png'], $payload);

        $first = $this->post('Look ![a cat](https://img.example/cat.png) **nice**');
        $second = $this->post('Same cat: [img]https://img.example/cat.png[/img]');

        $this->run_();

        $link = $this->link('https://img.example/cat.png');
        $this->assertSame('mirror', $link->action);
        $this->assertFalse($link->apply_pending);
        $this->assertNull($link->next_check_at, 'A mirrored image is not checked again.');
        $this->assertStringStartsWith('http://localhost/assets/salvage/', $link->replacement);

        $stored = $this->disk()->get($link->mirror_path);
        $this->assertNotSame($payload, $stored);
        $this->assertStringNotContainsString('<script', $stored, 'Re-encoding drops what is not pixels.');
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($stored)[2]);
        $this->assertStringEndsWith('.png', $link->mirror_path);

        foreach ([$first, $second] as $postId) {
            $xml = $this->content($postId);
            $this->assertStringContainsString('src="'.$link->replacement.'"', $xml);
            $this->assertStringNotContainsString('src="https://img.example/cat.png"', $xml);
        }

        $this->assertStringContainsString('<STRONG>', $this->content($first));
        // Only the image was fetched, once, pinned to its address.
        $this->assertSame([['GET', 'https://img.example/cat.png', 'img.example', 443, '93.184.216.34', 5120 * 1024]], $this->http->sent);
    }

    #[Test]
    public function stops_mirroring_for_the_day_at_the_daily_cap(): void
    {
        $this->setting(Settings::USE_FOF_UPLOAD, false);
        $this->setting(Settings::DAILY_MB, 1);
        $this->boot();
        $this->app()->getContainer()->make('cache.store')->put('ernestdefoe-salvage.mirrored.'.gmdate('Ymd'), 1024 * 1024, 60);
        $this->http->respond('https://img.example/late.png', 200, [], self::png());

        $this->post('![](https://img.example/late.png)');
        $this->run_();

        $this->assertSame([], $this->http->sent, 'Not fetched, not checked either.');
        $link = $this->link('https://img.example/late.png');
        $this->assertNull($link->action);
        $this->assertTrue($link->next_check_at->lessThanOrEqualTo(Carbon::now()), 'Still due, for tomorrow.');

        $this->app()->getContainer()->make('cache.store')->forget('ernestdefoe-salvage.mirrored.'.gmdate('Ymd'));
        $this->run_();
        $this->assertSame('mirror', $this->link('https://img.example/late.png')->action);
        $this->assertGreaterThan(0, $this->app()->getContainer()->make('cache.store')->get('ernestdefoe-salvage.mirrored.'.gmdate('Ymd')));
    }

    #[Test]
    public function refuses_to_mirror_what_is_not_a_safe_image(): void
    {
        $this->setting(Settings::USE_FOF_UPLOAD, false);
        $this->setting(Settings::MAX_IMAGE_KB, 50);
        $this->boot();

        // Real dimensions in the header, a decompression bomb: 30000 x 30000.
        $bomb = self::png(1, 1);
        $bomb = substr_replace($bomb, pack('NN', 30000, 30000), 16, 8);

        $this->http->respond('https://img.example/vector.svg', 200, ['content-type' => 'image/svg+xml'], '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
        $this->http->respond('https://img.example/fake.png', 200, ['content-type' => 'image/png'], '<html><body>not an image</body></html>');
        $this->http->respond('https://img.example/huge.png', 200, ['content-type' => 'image/png'], str_repeat('x', 60 * 1024));
        $this->http->respond('https://img.example/bomb.png', 200, ['content-type' => 'image/png'], $bomb);

        // The test forum's public folder outlives each test (and its place differs on CI).
        $before = $this->disk()->allFiles();
        $this->post('![](https://img.example/vector.svg) ![](https://img.example/fake.png) ![](https://img.example/huge.png) ![](https://img.example/bomb.png)');
        // Four images on one host: three this round, the last one next round.
        $this->run_();
        $this->assertSame(3, count($this->http->sent));
        $this->run_();

        foreach (['vector.svg' => 'not_image', 'fake.png' => 'not_image', 'huge.png' => 'too_large', 'bomb.png' => 'too_large'] as $file => $error) {
            $link = $this->link("https://img.example/$file");
            $this->assertNull($link->action, $file);
            $this->assertSame($error, $link->error, $file);
            $this->assertSame(255, $link->mirror_tries, "$file: never tried again");
            $this->assertNotNull($link->next_check_at, "$file: still checked as a link");
        }

        $this->assertSame($before, $this->disk()->allFiles(), 'Nothing was stored.');
    }

    #[Test]
    public function calls_a_link_broken_only_after_failing_twice_a_day_apart(): void
    {
        $this->boot();
        $this->http->respond('https://dead.example/gone', 404);
        $this->post('[gone](https://dead.example/gone)');

        $this->run_();
        $link = $this->link('https://dead.example/gone');
        $this->assertSame('pending', $link->status);
        $this->assertSame(1, $link->fail_count);
        $this->assertSame('http_404', $link->error);
        $this->assertTrue($link->next_check_at->greaterThan(Carbon::now()->addHours(23)));
        // HEAD failed, so GET had the last word.
        $this->assertSame(['HEAD', 'GET'], array_column($this->http->sent, 0));

        $this->run_();
        $this->assertSame(1, $this->link('https://dead.example/gone')->fail_count, 'Not due yet: nothing is fetched.');

        $this->makeDue();
        $this->run_();
        $link = $this->link('https://dead.example/gone');
        $this->assertSame('broken', $link->status);
        $this->assertNotNull($link->broken_at);
    }

    #[Test]
    public function network_trouble_backs_off_and_needs_four_failures(): void
    {
        $this->boot();
        $this->http->respond('https://dead.example/slow', 'timeout');
        $this->post('[slow](https://dead.example/slow)');

        $hours = [];
        for ($i = 1; $i <= 4; $i++) {
            $this->makeDue();
            $this->run_();
            $link = $this->link('https://dead.example/slow');
            $hours[] = (int) round(Carbon::now()->diffInMinutes($link->next_check_at) / 60);
        }

        $this->assertSame('broken', $link->status);
        $this->assertSame([1, 6, 24, 168], $hours);
    }

    #[Test]
    public function bot_walls_and_head_quirks_are_not_broken(): void
    {
        $this->boot();
        $this->http->respond('https://alive.example/walled', 403);
        $this->http->respond('https://alive.example/linkedin', 999);
        // HEAD refused, GET fine.
        $this->http->respond('https://alive.example/nohead', 405);
        $this->http->respond('https://alive.example/nohead', 200);
        $this->post('[a](https://alive.example/walled) [b](https://alive.example/linkedin) [c](https://alive.example/nohead)');
        $this->run_();

        $this->assertSame('ok', $this->link('https://alive.example/nohead')->status);
        $this->assertSame(200, $this->link('https://alive.example/nohead')->http_status);

        $this->assertSame('blocked', $this->link('https://alive.example/walled')->status);
        $this->assertSame('blocked', $this->link('https://alive.example/linkedin')->status);
        $this->assertSame(0, $this->link('https://alive.example/walled')->fail_count);
    }

    #[Test]
    public function an_image_the_source_refuses_to_serve_is_missing_for_readers_too(): void
    {
        $this->setting(Settings::MIRROR_IMAGES, false);
        $this->boot();
        $this->http->respond('https://img.example/hotlink.png', 403);
        $this->post('![](https://img.example/hotlink.png) [walled page](https://alive.example/walled)');
        $this->http->respond('https://alive.example/walled', 403);

        $this->run_();
        $this->assertSame('http_403', $this->link('https://img.example/hotlink.png')->error);
        $this->assertSame(1, $this->link('https://img.example/hotlink.png')->fail_count);
        $this->assertSame('blocked', $this->link('https://alive.example/walled')->status, 'A link behind a wall is still fine.');

        $this->makeDue();
        $this->run_();
        $this->assertSame('broken', $this->link('https://img.example/hotlink.png')->status);
    }

    #[Test]
    public function refuses_private_targets_and_marks_them_skipped(): void
    {
        $this->boot();
        $this->post('[a](https://internal.example/admin) [b](http://10.0.0.1/) [c](http://169.254.169.254/latest/meta-data/)');
        $this->run_();

        foreach (['https://internal.example/admin', 'http://10.0.0.1/', 'http://169.254.169.254/latest/meta-data/'] as $url) {
            $link = $this->link($url);
            $this->assertSame('skipped', $link->status, $url);
            $this->assertSame('private_address', $link->error, $url);
            $this->assertNull($link->next_check_at, $url);
        }

        $this->assertSame([], $this->http->sent);
    }

    #[Test]
    public function at_most_three_requests_per_host_and_the_round_budget(): void
    {
        $this->setting(Settings::PER_RUN, 5);
        $this->setting(Settings::MIRROR_IMAGES, false);
        $this->boot();

        $this->post(implode(' ', array_map(fn ($i) => "[x](https://alive.example/$i) [y](https://dead.example/$i)", range(1, 6))));
        foreach (range(1, 6) as $i) {
            $this->http->respond("https://alive.example/$i", 200);
            $this->http->respond("https://dead.example/$i", 200);
        }

        $this->run_();

        $hosts = array_count_values(array_column($this->http->sent, 2));
        $this->assertLessThanOrEqual(3, $hosts['alive.example'] ?? 0);
        $this->assertLessThanOrEqual(3, $hosts['dead.example'] ?? 0);
        $this->assertCount(5, $this->http->sent, 'The round stops at its budget.');
    }

    #[Test]
    public function a_host_that_times_out_is_left_alone_for_the_rest_of_the_round(): void
    {
        $this->boot();
        foreach (range(1, 3) as $i) {
            $this->http->respond("https://dead.example/$i", 'timeout');
        }

        $this->post('[1](https://dead.example/1) [2](https://dead.example/2) [3](https://dead.example/3)');
        $this->run_();

        $this->assertCount(1, $this->http->sent);
    }

    #[Test]
    public function finds_an_archive_copy_points_the_link_there_and_flags_the_posts(): void
    {
        $this->setting(Settings::AUTO_ARCHIVE, true);
        $this->setting(Settings::FLAG_POSTS, true);
        $this->boot();

        $url = 'https://dead.example/article';
        $snapshot = 'http://web.archive.org/web/20200101000000/https://dead.example/article';
        $this->http->respond($url, 410);
        $this->http->respond(Archive::API.rawurlencode($url), 200, [], json_encode(['archived_snapshots' => ['closest' => ['available' => true, 'url' => $snapshot, 'status' => '200']]]));

        $postId = $this->post("Read [this]($url).");
        $this->run_();
        $this->makeDue();
        $this->run_();

        $link = $this->link($url);
        $this->assertSame('broken', $link->status);
        $this->assertSame('https://web.archive.org/web/20200101000000/https://dead.example/article', $link->archive_url, 'Stored as https.');
        $this->assertSame('replace', $link->action);

        // Rewritten on the next round (the applier runs first in a round).
        $this->run_();
        $this->assertStringContainsString('url="https://web.archive.org/web/20200101000000/https://dead.example/article"', $this->content($postId));

        // The flag went up when it broke and came down once it was fixed.
        $this->assertSame(0, $this->database()->table('flags')->where('type', 'salvage')->count());
    }

    #[Test]
    public function flags_posts_with_newly_broken_links_once(): void
    {
        $this->setting(Settings::FLAG_POSTS, true);
        $this->setting(Settings::ARCHIVE, false);
        $this->boot();
        $this->http->respond('https://dead.example/x', 404);
        $postId = $this->post('[x](https://dead.example/x)');

        $this->run_();
        $this->makeDue();
        $this->run_();

        $flag = $this->database()->table('flags')->where('type', 'salvage')->first();
        $this->assertSame($postId, (int) $flag->post_id);
        $this->assertNull($flag->user_id);
        $this->assertSame('https://dead.example/x', $flag->reason_detail);

        // Still broken a week later: not flagged again.
        $this->makeDue();
        $this->run_();
        $this->assertSame(1, $this->database()->table('flags')->where('type', 'salvage')->count());
    }

    #[Test]
    public function archive_org_saying_slow_down_pauses_the_lookups(): void
    {
        $this->boot();
        foreach (['a', 'b', 'c'] as $path) {
            $this->http->respond("https://dead.example/$path", 404);
            $this->http->respond(Archive::API.rawurlencode("https://dead.example/$path"), 429, ['retry-after' => '120']);
        }
        $this->post('[a](https://dead.example/a) [b](https://dead.example/b) [c](https://dead.example/c)');

        $this->run_();
        $this->makeDue();
        $this->run_();

        $asked = array_filter($this->http->sent, fn ($r) => $r[2] === 'archive.org');
        $this->assertCount(1, $asked, 'One 429 and archive.org is left alone.');
        $this->assertTrue($this->app()->getContainer()->make('cache.store')->has(Archive::PAUSE));
        $this->assertNull($this->link('https://dead.example/a')->archive_checked_at, 'Asked again after the pause.');
    }

    #[Test]
    public function archive_answers_are_validated(): void
    {
        $this->assertNull(Archive::snapshotUrl('javascript:alert(1)//web.archive.org/web/1/https://x.example/'));
        $this->assertNull(Archive::snapshotUrl('https://evil.example/web/2020/https://x.example/'));
        $this->assertNull(Archive::snapshotUrl('https://web.archive.org.evil.example/web/2020/https://x.example/'));
        $this->assertNull(Archive::snapshotUrl('https://web.archive.org/web/2020/https://x.example/"onmouseover="alert(1)'));
        $this->assertSame('https://web.archive.org/web/2020/https://x.example/', Archive::snapshotUrl('http://web.archive.org/web/2020/https://x.example/'));
    }

    #[Test]
    public function scans_existing_posts_in_the_background(): void
    {
        $this->prepareDatabase([
            'posts' => array_map(fn ($i) => [
                'id' => 10 + $i, 'discussion_id' => 1, 'number' => 1 + $i, 'created_at' => Carbon::now()->toDateTimeString(), 'user_id' => 2, 'type' => 'comment',
                'content' => '<r><p><URL url="https://alive.example/old'.$i.'">old</URL></p></r>',
            ], range(1, 3)),
        ]);
        $this->setting(Settings::CHECK_LINKS, false);
        $this->setting(Settings::MIRROR_IMAGES, false);
        $this->boot();

        $this->assertNull($this->run_()['scanned'] ?: null, 'No scan until one is started.');

        $response = $this->send($this->request('POST', '/api/salvage/scan', ['authenticatedAs' => 1, 'json' => ['start' => true]]));
        $this->assertSame(204, $response->getStatusCode());

        $this->assertSame(3, $this->run_()['scanned']);
        $this->assertSame(3, Link::query()->count());
        $this->assertSame(1, $this->database()->table('salvage_link_post')->where('post_id', 12)->count());
        $this->assertSame('', $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class)->get(Settings::SCAN_CURSOR));
    }

    #[Test]
    public function forgets_links_no_post_holds_any_more(): void
    {
        $this->setting(Settings::CHECK_LINKS, false);
        $this->setting(Settings::MIRROR_IMAGES, false);
        $this->boot();
        $this->post('[x](https://alive.example/kept)');
        $postId = $this->post('[y](https://alive.example/dropped)');
        $this->edit($postId, 'gone now');

        $this->run_();
        $this->assertNotNull($this->link('https://alive.example/dropped'), 'Kept for a day.');

        Link::query()->update(['created_at' => Carbon::now()->subDays(2)]);
        $this->assertSame(1, $this->run_()['removed']);
        $this->assertNull($this->link('https://alive.example/dropped'));
        $this->assertNotNull($this->link('https://alive.example/kept'));
    }

    #[Test]
    public function one_round_at_a_time(): void
    {
        $this->boot();
        $store = $this->app()->getContainer()->make('cache.store')->getStore();
        $lock = $store->lock(Runner::LOCK, 60);
        $this->assertTrue($lock->get());

        $this->assertNull($this->run_());

        $lock->release();
        $this->assertIsArray($this->run_());
    }
}
