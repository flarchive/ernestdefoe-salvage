<?php

namespace Ernestdefoe\Salvage\Tests\integration\Net;

use Ernestdefoe\Salvage\Net\Refused;
use Ernestdefoe\Salvage\Net\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UrlGuardTest extends TestCase
{
    public static function refused(): array
    {
        return [
            ['javascript:alert(1)', 'scheme'],
            ['file:///etc/passwd', 'scheme'],
            ['gopher://example.com/', 'scheme'],
            ['ftp://example.com/x', 'scheme'],
            ['dict://example.com:11211/', 'scheme'],
            ["\x01javascript:alert(1)", 'host'],
            ["http://example.com/\nHost: evil", 'host'],
            ['http://exa mple.com/', 'host'],
            ['http://user:pass@example.com/', 'credentials'],
            ['http://user@example.com/', 'credentials'],
            ['http://example.com\\@169.254.169.254/', 'credentials'],
            ['http://169.254.169.254\\@example.com/', 'credentials'],
            ['http://example.com:22/', 'port'],
            ['http://example.com:6379/', 'port'],
            ['https://example.com:0/', 'port'],
            ['http://2130706433/', 'host'],
            ['http://0x7f000001/', 'host'],
            ['http://0x7f.0x0.0x0.0x1/', 'host'],
            ['http://0177.0.0.1/', 'host'],
            ['http://017700000001/', 'host'],
            ['http://127.1/', 'host'],
            ['http://127.0.1/', 'host'],
            ['http://0/', 'host'],
            ['http://1.2.3.4.5/', 'host'],
            ['http://intranet/', 'host'],
            ['http://localhost/', 'host'],
            ['http://localhost./', 'host'],
            ['http://app.localhost/', 'private_address'],
            ['http://LOCALHOST.localhost/', 'private_address'],
            ['http://[::1%25eth0]/', 'host'],
            ['http://[::1/', 'host'],
            ['http://-bad-.example.com/', 'host'],
            ['http://example.123/', 'host'],
            ['http:///path', 'host'],
            ['//example.com/', 'host'],
            ['', 'host'],
            ['http://example.com/'.str_repeat('a', 2050), 'host'],
        ];
    }

    #[Test]
    #[DataProvider('refused')]
    public function refuses(string $url, string $reason): void
    {
        try {
            UrlGuard::check($url);
            $this->fail("Accepted $url");
        } catch (Refused $e) {
            $this->assertSame($reason, $e->reason, $url);
        }
    }

    #[Test]
    public function rebuilds_the_url_from_its_parts(): void
    {
        $this->assertSame(
            ['url' => 'https://example.com/a/b?c=1', 'scheme' => 'https', 'host' => 'example.com', 'port' => 443, 'ip' => null],
            UrlGuard::check('HTTPS://Example.COM./a/b?c=1#frag')
        );
        $this->assertSame('http://example.com:8080/', UrlGuard::check('http://example.com:8080')['url']);
        $this->assertSame('93.184.216.34', UrlGuard::check('http://93.184.216.34/x')['ip']);
        $this->assertSame('::1', UrlGuard::check('http://[::1]/')['ip'], 'Literal IPs pass the shape check; the address check refuses them.');
        $this->assertSame('[2606:4700::1111]', UrlGuard::check('https://[2606:4700::1111]/')['host']);
        // An @ in the query or path is not userinfo.
        $this->assertSame('example.com', UrlGuard::check('https://example.com/u/@name?mail=a@b.c')['host']);
    }

    #[Test]
    public function converts_international_names_to_punycode(): void
    {
        if (! function_exists('idn_to_ascii')) {
            $this->markTestSkipped('intl is not installed.');
        }

        $this->assertSame('xn--bcher-kva.example', UrlGuard::check('https://bücher.example/')['host']);
        $this->assertSame('https://xn--bcher-kva.example/', UrlGuard::check('https://bücher.example/')['url']);
    }
}
