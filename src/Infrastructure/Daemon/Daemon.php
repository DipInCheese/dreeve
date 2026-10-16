<?php

declare(strict_types=1);

namespace App\Infrastructure\Daemon;

use Symfony\Component\Console\Output\OutputInterface;

interface Daemon
{
    public function setConsoleOutput(OutputInterface $output): void;

    public function getConsoleOutput(): OutputInterface;

    public function addPeriodicDebugTimer(): void;

    public function clearStaleCronLocks(): void;

    public function configureCron(): void;

    /**
     * Imports files as soon as they land in the watch folder: uploads, the API and the Garmin sync.
     */
    public function watchForNewFiles(): void;
}
