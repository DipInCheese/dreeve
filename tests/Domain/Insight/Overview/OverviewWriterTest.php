<?php

namespace App\Tests\Domain\Insight\Overview;

use App\Domain\Health\DailySummary;
use App\Domain\Health\HealthRecords;
use App\Domain\Health\HealthRepository;
use App\Domain\Insight\InsightProvider;
use App\Domain\Insight\InsightProviderUnavailable;
use App\Domain\Insight\InsightRepository;
use App\Domain\Insight\Overview\OverviewFacts;
use App\Domain\Insight\Overview\OverviewSlot;
use App\Domain\Insight\Overview\OverviewWriter;
use App\Domain\Insight\WrittenInsight;
use App\Domain\Settings\SettingsGroup;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;

class OverviewWriterTest extends ContainerTestCase
{
    private FakeInsightProvider $provider;
    private OverviewWriter $writer;

    public function testItWritesTheOverviewOfThisMomentOnce(): void
    {
        $this->seedToday();
        $now = SerializableDateTime::fromString('2026-10-18 13:05:00');

        $this->assertSame(OverviewSlot::AFTERNOON, $this->writer->dueAt($now));
        $overview = $this->writer->write(OverviewSlot::AFTERNOON, $now);

        $this->assertSame('Charged up', $overview?->headline);
        $this->assertNull($this->writer->dueAt($now->modify('+15 minutes')), 'Once per moment of the day');
        $this->assertSame(OverviewSlot::EVENING, $this->writer->dueAt(SerializableDateTime::fromString('2026-10-18 20:00:00')));

        // The model gets the facts, compared with the normal, and today's numbers.
        $this->assertSame(64, $this->provider->lastFacts['today']['bodyBatteryNow']);
        $this->assertSame('afternoon', $this->provider->lastFacts['moment']);
        $this->assertArrayHasKey('metrics', $this->provider->lastFacts['last7Days']);
        $this->assertStringContainsString('early afternoon', $this->provider->lastInstructions);
    }

    public function testTheModelKnowsTodayIsAPlannedRestDay(): void
    {
        $this->seedToday();
        $settings = $this->getContainer()->get(SettingsRepository::class);
        $settings->saveGroup(SettingsGroup::GENERAL, [...$settings->findGroup(SettingsGroup::GENERAL), 'trainingDays' => ['monday', 'wednesday']]);

        // 2026-10-18 is a Sunday.
        $this->writer->write(OverviewSlot::MORNING, SerializableDateTime::fromString('2026-10-18 08:00:00'));

        $this->assertTrue($this->provider->lastFacts['training']['todayIsAPlannedRestDay']);
        $this->assertSame(['monday', 'wednesday'], $this->provider->lastFacts['training']['plannedTrainingDays']);
        $this->assertArrayHasKey('trainingLoadPerDay', $this->provider->lastFacts['training']);
        $this->assertStringContainsString('rest day', $this->provider->lastInstructions);
    }

    public function testNothingIsDueWhenAiIsOff(): void
    {
        $this->provider->enabled = false;

        $this->assertNull($this->writer->dueAt(SerializableDateTime::fromString('2026-10-18 08:00:00')));
    }

    public function testAnUnreachableModelLeavesTheMomentDueForTheNextTry(): void
    {
        $this->seedToday();
        $this->provider->unreachable = true;
        $now = SerializableDateTime::fromString('2026-10-18 08:00:00');

        try {
            $this->writer->write(OverviewSlot::MORNING, $now);
            $this->fail('Expected the model to be unreachable');
        } catch (InsightProviderUnavailable) {
        }

        $this->assertSame(OverviewSlot::MORNING, $this->writer->dueAt($now->modify('+15 minutes')));
    }

    public function testAnAnswerInAnotherShapeIsNotStored(): void
    {
        $this->seedToday();
        $this->provider->answer = 'I think you are doing great!';

        $this->expectException(InsightProviderUnavailable::class);
        $this->writer->write(OverviewSlot::MORNING, SerializableDateTime::fromString('2026-10-18 08:00:00'));
    }

    public function testWithoutHealthDataThereIsNothingToWriteAbout(): void
    {
        $this->assertNull($this->writer->write(OverviewSlot::MORNING, SerializableDateTime::fromString('2026-10-18 08:00:00')));
        $this->assertNull($this->provider->lastFacts);
    }

    private function seedToday(): void
    {
        $records = HealthRecords::empty();
        $records->addDailySummary(new DailySummary(day: '2026-10-18', source: 'garmin', steps: 4200, bodyBatteryHighest: 81, bodyBatteryMostRecent: 64));
        $this->getContainer()->get(HealthRepository::class)->save($records);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeInsightProvider();
        $this->writer = new OverviewWriter(
            $this->provider,
            $this->getContainer()->get(InsightRepository::class),
            $this->getContainer()->get(OverviewFacts::class),
        );
    }
}

final class FakeInsightProvider implements InsightProvider
{
    public bool $enabled = true;
    public bool $unreachable = false;
    public string $answer = '{"headline": "Charged up", "summary": "Your Body Battery is still at 64.", "tips": ["Take a walk"]}';
    /** @var array<string, mixed>|null */
    public ?array $lastFacts = null;
    public string $lastInstructions = '';

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function write(string $instructions, array $facts): WrittenInsight
    {
        $this->lastInstructions = $instructions;
        $this->lastFacts = $facts;
        if ($this->unreachable) {
            throw new InsightProviderUnavailable('Connection refused');
        }

        return new WrittenInsight($this->answer, 'fake/model');
    }
}
