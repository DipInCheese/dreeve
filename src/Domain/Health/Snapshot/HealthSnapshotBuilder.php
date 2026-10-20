<?php

declare(strict_types=1);

namespace App\Domain\Health\Snapshot;

use App\Domain\Activity\ActivityRepository;
use App\Domain\Athlete\Weight\AthleteWeightHistoryProvider;
use App\Domain\Health\Baseline\BaselineCalculator;
use App\Domain\Health\HealthDataSet;
use App\Domain\Health\HealthMetric;
use App\Domain\Health\HealthRepository;
use App\Domain\Health\SleepSession;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class HealthSnapshotBuilder
{
    public const array METRICS = [
        HealthMetric::SLEEP_DURATION,
        HealthMetric::SLEEP_SCORE,
        HealthMetric::RESTING_HEART_RATE,
        HealthMetric::HRV,
        HealthMetric::STRESS,
        HealthMetric::BODY_BATTERY_HIGH,
        HealthMetric::STEPS,
        HealthMetric::INTENSITY_MINUTES,
        HealthMetric::TRAINING_READINESS,
        HealthMetric::WEIGHT,
    ];

    public function __construct(
        private HealthRepository $healthRepository,
        private ActivityRepository $activityRepository,
        private AthleteWeightHistoryProvider $athleteWeightHistoryProvider,
    ) {
    }

    /**
     * The last $days days up to and including $till.
     */
    public function lastDays(int $days, SerializableDateTime $till): HealthSnapshot
    {
        return $this->build($till->modify(sprintf('-%d days', $days - 1)), $till);
    }

    public function build(SerializableDateTime $from, SerializableDateTime $till): HealthSnapshot
    {
        $from = SerializableDateTime::fromString($from->format('Y-m-d 00:00:00'));
        $till = SerializableDateTime::fromString($till->format('Y-m-d 23:59:59'));
        $periodStart = $from->format('Y-m-d');
        $periodEnd = $till->format('Y-m-d');
        $periodLength = (int) $from->diff($till)->days + 1;
        $previousStart = $from->modify(sprintf('-%d days', $periodLength))->format('Y-m-d');

        $data = HealthDataSet::load(
            repository: $this->healthRepository,
            from: $from->modify(sprintf('-%d days', BaselineCalculator::HISTORY_DAYS + $periodLength)),
            till: $till,
            weights: array_values($this->athleteWeightHistoryProvider->get()->findAll()),
        );

        $metrics = [];
        foreach (self::METRICS as $metric) {
            $series = $metric->seriesFrom($data);
            $inPeriod = $this->valuesBetween($series, $periodStart, $periodEnd);
            $previous = $this->valuesBetween($series, $previousStart, $from->modify('-1 day')->format('Y-m-d'));
            // The normal from before the period: the day before it counts as the "latest" day.
            $normal = new BaselineCalculator()->calculate(array_filter($series, static fn (string $day): bool => $day < $periodStart, ARRAY_FILTER_USE_KEY), $metric->highestValue());

            $metrics[] = new MetricSnapshot(
                metric: $metric,
                average: $this->average($inPeriod),
                daysMeasured: count($inPeriod),
                normalLow: $normal->normalLow,
                normalHigh: $normal->normalHigh,
                previousAverage: $this->average($previous),
            );
        }

        $sleepSessions = array_values(array_filter(
            $data->sleepSessions,
            static fn (SleepSession $session): bool => $session->day >= $periodStart && $session->day <= $periodEnd,
        ));

        return new HealthSnapshot(
            from: $periodStart,
            till: $periodEnd,
            metrics: $metrics,
            sleepRhythm: SleepRhythm::from($sleepSessions),
            training: TrainingSummary::from($this->activityRepository->findByDateRange($from, $till)),
        );
    }

    /**
     * @param array<string, float|null> $series
     *
     * @return list<float>
     */
    private function valuesBetween(array $series, string $from, string $till): array
    {
        $values = [];
        foreach ($series as $day => $value) {
            if (null !== $value && $day >= $from && $day <= $till) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param list<float> $values
     */
    private function average(array $values): ?float
    {
        return [] === $values ? null : round(array_sum($values) / count($values), 1);
    }
}
