<?php

namespace App\Tests\Domain\Health\Snapshot;

use App\Domain\Health\Baseline\Comparison;
use App\Domain\Health\DailySummary;
use App\Domain\Health\HealthMetric;
use App\Domain\Health\HealthRecords;
use App\Domain\Health\HealthRepository;
use App\Domain\Health\SleepSession;
use App\Domain\Health\Snapshot\HealthSnapshotBuilder;
use App\Domain\Health\Snapshot\MetricSnapshot;
use App\Domain\Health\Snapshot\SleepRhythm;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;

class HealthSnapshotBuilderTest extends ContainerTestCase
{
    private const string TODAY = '2026-10-17';

    public function testAWeekIsComparedWithTheNormalFromBeforeIt(): void
    {
        $this->seed(static fn (int $daysAgo): int => $daysAgo < 7 ? 56 : 48);

        $snapshot = $this->getContainer()->get(HealthSnapshotBuilder::class)->lastDays(7, SerializableDateTime::fromString(self::TODAY));

        $restingHeartRate = $this->metric($snapshot->metrics, HealthMetric::RESTING_HEART_RATE);
        $this->assertEqualsWithDelta(56.0, $restingHeartRate->average, 0.2);
        $this->assertSame(7, $restingHeartRate->daysMeasured);
        // The high week does not move its own yardstick: the normal comes from before it.
        $this->assertLessThan(56.0, (float) $restingHeartRate->normalHigh);
        $this->assertSame(Comparison::ABOVE, $restingHeartRate->comparison());
        $this->assertSame('bad', $restingHeartRate->tone());
        $this->assertSame(48.0, $restingHeartRate->previousAverage);
        $this->assertEqualsWithDelta(8.0, (float) $restingHeartRate->change(), 0.2);

        $this->assertSame(['2026-10-11', '2026-10-17', 7], [$snapshot->from, $snapshot->till, $snapshot->days()]);
        $this->assertContains(HealthMetric::RESTING_HEART_RATE, array_map(static fn (MetricSnapshot $metric): HealthMetric => $metric->metric, $snapshot->outsideNormal()));
        $this->assertTrue($snapshot->hasData());
    }

    public function testItSummarisesTheSleepRhythm(): void
    {
        $this->seed(static fn (int $daysAgo): int => 50);

        $snapshot = $this->getContainer()->get(HealthSnapshotBuilder::class)->lastDays(7, SerializableDateTime::fromString(self::TODAY));

        // Bedtimes alternate between 23:00 (four nights) and 01:00 (three nights), across midnight.
        $this->assertSame('23:51', SleepRhythm::formatTime($snapshot->sleepRhythm->averageBedtimeInMinutes));
        $this->assertSame('07:00', SleepRhythm::formatTime($snapshot->sleepRhythm->averageWakeTimeInMinutes));
        $this->assertSame(7, $snapshot->sleepRhythm->nights);
        $this->assertSame(59, $snapshot->sleepRhythm->bedtimeSpreadInMinutes);
    }

    public function testItExportsPlainFactsForAnAiModel(): void
    {
        $this->seed(static fn (int $daysAgo): int => 50);

        $export = $this->getContainer()->get(HealthSnapshotBuilder::class)->lastDays(7, SerializableDateTime::fromString(self::TODAY))->toArray();

        $this->assertSame(['period', 'metrics', 'outsideNormal', 'sleepRhythm', 'training'], array_keys($export));
        $this->assertSame(['from' => '2026-10-11', 'till' => '2026-10-17', 'days' => 7], $export['period']);
        $this->assertSame('resting-heart-rate', $export['metrics'][2]['metric']);
        $this->assertSame('within', $export['metrics'][2]['comparedWithNormal']);
        $this->assertJson((string) json_encode($export));
    }

    public function testAnEmptyPeriodHasNoData(): void
    {
        $snapshot = $this->getContainer()->get(HealthSnapshotBuilder::class)->lastDays(7, SerializableDateTime::fromString('2001-01-07'));

        $this->assertFalse($snapshot->hasData());
        $this->assertSame([], $snapshot->outsideNormal());
    }

    /**
     * @param \Closure(int): int $restingHeartRate
     */
    private function seed(\Closure $restingHeartRate): void
    {
        $records = HealthRecords::empty();
        for ($daysAgo = 40; $daysAgo >= 0; --$daysAgo) {
            $day = new \DateTimeImmutable(self::TODAY)->modify(sprintf('-%d days', $daysAgo));
            $records->addDailySummary(new DailySummary(
                day: $day->format('Y-m-d'),
                source: 'garmin',
                // A little spread, so the normal has a range.
                restingHeartRate: $restingHeartRate($daysAgo) + ($daysAgo % 3) - 1,
            ));
            $bedtime = 0 === $daysAgo % 2 ? $day->modify('-1 day')->format('Y-m-d 23:00:00') : $day->format('Y-m-d 01:00:00');
            $records->addSleepSession(new SleepSession(
                day: $day->format('Y-m-d'),
                source: 'garmin',
                startsAt: $bedtime,
                endsAt: $day->format('Y-m-d 07:00:00'),
                sleepSeconds: 25200,
            ));
        }
        $this->getContainer()->get(HealthRepository::class)->save($records);
    }

    /**
     * @param list<MetricSnapshot> $metrics
     */
    private function metric(array $metrics, HealthMetric $metric): MetricSnapshot
    {
        foreach ($metrics as $snapshot) {
            if ($snapshot->metric === $metric) {
                return $snapshot;
            }
        }

        throw new \LogicException('Missing '.$metric->value);
    }
}
