<?php

namespace Ernestdefoe\Salvage\Tests\integration\Net;

use Ernestdefoe\Salvage\Net\CurlTransport;
use Ernestdefoe\Salvage\Net\Refused;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The real transport against a local server. "pinned.example" exists in no
 * DNS: a response proves curl went to the pinned address and nowhere else.
 */
class CurlTransportTest extends TestCase
{
    private static $server;
    private static int $port;
    private static string $dir;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir().'/salvage-curl-'.bin2hex(random_bytes(4));
        mkdir(self::$dir);
        file_put_contents(self::$dir.'/router.php', <<<'PHP'
<?php
file_put_contents(__DIR__.'/log', $_SERVER['REQUEST_METHOD'].' '.$_SERVER['HTTP_HOST'].' '.$_SERVER['REQUEST_URI']."\n", FILE_APPEND);
switch (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
    case '/ok': echo 'hello'; break;
    case '/big': echo str_repeat('x', 200000); break;
    case '/redirect': header('Location: http://169.254.169.254/latest/meta-data/', true, 302); break;
    case '/agent': echo $_SERVER['HTTP_USER_AGENT'] ?? ''; break;
    default: http_response_code(404);
}
PHP);

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.self::$port, self::$dir.'/router.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes
        );

        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', self::$port); $i++) {
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$server);
        array_map('unlink', glob(self::$dir.'/*'));
        rmdir(self::$dir);
    }

    private function send(string $path, int $max, string $method = 'GET')
    {
        return (new CurlTransport('SalvageTest/1.0'))->send($method, 'http://pinned.example:'.self::$port.$path, 'pinned.example', self::$port, '127.0.0.1', $max);
    }

    #[Test]
    public function connects_only_to_the_pinned_address(): void
    {
        $response = $this->send('/ok', 100);

        $this->assertSame(200, $response->status);
        $this->assertSame('hello', $response->body);
        $this->assertStringContainsString('GET pinned.example:'.self::$port.' /ok', file_get_contents(self::$dir.'/log'));
    }

    #[Test]
    public function never_follows_redirects_itself(): void
    {
        $response = $this->send('/redirect', 100);

        $this->assertSame(302, $response->status);
        $this->assertSame('http://169.254.169.254/latest/meta-data/', $response->header('location'));
    }

    #[Test]
    public function stops_at_the_byte_cap(): void
    {
        $this->expectExceptionObject(new Refused('too_large'));

        $this->send('/big', 1000);
    }

    #[Test]
    public function stops_quietly_once_the_status_is_known(): void
    {
        $response = $this->send('/big', 0);

        $this->assertSame(200, $response->status);
        $this->assertSame('', $response->body);
        $this->assertSame(404, $this->send('/missing', 0, 'HEAD')->status);
    }

    #[Test]
    public function ignores_proxy_settings_from_the_environment(): void
    {
        putenv('http_proxy=http://127.0.0.1:1');
        putenv('HTTP_PROXY=http://127.0.0.1:1');
        putenv('all_proxy=http://127.0.0.1:1');

        try {
            $this->assertSame('SalvageTest/1.0', $this->send('/agent', 100)->body);
        } finally {
            putenv('http_proxy');
            putenv('HTTP_PROXY');
            putenv('all_proxy');
        }
    }

    #[Test]
    public function reports_a_refused_connection(): void
    {
        $this->expectExceptionObject(new Refused('connect'));

        (new CurlTransport('t'))->send('GET', 'http://pinned.example:1/', 'pinned.example', 1, '127.0.0.1', 10);
    }
}
