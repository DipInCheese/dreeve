<?php

declare(strict_types=1);

namespace App\Domain\Health\Baseline;

/**
 * A health number next to your own normal: most numbers only mean something compared to yourself.
 */
final readonly class Baseline
{
    public function __construct(
        public ?float $latest,
        public ?string $latestDay,
        public ?float $sevenDayAverage,
        public ?float $normalLow,
        public ?float $normalHigh,
        public int $daysOfHistory,
    ) {
    }

    public static function empty(): self
    {
        return new self(null, null, null, null, null, 0);
    }

    public function hasLatest(): bool
    {
        return null !== $this->latest;
    }

    public function hasNormalRange(): bool
    {
        return null !== $this->normalLow && null !== $this->normalHigh;
    }

    public function compare(): Comparison
    {
        if (null === $this->latest || null === $this->normalLow || null === $this->normalHigh) {
            return Comparison::UNKNOWN;
        }

        return match (true) {
            $this->latest < $this->normalLow => Comparison::BELOW,
            $this->latest > $this->normalHigh => Comparison::ABOVE,
            default => Comparison::WITHIN,
        };
    }
}
