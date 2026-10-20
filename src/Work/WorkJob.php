<?php

namespace Ernestdefoe\Salvage\Work;

use Flarum\Queue\AbstractJob;

class WorkJob extends AbstractJob
{
    public function handle(Runner $runner): void
    {
        $runner->run();
    }
}
