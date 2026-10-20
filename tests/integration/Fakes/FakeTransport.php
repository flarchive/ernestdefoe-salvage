<?php

namespace Ernestdefoe\Salvage\Tests\integration\Fakes;

use Ernestdefoe\Salvage\Net\Refused;
use Ernestdefoe\Salvage\Net\Response;
use Ernestdefoe\Salvage\Net\Transport;

/**
 * Answers with scripted responses, in order, and records what was sent.
 * A response can also be keyed by URL (respond()) for runs that visit
 * several links in an order the test does not fix.
 */
class FakeTransport implements Transport
{
    /** @var list<array{0: string, 1: string, 2: string, 3: int, 4: string, 5: int}> */
    public array $sent = [];

    /** @var list<array{int|string, array<string, string>, string}> */
    private array $queue = [];

    /** @var array<string, list<array{int|string, array<string, string>, string}>> */
    private array $byUrl = [];

    public function queue(int|string $status, array $headers = [], string $body = ''): void
    {
        $this->queue[] = [$status, $headers, $body];
    }

    /** A status, or a Refused reason such as 'timeout'. Repeats the last answer when run out. */
    public function respond(string $url, int|string $status, array $headers = [], string $body = ''): void
    {
        $this->byUrl[$url][] = [$status, $headers, $body];
    }

    public function send(string $method, string $url, string $host, int $port, string $ip, int $maxBytes): Response
    {
        $this->sent[] = [$method, $url, $host, $port, $ip, $maxBytes];

        if (isset($this->byUrl[$url])) {
            $answer = count($this->byUrl[$url]) > 1 ? array_shift($this->byUrl[$url]) : $this->byUrl[$url][0];
        } else {
            $answer = array_shift($this->queue) ?? [404, [], ''];
        }

        [$status, $headers, $body] = $answer;

        if (is_string($status)) {
            throw new Refused($status);
        }

        if ($maxBytes > 0 && strlen($body) > $maxBytes) {
            throw new Refused('too_large');
        }

        return new Response($status, array_change_key_case($headers), $method === 'HEAD' || $maxBytes === 0 ? '' : $body, $url);
    }
}
