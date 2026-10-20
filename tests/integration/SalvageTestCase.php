<?php

namespace Ernestdefoe\Salvage\Tests\integration;

use Carbon\Carbon;
use Ernestdefoe\Salvage\Link;
use Ernestdefoe\Salvage\Net\Resolver;
use Ernestdefoe\Salvage\Net\Transport;
use Ernestdefoe\Salvage\Tests\integration\Fakes\FakeResolver;
use Ernestdefoe\Salvage\Tests\integration\Fakes\FakeTransport;
use Ernestdefoe\Salvage\Work\Runner;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Psr\Http\Message\ResponseInterface;

abstract class SalvageTestCase extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected FakeResolver $dns;
    protected FakeTransport $http;

    protected function setUp(): void
    {
        parent::setUp();

        // The test forum's public folder outlives each test.
        $mirrors = (getenv('FLARUM_TEST_TMP_DIR_LOCAL') ?: getenv('FLARUM_TEST_TMP_DIR') ?: dirname(__DIR__, 2).'/vendor/flarum/testing/src/integration/tmp').'/public/assets/salvage';
        if (is_dir($mirrors)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($mirrors, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        $this->extension('flarum-markdown', 'flarum-bbcode', 'flarum-tags', 'flarum-mentions', 'flarum-flags', 'ernestdefoe-salvage');

        // An hour ago, or the first reply by its author trips the flood control.
        $now = Carbon::now()->subHour()->toDateTimeString();

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Discussion::class => [
                ['id' => 1, 'title' => 'Links galore', 'created_at' => $now, 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'created_at' => $now, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>First post</p></t>', 'number' => 1],
            ],
        ]);

        $this->dns = new FakeResolver([
            'img.example' => ['93.184.216.34'],
            'dead.example' => ['93.184.216.35'],
            'alive.example' => ['93.184.216.36'],
            'archive.org' => ['207.241.224.2'],
            'internal.example' => ['10.0.0.7'],
            'gone.example' => [],
        ]);
        $this->http = new FakeTransport();
    }

    /** Boots the app and swaps the network for the fakes. */
    protected function boot(): void
    {
        $container = $this->app()->getContainer();
        $container->instance(Resolver::class, $this->dns);
        $container->instance(Transport::class, $this->http);
    }

    protected function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    protected function reply(string $content, int $as = 1): ResponseInterface
    {
        return $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => $as,
            'json' => ['data' => [
                'type' => 'posts',
                'attributes' => ['content' => $content],
                'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]],
            ]],
        ]));
    }

    protected function edit(int $postId, string $content, int $as = 1): ResponseInterface
    {
        return $this->send($this->request('PATCH', "/api/posts/$postId", [
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'posts', 'id' => (string) $postId, 'attributes' => ['content' => $content]]],
        ]));
    }

    protected function run_(): ?array
    {
        return $this->app()->getContainer()->make(Runner::class)->run();
    }

    protected function link(string $url): ?Link
    {
        return Link::query()->where('url_hash', Link::hash($url))->first();
    }

    protected function content(int $postId): string
    {
        return (string) $this->database()->table('posts')->where('id', $postId)->value('content');
    }

    /** Makes every link due now, as if the backoff had passed. */
    protected function makeDue(): void
    {
        Link::query()->whereNotNull('next_check_at')->update(['next_check_at' => Carbon::now()->subMinute()]);
    }

    protected static function png(int $width = 8, int $height = 8): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
