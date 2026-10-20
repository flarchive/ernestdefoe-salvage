<?php

namespace Ernestdefoe\Salvage\Net;

/**
 * The first gate every URL passes, before any DNS lookup: scheme, credentials,
 * port and the shape of the host. Hosts written as numbers in any other
 * spelling than canonical dotted decimal (2130706433, 0x7f.1, 0177.0.0.1,
 * 127.1) are refused here, because resolvers and libc expand them to
 * addresses the IP check would then never see written down.
 */
final class UrlGuard
{
    public const PORTS = [80, 443, 8080, 8443];

    /**
     * The URL is returned rebuilt from its parsed parts, so curl is handed
     * exactly the host this check saw and pinned, never its own reading of
     * the original string.
     *
     * @return array{url: string, scheme: string, host: string, port: int, ip: string|null}
     *
     * @throws Refused
     */
    public static function check(string $url): array
    {
        // Control characters and whitespace have no place in a URL we fetch;
        // they are how scheme and host checks get fooled ("\x01javascript:").
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new Refused('host');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'])) {
            throw new Refused('host');
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new Refused('scheme');
        }

        if (! isset($parts['host'])) {
            throw new Refused('host');
        }

        // The authority as written, up to the first / ? or #. A backslash
        // there is read as a slash by some parsers and not by others, which
        // is how "http://evil.example\@good.example" smuggles a second host.
        preg_match('~^[a-z][a-z0-9+.-]*://([^/?#]*)~i', $url, $authority);

        if (isset($parts['user']) || isset($parts['pass']) || ! isset($authority[1]) || strpbrk($authority[1], '@\\') !== false) {
            throw new Refused('credentials');
        }

        $host = strtolower($parts['host']);

        // IPv6 literal: parse_url keeps the brackets.
        if (str_starts_with($host, '[')) {
            $ip = substr($host, 1, -1);

            if (! str_ends_with($host, ']') || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new Refused('host');
            }

            return self::target($parts, $scheme, $host, $ip);
        }

        // Canonical dotted-decimal IPv4 only: filter_var refuses leading
        // zeros, short forms and hex.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::target($parts, $scheme, $host, $host);
        }

        $host = rtrim($host, '.');

        if (preg_match('/[^\x21-\x7e]/', $host)) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46) : false;

            if ($ascii === false) {
                throw new Refused('host');
            }

            $host = strtolower($ascii);
        }

        if (strlen($host) > 253 || ! self::isHostname($host)) {
            throw new Refused('host');
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new Refused('private_address');
        }

        return self::target($parts, $scheme, $host, null);
    }

    /**
     * @param array<string, mixed> $parts
     *
     * @return array{url: string, scheme: string, host: string, port: int, ip: string|null}
     */
    private static function target(array $parts, string $scheme, string $host, ?string $ip): array
    {
        $default = $scheme === 'https' ? 443 : 80;
        $port = $parts['port'] ?? $default;

        if (! in_array($port, self::PORTS, true)) {
            throw new Refused('port');
        }

        $url = "$scheme://$host".($port === $default ? '' : ":$port").($parts['path'] ?? '/');

        if (isset($parts['query'])) {
            $url .= '?'.$parts['query'];
        }

        return ['url' => $url, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $ip];
    }

    /**
     * Letters, digits and hyphens in dot-separated labels, at least two of
     * them, and a top-level label that starts with a letter, so no spelling
     * of a number can pass as a name.
     */
    private static function isHostname(string $host): bool
    {
        $labels = explode('.', $host);

        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        return (bool) preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', end($labels));
    }
}
