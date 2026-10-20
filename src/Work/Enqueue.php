<?php

namespace Ernestdefoe\Salvage\Work;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Queue;

/**
 * Puts one round on the queue, unless one is already waiting there.
 */
class Enqueue
{
    public function __construct(private readonly Queue $queue, private readonly Cache $cache)
    {
    }

    public function __invoke(): void
    {
        if ($this->cache->add(Runner::QUEUED, 1, 900)) {
            $this->queue->push(new WorkJob());
        }
    }
}
