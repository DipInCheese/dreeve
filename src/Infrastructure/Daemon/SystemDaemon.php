<?php

declare(strict_types=1);

namespace App\Infrastructure\Daemon;

use App\Console\Cache\PruneRenderCacheConsoleCommand;
use App\Console\Import\RunAutomationRulesBackfillConsoleCommand;
use App\Console\Import\RunFileImportConsoleCommand;
use App\Console\Import\RunHealthImportConsoleCommand;
use App\Domain\Health\HealthInbox;
use App\Domain\Import\WatchDirectory;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\Console\ConsoleOutputAware;
use App\Infrastructure\Daemon\Cron\CronAction;
use App\Infrastructure\Daemon\Cron\CronProcess;
use App\Infrastructure\Mutex\LockName;
use App\Infrastructure\Mutex\Mutex;
use App\Infrastructure\Time\Clock\Clock;
use Doctrine\DBAL\Connection;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use WyriHaximus\React\Cron\Action;

use function React\Promise\resolve;

/**
 * @codeCoverageIgnore
 */
final class SystemDaemon implements Daemon
{
    use ConsoleOutputAware;

    private const string CRON_EVERY_5_MINUTES = '*/5 * * * *';
    private const string CRON_DAILY = '0 4 * * *';
    private const float WATCH_INTERVAL_IN_SECONDS = 10.0;

    private bool $isImportingFiles = false;

    public function __construct(
        private readonly Clock $clock,
        private readonly SettingsRepository $settingsRepository,
        private readonly Connection $connection,
        private readonly WatchDirectory $watchDirectory,
        private readonly HealthInbox $healthInbox,
    ) {
    }

    public function watchForNewFiles(): void
    {
        Loop::addPeriodicTimer(self::WATCH_INTERVAL_IN_SECONDS, function (): void {
            if ($this->isImportingFiles) {
                return;
            }
            // One import at a time: activities first, then health data.
            if ($this->watchDirectory->hasFilesThatCanBeProcessed()) {
                $this->startFileImport('importNewFiles');
            } elseif ($this->healthInbox->hasFiles()) {
                $this->startImport('importHealthData', RunHealthImportConsoleCommand::NAME);
            }
        });
        $this->getConsoleOutput()->writeln(sprintf('<info>Watching for new files every %d seconds</info>', self::WATCH_INTERVAL_IN_SECONDS));
    }

    private function startFileImport(string $reason): void
    {
        $this->startImport($reason, RunFileImportConsoleCommand::NAME);
    }

    private function startImport(string $reason, string $command): void
    {
        $this->isImportingFiles = true;
        new CronProcess(
            cronActionId: $reason,
            clock: $this->clock,
            output: $this->getConsoleOutput(),
            command: sprintf('bin/console %s', $command),
        )->start(function (): void {
            $this->isImportingFiles = false;
        });
    }

    public function addPeriodicDebugTimer(): void
    {
        Loop::addPeriodicTimer(1.0, function (): void {
            $this->getConsoleOutput()->writeln(sprintf(
                '[%s] Periodic debug timer',
                $this->clock->getCurrentDateTimeImmutable()->format('H:i:s'),
            ));
        });
    }

    public function clearStaleCronLocks(): void
    {
        // On startup no cron child process is running yet, so any lock still present
        // in the KeyValue table is stale.
        foreach (LockName::cases() as $lockName) {
            new Mutex(
                connection: $this->connection,
                clock: $this->clock,
                lockName: $lockName,
            )->releaseLock();
        }
    }

    public function configureCron(): void
    {
        $actions = [];
        $processedCronAction = [];
        /** @var CronAction $cronAction */
        foreach ($this->settingsRepository->daemon()->getConfiguredCronActions() as $cronAction) {
            $processedCronAction[] = $cronAction;
            $actions[] = new Action(
                key: $cronAction->getId()->value,
                mutexTtl: 1200,
                expression: (string) $cronAction->getExpression(),
                performer: function () use ($cronAction): PromiseInterface {
                    $process = new CronProcess(
                        cronActionId: $cronAction->getId()->value,
                        clock: $this->clock,
                        output: $this->getConsoleOutput(),
                        command: $cronAction->getCommand(),
                    );
                    $process->start();

                    return resolve(true);
                }
            );
        }

        $extraConfiguredCronActionsOutput = [];

        $extraConfiguredCronActionsOutput[] = sprintf('<info> - runFileImport: %s</info>', self::CRON_EVERY_5_MINUTES);
        $actions[] = new Action(
            key: 'runFileImport',
            mutexTtl: 300,
            expression: self::CRON_EVERY_5_MINUTES,
            performer: function (): PromiseInterface {
                // Also recalculates metrics when no files came in, so it keeps running on its schedule.
                if (!$this->isImportingFiles) {
                    $this->startFileImport('runFileImport');
                }

                return resolve(true);
            }
        );

        $extraConfiguredCronActionsOutput[] = sprintf('<info> - runAutomationRulesBackfill: %s</info>', self::CRON_EVERY_5_MINUTES);
        $actions[] = new Action(
            key: 'runAutomationRulesBackfill',
            mutexTtl: 300,
            expression: self::CRON_EVERY_5_MINUTES,
            performer: function (): PromiseInterface {
                $process = new CronProcess(
                    cronActionId: 'runAutomationRulesBackfill',
                    clock: $this->clock,
                    output: $this->getConsoleOutput(),
                    command: sprintf('bin/console %s', RunAutomationRulesBackfillConsoleCommand::NAME)
                );
                $process->start();

                return resolve(true);
            }
        );

        $extraConfiguredCronActionsOutput[] = sprintf('<info> - pruneRenderCache: %s</info>', self::CRON_DAILY);
        $actions[] = new Action(
            key: 'pruneRenderCache',
            mutexTtl: 300,
            expression: self::CRON_DAILY,
            performer: function (): PromiseInterface {
                $process = new CronProcess(
                    cronActionId: 'pruneRenderCache',
                    clock: $this->clock,
                    output: $this->getConsoleOutput(),
                    command: sprintf('bin/console %s', PruneRenderCacheConsoleCommand::NAME),
                );
                $process->start();

                return resolve(true);
            }
        );

        \WyriHaximus\React\Cron::create(...$actions)->on('error', function (\Throwable $throwable): void {
            $this->getConsoleOutput()->writeln(sprintf('<error>%s</error>', $throwable->getMessage()));
        });

        $this->getConsoleOutput()->writeln(sprintf('<info>%s</info>', 'Cron configured'));
        $this->getConsoleOutput()->writeln([
            ...array_map(
                fn (CronAction $action): string => \sprintf('<info> - %s: %s</info>', $action->getId()->value, $action->getExpression()),
                $processedCronAction
            ),
            ...$extraConfiguredCronActionsOutput,
        ]);
    }
}
