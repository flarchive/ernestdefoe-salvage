<?php

namespace Ernestdefoe\Salvage\Tests\integration;

use Ernestdefoe\Salvage\Net\CurlTransport;
use Ernestdefoe\Salvage\Net\DnsResolver;
use Ernestdefoe\Salvage\Net\Fetcher;
use Ernestdefoe\Salvage\Net\Resolver;
use Ernestdefoe\Salvage\Net\Transport;
use Ernestdefoe\Salvage\Work\Enqueue;
use Ernestdefoe\Salvage\Work\Runner;
use Illuminate\Contracts\Cache\Repository;
use PHPUnit\Framework\Attributes\Test;

/** The real wiring, without fakes: what a forum actually runs. */
class WiringTest extends SalvageTestCase
{
    #[Test]
    public function the_real_network_classes_are_bound(): void
    {
        $container = $this->app()->getContainer();

        $this->assertInstanceOf(DnsResolver::class, $container->make(Resolver::class));
        $this->assertInstanceOf(CurlTransport::class, $container->make(Transport::class));
        $this->assertInstanceOf(Fetcher::class, $container->make(Fetcher::class));
    }

    #[Test]
    public function the_scheduled_command_queues_one_round_and_the_sync_queue_runs_it(): void
    {
        $this->setting('ernestdefoe-salvage.check_links', false);
        $this->setting('ernestdefoe-salvage.mirror_images', false);
        $cache = $this->app()->getContainer()->make(Repository::class);

        $this->app()->getContainer()->make(Enqueue::class)();

        // The sync queue ran the round right away, and the round cleared the marker.
        $this->assertFalse($cache->has(Runner::QUEUED));

        // A round still waiting on the queue keeps a second one out.
        $cache->put(Runner::QUEUED, 1, 60);
        $this->assertFalse($cache->add(Runner::QUEUED, 1, 60));
    }
}
