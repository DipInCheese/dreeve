<?php

declare(strict_types=1);

namespace App\Domain\Health\Baseline;

/**
 * Your normal for a metric: the median of the 60 days before the latest value, give or take a robust
 * spread (based on the median absolute deviation, so a single odd night does not shift it).
 */
final readonly class BaselineCalculator
{
    public const int HISTORY_DAYS = 60;
    public const int MINIMUM_DAYS = 14;
    // Turns the median absolute deviation into the equivalent of a standard deviation.
    private const float MAD_TO_STANDARD_DEVIATION = 1.4826;
    // Keeps the range from collapsing to a single number for very steady metrics.
    private const float MINIMUM_SPREAD_RATIO = 0.03;

    /**
     * @param array<string, int|float|null> $valuesPerDay keyed by day (Y-m-d)
     * @param float|null                    $highest      the highest possible value, e.g. 100 for a score
     */
    public function calculate(array $valuesPerDay, ?float $highest = null): Baseline
    {
        $values = array_filter($valuesPerDay, static fn (int|float|null $value): bool => null !== $value);
        if ([] === $values) {
            return Baseline::empty();
        }
        ksort($values);

        $latestDay = array_key_last($values);
        $latest = (float) $values[$latestDay];

        $sevenDaysAgo = new \DateTimeImmutable($latestDay)->modify('-6 days')->format('Y-m-d');
        $lastSevenDays = array_filter($values, static fn (string $day): bool => $day >= $sevenDaysAgo, ARRAY_FILTER_USE_KEY);
        $sevenDayAverage = array_sum($lastSevenDays) / count($lastSevenDays);

        $historyStart = new \DateTimeImmutable($latestDay)->modify(sprintf('-%d days', self::HISTORY_DAYS))->format('Y-m-d');
        $history = array_values(array_filter(
            $values,
            static fn (string $day): bool => $day >= $historyStart && $day < $latestDay,
            ARRAY_FILTER_USE_KEY,
        ));

        $normalLow = $normalHigh = null;
        if (count($history) >= self::MINIMUM_DAYS) {
            $median = $this->median($history);
            $deviation = $this->median(array_map(static fn (int|float $value): float => abs($value - $median), $history));
            $spread = max($deviation * self::MAD_TO_STANDARD_DEVIATION, abs($median) * self::MINIMUM_SPREAD_RATIO);
            // A normal range never goes past what the metric can be: no Body Battery of 103, no negative minutes.
            $normalLow = round(max(0.0, $median - $spread), 1);
            $normalHigh = round(null === $highest ? $median + $spread : min($highest, $median + $spread), 1);
        }

        return new Baseline(
            latest: $latest,
            latestDay: $latestDay,
            sevenDayAverage: round($sevenDayAverage, 1),
            normalLow: $normalLow,
            normalHigh: $normalHigh,
            daysOfHistory: count($history),
        );
    }

    /**
     * @param list<int|float> $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return 0 === $count % 2 ? ($values[$middle - 1] + $values[$middle]) / 2 : (float) $values[$middle];
    }
}
