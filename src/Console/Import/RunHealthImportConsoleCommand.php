<?php

declare(strict_types=1);

namespace App\Console\Import;

use App\Domain\Health\BodyMeasurementDelivery;
use App\Domain\Health\Garmin\GarminHealthParser;
use App\Domain\Health\HealthInbox;
use App\Domain\Health\HealthRepository;
use App\Domain\Health\SourceRecord;
use App\Infrastructure\Cache\Render\RenderCache;
use App\Infrastructure\Cache\Tag\RootCacheTag;
use App\Infrastructure\DependencyInjection\Mutex\WithMutex;
use App\Infrastructure\Doctrine\Migrations\RequiresUpToDateDatabaseSchema;
use App\Infrastructure\Exception\CorruptedData;
use App\Infrastructure\Logging\LoggableConsoleOutput;
use App\Infrastructure\Mutex\LockIsAlreadyAcquired;
use App\Infrastructure\Mutex\LockName;
use App\Infrastructure\Mutex\Mutex;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\String\CompressedString;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports what sources dropped in the health inbox: every delivery is archived as it came, then
 * turned into health records.
 */
#[WithMonologChannel('daemon')]
#[WithMutex(lockName: LockName::IMPORT_DATA)]
#[RequiresUpToDateDatabaseSchema]
#[AsCommand(name: RunHealthImportConsoleCommand::NAME, description: 'Import health data from the inbox')]
final class RunHealthImportConsoleCommand extends Command
{
    public const string NAME = 'app:import:health';

    public function __construct(
        private readonly HealthInbox $healthInbox,
        private readonly HealthRepository $healthRepository,
        private readonly GarminHealthParser $garminHealthParser,
        private readonly Clock $clock,
        private readonly RenderCache $renderCache,
        private readonly Mutex $mutex,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output = new SymfonyStyle($input, new LoggableConsoleOutput($output, $this->logger));

        $files = $this->healthInbox->listFiles();
        if ([] === $files) {
            $output->writeln('No health data to import...');

            return Command::SUCCESS;
        }

        try {
            $this->mutex->acquireLock('runHealthImport');
        } catch (LockIsAlreadyAcquired) {
            $output->writeln('<comment>Postponing health import, another process is importing data.</comment>');

            return Command::SUCCESS;
        }

        $imported = 0;
        $failed = 0;
        $hasWeighIns = false;
        try {
            foreach ($files as $path) {
                try {
                    $hasWeighIns = $this->import($path) || $hasWeighIns;
                    $this->healthInbox->delete($path);
                    ++$imported;
                } catch (CorruptedData|\InvalidArgumentException $exception) {
                    // Kept aside instead of retried forever; the archive of earlier deliveries is untouched.
                    $this->healthInbox->moveToFailed($path);
                    $this->logger->error(sprintf('Could not import "%s": %s', $path, $exception->getMessage()));
                    ++$failed;
                }
            }
        } finally {
            $this->mutex->releaseLock();
        }

        $this->renderCache->invalidateTags(RootCacheTag::HEALTH);
        if ($hasWeighIns) {
            // Weights feed relative power (w/kg) everywhere, cached with the general settings.
            $this->renderCache->invalidateTags(RootCacheTag::SETTINGS_GENERAL);
        }

        $output->writeln(sprintf('Imported %d health deliveries, %d failed', $imported, $failed));

        return Command::SUCCESS;
    }

    /**
     * @return bool whether the delivery contained weigh-ins
     */
    private function import(string $path): bool
    {
        $contents = $this->healthInbox->read($path);
        $payload = Json::decode($contents);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Expected a JSON object');
        }

        $source = is_string($payload['source'] ?? null) ? $payload['source'] : '';
        $kind = is_string($payload['kind'] ?? null) ? $payload['kind'] : '';
        // Garmin's own payloads, or weigh-ins in Dreeve's format from any source (e.g. a Eufy scale).
        $isBodyDelivery = BodyMeasurementDelivery::KIND === $kind && BodyMeasurementDelivery::isSource($source);
        if (!$isBodyDelivery && (GarminHealthParser::SOURCE !== $source || !in_array($kind, ['day', 'range'], true))) {
            throw new \InvalidArgumentException(sprintf('Unknown delivery "%s/%s"', $source, $kind));
        }
        $recordKey = match (true) {
            $isBodyDelivery => hash('xxh128', $contents),
            'day' === $kind => (string) ($payload['date'] ?? ''),
            default => sprintf('%s..%s', $payload['start'] ?? '', $payload['end'] ?? ''),
        };

        $this->healthRepository->saveSourceRecord(new SourceRecord(
            id: sprintf('%s:%s:%s', $source, $kind, $recordKey),
            source: $source,
            kind: $kind,
            recordKey: $recordKey,
            receivedOn: $this->clock->getCurrentDateTimeImmutable()->format('Y-m-d H:i:s'),
            payload: (string) CompressedString::fromUncompressed($contents),
        ));
        $records = $isBodyDelivery ? BodyMeasurementDelivery::parse($payload) : $this->garminHealthParser->parse($payload);
        $this->healthRepository->save($records);

        return [] !== $records->getBodyMeasurements();
    }
}
