<?php

namespace Ernestdefoe\Salvage\Tests\integration\Fakes;

use Ernestdefoe\Salvage\Net\Refused;
use Ernestdefoe\Salvage\Net\Resolver;

class FakeResolver implements Resolver
{
    /** @param array<string, list<string>> $hosts */
    public function __construct(public array $hosts = [])
    {
    }

    public function resolve(string $host): array
    {
        if (! array_key_exists($host, $this->hosts)) {
            throw new Refused('dns');
        }

        return $this->hosts[$host];
    }
}
