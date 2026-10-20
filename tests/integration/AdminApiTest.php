<?php

namespace Ernestdefoe\Salvage\Tests\integration;

use Carbon\Carbon;
use Ernestdefoe\Salvage\Link;
use Flarum\Formatter\Formatter;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

class AdminApiTest extends SalvageTestCase
{
    private function get(string $path, array $query = [], ?int $as = 1): ResponseInterface
    {
        return $this->send($this->request('GET', $path, $as ? ['authenticatedAs' => $as] : [])->withQueryParams($query));
    }

    private function decide(int $id, array $body, ?int $as = 1): ResponseInterface
    {
        return $this->send($this->request('POST', "/api/salvage/links/$id", ($as ? ['authenticatedAs' => $as] : []) + ['json' => $body]));
    }

    /** A broken link in a fresh post, as the checker would leave it. */
    private function brokenLink(string $url = 'https://dead.example/a', ?string $archive = null): array
    {
        $response = $this->reply("Some **text**, a [link]($url) and more text.");
        $postId = (int) $this->json($response)['data']['id'];
        Link::query()->where('url_hash', Link::hash($url))->update([
            'status' => 'broken', 'error' => 'http_404', 'fail_count' => 2, 'broken_at' => Carbon::now(), 'archive_url' => $archive,
            'archive_checked_at' => Carbon::now(),
        ]);

        return [$this->link($url), $postId];
    }

    private function html(int $postId): string
    {
        return $this->app()->getContainer()->make(Formatter::class)->render($this->content($postId));
    }

    #[Test]
    public function only_admins_reach_any_of_it(): void
    {
        [$link] = $this->brokenLink();

        foreach ([null, 2] as $as) {
            $this->assertSame(403, $this->get('/api/salvage/overview', [], $as)->getStatusCode());
            $this->assertSame(403, $this->get('/api/salvage/links', [], $as)->getStatusCode());
            $this->assertSame(403, $this->get("/api/salvage/links/$link->id/posts", [], $as)->getStatusCode());
            // A guest's POST already stops at the CSRF check.
            $this->assertContains($this->decide($link->id, ['action' => 'unlink'], $as)->getStatusCode(), [400, 403]);
            $this->assertContains($this->send($this->request('POST', '/api/salvage/scan', ($as ? ['authenticatedAs' => $as] : []) + ['json' => ['start' => true]]))->getStatusCode(), [400, 403]);
        }

        $this->assertNull($this->link('https://dead.example/a')->action, 'Nothing changed.');
        $this->assertSame('', (string) $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class)->get('ernestdefoe-salvage.scan_cursor'));
    }

    #[Test]
    public function lists_broken_links_with_where_they_are(): void
    {
        [$link, $postId] = $this->brokenLink();
        $this->reply('Again [here](https://dead.example/a)');
        $this->reply('[fine](https://alive.example/ok)');

        $data = $this->json($this->get('/api/salvage/links'));

        $this->assertSame(1, $data['total']);
        $row = $data['links'][0];
        $this->assertSame('https://dead.example/a', $row['url']);
        $this->assertSame(2, $row['posts']);
        $this->assertSame(['id' => $postId, 'number' => 2, 'discussionId' => 1, 'title' => 'Links galore', 'hidden' => false], $row['firstPost']);

        $this->assertSame(1, $this->json($this->get('/api/salvage/links', ['q' => 'alive.example', 'show' => 'all']))['total']);
        $this->assertSame(0, $this->json($this->get('/api/salvage/links', ['q' => '%', 'show' => 'all']))['total'], 'No wildcards from the search box.');
        $this->assertSame(3, $this->json($this->get('/api/salvage/links', ['show' => 'nonsense; drop table']))['total'] + 2, 'An unknown view falls back to broken.');

        $posts = $this->json($this->get("/api/salvage/links/$link->id/posts"));
        $this->assertCount(2, $posts['posts']);
        $this->assertFalse($posts['more']);

        $overview = $this->json($this->get('/api/salvage/overview'));
        $this->assertSame(1, $overview['counts']['broken']);
        $this->assertTrue($overview['flags']);
    }

    #[Test]
    public function the_link_list_costs_the_same_queries_for_one_link_or_twenty(): void
    {
        $count = function (): int {
            $db = $this->database();
            $db->flushQueryLog();
            $db->enableQueryLog();
            $this->get('/api/salvage/links', ['show' => 'all']);
            $n = count($db->getQueryLog());
            $db->disableQueryLog();

            return $n;
        };

        $this->reply('[one](https://alive.example/1)');
        $one = $count();

        $this->reply(implode(' ', array_map(fn ($i) => "[l](https://alive.example/x$i)", range(1, 19))));
        $twenty = $count();

        $this->assertSame($one, $twenty);
    }

    #[Test]
    public function replacing_validates_the_address_and_rewrites_every_post(): void
    {
        [$link, $postId] = $this->brokenLink();
        $second = (int) $this->json($this->reply('Also [b](https://dead.example/a)'))['data']['id'];

        foreach (['javascript:alert(1)', ' https://x.example/', "https://x.example/\n", 'data:text/html,hi', 'https://dead.example/a', '//x.example/', 'https://', 'vbscript:x', ['https://x.example/']] as $bad) {
            $response = $this->decide($link->id, ['action' => 'replace', 'replacement' => $bad]);
            $this->assertSame(422, $response->getStatusCode(), json_encode($bad).substr((string) $response->getBody(), 0, 600));
        }

        $response = $this->decide($link->id, ['action' => 'replace', 'replacement' => 'https://new.example/a']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('replace', $this->json($response)['action']);
        $this->assertFalse($this->json($response)['applying']);

        foreach ([$postId, $second] as $id) {
            $this->assertStringContainsString('href="https://new.example/a"', $this->html($id));
            $this->assertStringNotContainsString('href="https://dead.example/a"', $this->html($id));
        }
        $this->assertStringContainsString('<strong>text</strong>', $this->html($postId));

        // Changed again: posts carrying the first replacement follow.
        $again = $this->decide($link->id, ['action' => 'replace', 'replacement' => 'https://newer.example/a']);
        $this->assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        $this->assertStringContainsString('href="https://newer.example/a"', $this->html($postId));

        // Undone: the original comes back everywhere.
        $undo = $this->decide($link->id, ['action' => 'restore']);
        $this->assertSame(200, $undo->getStatusCode(), substr((string) $undo->getBody(), 0, 700));
        $this->assertStringContainsString('href="https://dead.example/a"', $this->html($postId));
        $this->assertStringContainsString('href="https://dead.example/a"', $this->html($second));
        $restored = $this->link('https://dead.example/a');
        $this->assertNull($restored->action);
        $this->assertNull($restored->replacement);
        $this->assertSame('pending', $restored->status);
    }

    #[Test]
    public function archive_unlink_ignore(): void
    {
        [$link, $postId] = $this->brokenLink('https://dead.example/a', 'https://web.archive.org/web/2020/https://dead.example/a');
        [$other] = $this->brokenLink('https://dead.example/b');

        $this->assertSame(422, $this->decide($other->id, ['action' => 'archive'])->getStatusCode(), 'No archive copy, no archive.');
        $this->assertSame(422, $this->decide($other->id, ['action' => 'explode'])->getStatusCode());

        $this->assertSame(200, $this->decide($link->id, ['action' => 'archive'])->getStatusCode());
        $this->assertStringContainsString('href="https://web.archive.org/web/2020/https://dead.example/a"', $this->html($postId));

        $this->assertSame(422, $this->decide($link->id, ['action' => 'ignore'])->getStatusCode(), 'Undo first.');

        // Unlinking after archiving takes out the archived link too.
        $this->assertSame(200, $this->decide($link->id, ['action' => 'unlink'])->getStatusCode());
        $html = $this->html($postId);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('a link and more text', $html);
        $this->assertSame(422, $this->decide($link->id, ['action' => 'restore'])->getStatusCode(), 'An unlink cannot be undone.');

        $this->assertSame(200, $this->decide($other->id, ['action' => 'ignore'])->getStatusCode());
        $this->assertSame(0, $this->json($this->get('/api/salvage/links'))['total'], 'Neither is reported as broken now.');
        $this->assertSame(1, $this->json($this->get('/api/salvage/links', ['show' => 'ignored']))['total']);

        $this->assertSame(200, $this->decide($other->id, ['action' => 'recheck'])->getStatusCode());
        $this->assertNull($this->link('https://dead.example/b')->action);
        $this->assertNotNull($this->link('https://dead.example/b')->next_check_at);
    }

    #[Test]
    public function fixing_a_link_takes_down_its_flags(): void
    {
        [$link, $postId] = $this->brokenLink();
        $this->database()->table('flags')->insert(['type' => 'salvage', 'post_id' => $postId, 'reason_detail' => 'https://dead.example/a', 'created_at' => Carbon::now()]);

        $this->decide($link->id, ['action' => 'replace', 'replacement' => 'https://new.example/']);

        $this->assertSame(0, $this->database()->table('flags')->count());
    }
}
