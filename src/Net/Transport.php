<?php

namespace Ernestdefoe\Salvage\Net;

/**
 * Sends one request to one already-approved address, following nothing.
 */
interface Transport
{
    /**
     * @param string $url  The canonical URL from UrlGuard.
     * @param string $ip   The address the connection must go to.
     * @param int    $maxBytes Body bytes to keep; more fails with 'too_large',
     *                         except 0, which stops quietly once the status is known.
     *
     * @throws Refused on any network failure
     */
    public function send(string $method, string $url, string $host, int $port, string $ip, int $maxBytes): Response;
}
