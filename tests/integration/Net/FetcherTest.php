<?php

namespace Ernestdefoe\Salvage\Tests\integration\Net;

use Ernestdefoe\Salvage\Net\Fetcher;
use Ernestdefoe\Salvage\Net\Refused;
use Ernestdefoe\Salvage\Tests\integration\Fakes\FakeResolver;
use Ernestdefoe\Salvage\Tests\integration\Fakes\FakeTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FetcherTest extends TestCase
{
    private FakeResolver $dns;
    private FakeTransport $http;
    private Fetcher $fetcher;

    protected function setUp(): void
    {
        $this->dns = new FakeResolver([
            'public.example' => ['93.184.216.34'],
            'other.example' => ['1.1.1.1', '2606:4700:4700::1111'],
            'v6only.example' => ['2606:4700:4700::1111'],
            'internal.example' => ['10.0.0.5'],
            'metadata.example' => ['169.254.169.254'],
            'mixed.example' => ['93.184.216.34', '127.0.0.1'],
            'mapped.example' => ['::ffff:127.0.0.1'],
            'nat64.example' => ['64:ff9b::a9fe:a9fe'],
            'gone.example' => [],
        ]);
        $this->http = new FakeTransport();
        $this->fetcher = new Fetcher($this->dns, $this->http);
    }

    private function refusal(string $url): string
    {
        try {
            $this->fetcher->fetch('GET', $url, 1000);
        } catch (Refused $e) {
            return $e->reason;
        }

        $this->fail("Fetched $url");
    }

    #[Test]
    public function pins_the_connection_to_the_checked_address(): void
    {
        $this->http->queue(200);

        $this->assertSame(200, $this->fetcher->fetch('HEAD', 'https://public.example/a?b=1', 0)->status);
        $this->assertSame([['HEAD', 'https://public.example/a?b=1', 'public.example', 443, '93.184.216.34', 0]], $this->http->sent);
    }

    #[Test]
    public function prefers_ipv4_and_uses_ipv6_when_that_is_all_there_is(): void
    {
        $this->http->queue(200);
        $this->http->queue(200);

        $this->fetcher->fetch('GET', 'http://other.example/', 10);
        $this->fetcher->fetch('GET', 'http://v6only.example/', 10);

        $this->assertSame('1.1.1.1', $this->http->sent[0][4]);
        $this->assertSame('2606:4700:4700::1111', $this->http->sent[1][4]);
    }

    #[Test]
    public function refuses_hosts_that_resolve_to_private_addresses_without_connecting(): void
    {
        foreach (['internal', 'metadata', 'mixed', 'mapped', 'nat64'] as $name) {
            $this->assertSame('private_address', $this->refusal("http://$name.example/"), $name);
        }

        foreach (['http://127.0.0.1/', 'http://10.1.2.3/', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/', 'http://[::ffff:127.0.0.1]/', 'http://[fd00:ec2::254]/', 'http://[64:ff9b::7f00:1]/', 'http://0.0.0.0/'] as $url) {
            $this->assertSame('private_address', $this->refusal($url), $url);
        }

        $this->assertSame([], $this->http->sent, 'Nothing may be sent to a refused address.');
    }

    #[Test]
    public function rechecks_every_redirect_hop(): void
    {
        $this->http->queue(302, ['location' => 'http://169.254.169.254/latest/meta-data/']);
        $this->assertSame('private_address', $this->refusal('https://public.example/r'));

        $this->http->queue(301, ['location' => 'http://internal.example/admin']);
        $this->assertSame('private_address', $this->refusal('https://public.example/r'));

        $this->http->queue(307, ['location' => 'file:///etc/passwd']);
        $this->assertSame('scheme', $this->refusal('https://public.example/r'));

        $this->http->queue(308, ['location' => 'http://public.example:6379/']);
        $this->assertSame('port', $this->refusal('https://public.example/r'));

        $this->http->queue(302, ['location' => 'http://[::1]:80/']);
        $this->assertSame('private_address', $this->refusal('https://public.example/r'));

        $this->assertCount(5, $this->http->sent, 'Only the first hop of each chain was sent.');
    }

    #[Test]
    public function follows_safe_redirects_relative_and_absolute(): void
    {
        $this->http->queue(301, ['location' => '/moved?x=1']);
        $this->http->queue(302, ['location' => 'https://other.example/final']);
        $this->http->queue(200);

        $response = $this->fetcher->fetch('GET', 'http://public.example/start', 100);

        $this->assertSame(200, $response->status);
        $this->assertSame('http://public.example/moved?x=1', $this->http->sent[1][1]);
        $this->assertSame(['https://other.example/final', 'other.example', 443, '1.1.1.1'], array_slice($this->http->sent[2], 1, 4));
    }

    #[Test]
    public function stops_after_five_redirects(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->http->queue(302, ['location' => "/hop$i"]);
        }

        $this->assertSame('redirects', $this->refusal('http://public.example/'));
        $this->assertCount(6, $this->http->sent);
    }

    #[Test]
    public function reports_domains_with_no_addresses(): void
    {
        $this->assertSame('dns_none', $this->refusal('http://gone.example/'));
    }
}
