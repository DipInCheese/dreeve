<?php

namespace App\Tests\Console\Import;

use App\Console\Import\RunHealthImportConsoleCommand;
use App\Domain\Health\HealthRepository;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\String\CompressedString;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\Console\ConsoleCommandTestCase;
use App\Tests\Domain\Health\Garmin\GarminHealthParserTest;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RunHealthImportConsoleCommandTest extends ConsoleCommandTestCase
{
    private FilesystemOperator $storage;
    private HealthRepository $healthRepository;

    public function testItImportsTheInbox(): void
    {
        $this->storage->write('inbox/health/garmin-day-2026-10-16.json', Json::encode(GarminHealthParserTest::day()));
        $this->storage->write('inbox/health/.garmin-day-2026-10-17.json.part', '{"half": ');

        $display = $this->execute();

        $this->assertStringContainsString('Imported 1 health deliveries, 0 failed', $display);
        $day = SerializableDateTime::fromString('2026-10-16');
        $this->assertSame(8421, $this->healthRepository->findDailySummaries($day, $day)['2026-10-16']->steps);
        $this->assertSame(81, $this->healthRepository->findSleepSessions($day, $day)['2026-10-16']->score);
        $this->assertSame(52.0, $this->healthRepository->findHrvSummaries($day, $day)['2026-10-16']->lastNightAverage);
        $this->assertSame(74, $this->healthRepository->findFitnessStatuses($day, $day)['2026-10-16']->trainingReadiness);
        $this->assertTrue($this->healthRepository->hasHealthData());

        // Imported deliveries leave the inbox; files still being written stay.
        $this->assertFalse($this->storage->fileExists('inbox/health/garmin-day-2026-10-16.json'));
        $this->assertTrue($this->storage->fileExists('inbox/health/.garmin-day-2026-10-17.json.part'));

        // The delivery is archived as it came.
        $payload = $this->getConnection()->fetchOne("SELECT payload FROM SourceRecord WHERE id = 'garmin:day:2026-10-16'");
        $this->assertEquals(GarminHealthParserTest::day(), Json::decode(CompressedString::fromCompressed((string) $payload)->uncompress()));
    }

    public function testANewerDeliveryReplacesTheDay(): void
    {
        $this->storage->write('inbox/health/garmin-day-2026-10-16.json', Json::encode(GarminHealthParserTest::day()));
        $this->execute();

        $later = GarminHealthParserTest::day();
        $later['summary']['totalSteps'] = 12000;
        $this->storage->write('inbox/health/garmin-day-2026-10-16.json', Json::encode($later));
        $this->execute();

        $day = SerializableDateTime::fromString('2026-10-16');
        $this->assertSame(12000, $this->healthRepository->findDailySummaries($day, $day)['2026-10-16']->steps);
        $this->assertSame(1, (int) $this->getConnection()->fetchOne('SELECT COUNT(*) FROM SourceRecord'));
    }

    public function testVo2MaxAndReadinessArriveSeparately(): void
    {
        $this->storage->write('inbox/health/garmin-day-2026-10-16.json', Json::encode(GarminHealthParserTest::day()));
        $this->storage->write('inbox/health/garmin-range-2026-10-16-2026-10-16.json', Json::encode([
            'source' => 'garmin',
            'kind' => 'range',
            'start' => '2026-10-16',
            'end' => '2026-10-16',
            'maxMetrics' => [['generic' => ['calendarDate' => '2026-10-16', 'vo2MaxPreciseValue' => 52.6]]],
            'bodyComposition' => null,
        ]));

        $this->execute();

        $day = SerializableDateTime::fromString('2026-10-16');
        $fitness = $this->healthRepository->findFitnessStatuses($day, $day)['2026-10-16'];
        $this->assertSame(74, $fitness->trainingReadiness);
        $this->assertSame(52.6, $fitness->vo2MaxRunning);
    }

    public function testItSetsAsideWhatItCanNotRead(): void
    {
        $this->storage->write('inbox/health/broken.json', '{not json');
        $this->storage->write('inbox/health/unknown.json', '{"source": "somewhere", "kind": "day"}');

        $display = $this->execute();

        $this->assertStringContainsString('Imported 0 health deliveries, 2 failed', $display);
        $this->assertTrue($this->storage->fileExists('inbox/health/failed/broken.json'));
        $this->assertTrue($this->storage->fileExists('inbox/health/failed/unknown.json'));
        $this->assertFalse($this->healthRepository->hasHealthData());
    }

    public function testItHasNothingToDoWithAnEmptyInbox(): void
    {
        $this->assertStringContainsString('No health data to import', $this->execute());
    }

    private function execute(): string
    {
        $commandTester = new CommandTester($this->getConsoleCommand());
        $this->assertSame(Command::SUCCESS, $commandTester->execute([]));

        return $commandTester->getDisplay();
    }

    protected function getConsoleCommand(): Command
    {
        return $this->getContainer()->get(RunHealthImportConsoleCommand::class);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = $this->getContainer()->get('default.storage');
        $this->storage->deleteDirectory('inbox');
        $this->healthRepository = $this->getContainer()->get(HealthRepository::class);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->storage->deleteDirectory('inbox');

        parent::tearDown();
    }
}
