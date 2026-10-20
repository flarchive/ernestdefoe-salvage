<?php

namespace Ernestdefoe\Salvage\Net;

final class Response
{
    /**
     * @param array<string, string> $headers Lower-cased names, last value wins.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $url,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isRedirect(): bool
    {
        return in_array($this->status, [301, 302, 303, 307, 308], true) && $this->header('location') !== null;
    }
}
