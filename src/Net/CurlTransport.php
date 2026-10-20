<?php

namespace Ernestdefoe\Salvage\Net;

/**
 * Plain ext-curl, not Guzzle: Guzzle switches to PHP streams for streamed
 * bodies, and streams cannot be pinned to an address. Here every request is
 * pinned with CURLOPT_RESOLVE, refuses proxies and redirects, speaks only
 * HTTP(S), and stops reading at the byte cap.
 */
final class CurlTransport implements Transport
{
    public const CONNECT_TIMEOUT = 5;
    public const TIMEOUT = 10;

    private const TLS_ERRORS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    public function __construct(private readonly string $userAgent)
    {
    }

    public function send(string $method, string $url, string $host, int $port, string $ip, int $maxBytes): Response
    {
        $name = trim($host, '[]');
        $pin = str_contains($ip, ':') ? "[$ip]" : $ip;
        $headers = [];
        $body = '';
        $tooLarge = false;
        $stopped = false;

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RESOLVE => ["$name:$port:$pin"],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HTTPGET => $method !== 'HEAD',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'User-Agent: '.$this->userAgent,
                'Accept: */*',
                'Accept-Encoding: identity',
            ],
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$headers): int {
                // A new status line (after a 100 Continue) starts a new header set.
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = [];
                } elseif (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($key))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body, &$tooLarge, &$stopped, $maxBytes): int {
                if ($maxBytes === 0) {
                    $stopped = true;

                    return 0;
                }

                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;

                    return 0;
                }

                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        if ($maxBytes > 0) {
            curl_setopt($curl, CURLOPT_MAXFILESIZE, $maxBytes);
        }

        curl_exec($curl);

        $errno = curl_errno($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $connected = (string) curl_getinfo($curl, CURLINFO_PRIMARY_IP);

        if ($tooLarge || $errno === CURLE_FILESIZE_EXCEEDED) {
            throw new Refused('too_large');
        }

        if ($errno !== 0 && ! ($stopped && $errno === CURLE_WRITE_ERROR)) {
            throw new Refused(match (true) {
                $errno === CURLE_OPERATION_TIMEDOUT => 'timeout',
                in_array($errno, self::TLS_ERRORS, true) => 'tls',
                default => 'connect',
            });
        }

        // Belt and braces: the connection must have gone where it was pinned.
        if ($connected !== '' && @inet_pton($connected) !== @inet_pton($ip)) {
            throw new Refused('private_address');
        }

        if ($status < 100) {
            throw new Refused('connect');
        }

        return new Response($status, $headers, $body, $url);
    }
}
