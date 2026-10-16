<?php

declare(strict_types=1);

namespace App\Domain\Health;

use App\Domain\Health\Baseline\Baseline;
use App\Domain\Health\Baseline\BaselineCalculator;
use App\Domain\Health\Baseline\Comparison;

/**
 * One metric at a glance: its latest value, your normal range and how they compare.
 */
final readonly class MetricOverview
{
    // Values older than this are shown as "last measured on", not as today's number.
    private const int RECENT_DAYS = 2;

    /**
     * @param array<string, float|null> $series
     */
    private function __construct(
        public HealthMetric $metric,
        public Baseline $baseline,
        public array $series,
        public bool $isRecent,
    ) {
    }

    public static function for(HealthMetric $metric, HealthDataSet $data): self
    {
        $series = $metric->seriesFrom($data);
        $baseline = new BaselineCalculator()->calculate($series);

        // For HRV, Garmin has its own balanced range for you, based on weeks of overnight readings.
        if (HealthMetric::HRV === $metric && null !== $baseline->latestDay) {
            $hrv = $data->hrvSummaries[$baseline->latestDay] ?? null;
            if (null !== $hrv?->balancedLow && null !== $hrv->balancedHigh) {
                $baseline = new Baseline(
                    latest: $baseline->latest,
                    latestDay: $baseline->latestDay,
                    sevenDayAverage: $hrv->weeklyAverage ?? $baseline->sevenDayAverage,
                    normalLow: $hrv->balancedLow,
                    normalHigh: $hrv->balancedHigh,
                    daysOfHistory: $baseline->daysOfHistory,
                );
            }
        }

        $isRecent = null !== $baseline->latestDay
            && $baseline->latestDay >= $data->till->modify(sprintf('-%d days', self::RECENT_DAYS - 1))->format('Y-m-d');

        return new self($metric, $baseline, $series, $isRecent);
    }

    public function comparison(): Comparison
    {
        return $this->baseline->compare();
    }

    public function tone(): string
    {
        return $this->comparison()->tone($this->metric->direction());
    }

    public function formatLatest(): ?string
    {
        return null === $this->baseline->latest ? null : $this->format($this->baseline->latest);
    }

    public function format(?float $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (in_array($this->metric, [HealthMetric::SLEEP_DURATION, HealthMetric::DEEP_SLEEP, HealthMetric::REM_SLEEP], true)) {
            $minutes = (int) round($value * 60);

            return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
        }

        return number_format($value, $this->metric->decimals(), '.', ' ');
    }

    public function unit(): string
    {
        return in_array($this->metric, [HealthMetric::SLEEP_DURATION, HealthMetric::DEEP_SLEEP, HealthMetric::REM_SLEEP], true) ? '' : $this->metric->unit();
    }
}
