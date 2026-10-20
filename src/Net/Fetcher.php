<?php

namespace Ernestdefoe\Salvage\Net;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/**
 * The only way this extension reaches the network.
 *
 * Every hop of a redirect chain goes through the whole gate again: the URL
 * guard, a fresh DNS lookup in which EVERY address must be public, and a
 * connection pinned to the address that was checked, so a host cannot answer
 * the check with one address and the connection with another (DNS rebinding).
 */
class Fetcher
{
    public const MAX_REDIRECTS = 5;

    public function __construct(
        private readonly Resolver $resolver,
        private readonly Transport $transport,
    ) {
    }

    /**
     * @throws Refused
     */
    public function fetch(string $method, string $url, int $maxBytes): Response
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = UrlGuard::check($url);
            $ip = $target['ip'] ?? $this->pick($target['host']);

            if (! Address::isPublic($ip)) {
                throw new Refused('private_address');
            }

            $response = $this->transport->send($method, $target['url'], $target['host'], $target['port'], $ip, $maxBytes);

            if (! $response->isRedirect()) {
                return $response;
            }

            $url = (string) UriResolver::resolve(new Uri($target['url']), new Uri((string) $response->header('location')));
        }

        throw new Refused('redirects');
    }

    private function pick(string $host): string
    {
        $ips = $this->resolver->resolve($host);

        if ($ips === []) {
            throw new Refused('dns_none');
        }

        // All or nothing: one private answer among public ones is an attack,
        // not a misconfiguration worth working around.
        foreach ($ips as $ip) {
            if (! Address::isPublic($ip)) {
                throw new Refused('private_address');
            }
        }

        // IPv4 first: plenty of servers resolve AAAA but have no IPv6 route.
        usort($ips, fn (string $a, string $b) => str_contains($a, ':') <=> str_contains($b, ':'));

        return $ips[0];
    }
}
