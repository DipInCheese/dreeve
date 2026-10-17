<?php

declare(strict_types=1);

namespace App\Domain\Health\Snapshot;

use App\Domain\Health\Baseline\Comparison;

/**
 * A compact, structured summary of a period: how your numbers compared with your normal, how you
 * slept and what you trained. The same for every run, so it can be tested, shown as a weekly
 * summary, and later handed to an AI model as the facts to reason about.
 */
final readonly class HealthSnapshot
{
    /**
     * @param list<MetricSnapshot> $metrics
     */
    public function __construct(
        public string $from,
        public string $till,
        public array $metrics,
        public SleepRhythm $sleepRhythm,
        public TrainingSummary $training,
    ) {
    }

    public function days(): int
    {
        return (int) new \DateTimeImmutable($this->from)->diff(new \DateTimeImmutable($this->till))->days + 1;
    }

    public function hasData(): bool
    {
        foreach ($this->metrics as $metric) {
            if ($metric->daysMeasured > 0) {
                return true;
            }
        }

        return $this->training->activities > 0;
    }

    /**
     * The metrics whose average was outside your normal, the ones worth talking about.
     *
     * @return list<MetricSnapshot>
     */
    public function outsideNormal(): array
    {
        return array_values(array_filter(
            $this->metrics,
            static fn (MetricSnapshot $metric): bool => in_array($metric->comparison(), [Comparison::ABOVE, Comparison::BELOW], true),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period' => ['from' => $this->from, 'till' => $this->till, 'days' => $this->days()],
            'metrics' => array_map(static fn (MetricSnapshot $metric): array => $metric->toArray(), $this->metrics),
            'outsideNormal' => array_map(static fn (MetricSnapshot $metric): string => $metric->metric->value, $this->outsideNormal()),
            'sleepRhythm' => $this->sleepRhythm->toArray(),
            'training' => $this->training->toArray(),
        ];
    }
}
