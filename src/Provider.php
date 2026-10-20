<?php

namespace Ernestdefoe\Salvage;

use Ernestdefoe\Salvage\Net\CurlTransport;
use Ernestdefoe\Salvage\Net\DnsResolver;
use Ernestdefoe\Salvage\Net\Resolver;
use Ernestdefoe\Salvage\Net\Transport;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Foundation\Config;

class Provider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(Resolver::class, DnsResolver::class);
        $this->container->bind(Transport::class, fn ($container) => new CurlTransport(
            'Mozilla/5.0 (compatible; Salvage link checker; +'.$container->make(Config::class)->url().')'
        ));
    }
}
