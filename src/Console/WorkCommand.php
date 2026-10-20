<?php

namespace Ernestdefoe\Salvage\Console;

use Ernestdefoe\Salvage\Work\Runner;
use Ernestdefoe\Salvage\Work\Enqueue;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Scheduled every five minutes. Hands a round of work to the queue (a worker
 * runs it, or with the default sync queue this CLI process does), unless one
 * is already waiting there: a stalled worker must not come back to a pile.
 *
 * No constructor dependencies: Flarum's scheduler builds every scheduled
 * command when the app boots, so anything injected here would be built on
 * every request too. Everything is resolved in fire().
 */
class WorkCommand extends AbstractCommand
{

    protected function configure(): void
    {
        $this->setName('salvage:work')
            ->setDescription('Mirror hotlinked images and check links in posts (one bounded round).')
            ->addOption('now', null, InputOption::VALUE_NONE, 'Run the round in this process instead of queueing it, and print what it did.');
    }

    protected function fire(): int
    {
        if ($this->input->getOption('now')) {
            $result = resolve(Runner::class)->run();

            $this->info($result === null ? 'Another round is running.' : json_encode($result, JSON_THROW_ON_ERROR));

            return 0;
        }

        resolve(Enqueue::class)();

        return 0;
    }
}
