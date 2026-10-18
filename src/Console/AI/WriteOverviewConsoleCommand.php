<?php

declare(strict_types=1);

namespace App\Console\AI;

use App\Domain\Insight\InsightProviderUnavailable;
use App\Domain\Insight\Overview\Overview;
use App\Domain\Insight\Overview\OverviewSlot;
use App\Domain\Insight\Overview\OverviewWriter;
use App\Infrastructure\Cache\Render\RenderCache;
use App\Infrastructure\Cache\Tag\RootCacheTag;
use App\Infrastructure\Doctrine\Migrations\RequiresUpToDateDatabaseSchema;
use App\Infrastructure\Time\Clock\Clock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs every 15 minutes from the daemon. Writes the AI overview of the current moment of the day
 * when there is none yet; when the model can not be reached (the PC running it is off), the next
 * run tries again, until the next moment starts.
 */
#[RequiresUpToDateDatabaseSchema]
#[AsCommand(name: WriteOverviewConsoleCommand::NAME, description: 'Write the AI overview of the current moment of the day')]
final class WriteOverviewConsoleCommand extends Command
{
    public const string NAME = 'app:ai:write-overview';

    public function __construct(
        private readonly OverviewWriter $overviewWriter,
        private readonly RenderCache $renderCache,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('now', null, InputOption::VALUE_NONE, 'Write one right away, also when this moment of the day already has one');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = $this->clock->getCurrentDateTimeImmutable();
        $slot = $input->getOption('now')
            ? (OverviewSlot::dueAt($now) ?? OverviewSlot::EVENING)
            : $this->overviewWriter->dueAt($now);
        if (!$slot instanceof OverviewSlot) {
            return Command::SUCCESS;
        }

        try {
            $overview = $this->overviewWriter->write($slot, $now);
        } catch (InsightProviderUnavailable $error) {
            $output->writeln(sprintf('Could not write the %s overview, trying again in 15 minutes: %s', $slot->value, $error->getMessage()));

            return Command::SUCCESS;
        }

        if (!$overview instanceof Overview) {
            $output->writeln('No health data to write an overview about yet');

            return Command::SUCCESS;
        }

        $this->renderCache->invalidateTags(RootCacheTag::INSIGHTS);
        $output->writeln(sprintf('Wrote the %s overview: %s', $slot->value, $overview->headline));

        return Command::SUCCESS;
    }
}
