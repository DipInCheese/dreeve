<?php

declare(strict_types=1);

namespace App\Domain\Health\Snapshot;

use App\Domain\Health\Baseline\Comparison;
use App\Domain\Health\HealthMetric;

/**
 * One metric over a period: its average, compared with your normal from before the period
 * (so a bad week does not move its own yardstick) and with the period before.
 */
final readonly class MetricSnapshot
{
    public function __construct(
        public HealthMetric $metric,
        public ?float $average,
        public int $daysMeasured,
        public ?float $normalLow,
        public ?float $normalHigh,
        public ?float $previousAverage,
    ) {
    }

    public function comparison(): Comparison
    {
        if (null === $this->average || null === $this->normalLow || null === $this->normalHigh) {
            return Comparison::UNKNOWN;
        }

        return match (true) {
            $this->average < $this->normalLow => Comparison::BELOW,
            $this->average > $this->normalHigh => Comparison::ABOVE,
            default => Comparison::WITHIN,
        };
    }

    public function tone(): string
    {
        return $this->comparison()->tone($this->metric->direction());
    }

    public function change(): ?float
    {
        if (null === $this->average || null === $this->previousAverage) {
            return null;
        }

        return round($this->average - $this->previousAverage, $this->metric->decimals() + 1);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric->value,
            // Scores (sleep score, stress, Body Battery, readiness) have no unit; say what they are, or a model guesses "%".
            'unit' => '' === $this->metric->unit() ? 'score from 0 to 100' : $this->metric->unit(),
            'average' => $this->average,
            'daysMeasured' => $this->daysMeasured,
            'normalRange' => null === $this->normalLow ? null : [$this->normalLow, $this->normalHigh],
            'comparedWithNormal' => $this->comparison()->value,
            'previousPeriodAverage' => $this->previousAverage,
            'changeFromPreviousPeriod' => $this->change(),
        ];
    }
}
