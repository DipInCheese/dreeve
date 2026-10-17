<?php

declare(strict_types=1);

namespace App\Domain\Health\Snapshot;

use App\Domain\Health\SleepSession;

/**
 * When you went to bed and got up over a period, and how much that moved around.
 * A steady rhythm matters as much as the hours.
 */
final readonly class SleepRhythm
{
    // Bedtimes are counted from noon, so 23:30 and 00:30 are an hour apart and not 23 hours.
    private const int DAY_STARTS_AT_MINUTE = 12 * 60;

    private function __construct(
        public int $nights,
        public ?int $averageBedtimeInMinutes,
        public ?int $averageWakeTimeInMinutes,
        public ?int $bedtimeSpreadInMinutes,
    ) {
    }

    /**
     * @param list<SleepSession> $sessions
     */
    public static function from(array $sessions): self
    {
        $bedtimes = $wakeTimes = [];
        foreach ($sessions as $session) {
            if (null === $session->startsAt || null === $session->endsAt) {
                continue;
            }
            $bedtimes[] = self::minutesFromNoon($session->startsAt);
            $wakeTimes[] = self::minutesOfDay($session->endsAt);
        }

        if ([] === $bedtimes) {
            return new self(count($sessions), null, null, null);
        }

        $averageBedtime = array_sum($bedtimes) / count($bedtimes);
        $spread = sqrt(array_sum(array_map(static fn (int $minutes): float => ($minutes - $averageBedtime) ** 2, $bedtimes)) / count($bedtimes));

        return new self(
            nights: count($sessions),
            averageBedtimeInMinutes: ((int) round($averageBedtime) + self::DAY_STARTS_AT_MINUTE) % 1440,
            averageWakeTimeInMinutes: (int) round(array_sum($wakeTimes) / count($wakeTimes)),
            bedtimeSpreadInMinutes: (int) round($spread),
        );
    }

    public static function formatTime(?int $minutesOfDay): ?string
    {
        return null === $minutesOfDay ? null : sprintf('%02d:%02d', intdiv($minutesOfDay, 60), $minutesOfDay % 60);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'nights' => $this->nights,
            'averageBedtime' => self::formatTime($this->averageBedtimeInMinutes),
            'averageWakeTime' => self::formatTime($this->averageWakeTimeInMinutes),
            'bedtimeVariesByMinutes' => $this->bedtimeSpreadInMinutes,
        ];
    }

    private static function minutesOfDay(string $dateTime): int
    {
        $time = new \DateTimeImmutable($dateTime);

        return (int) $time->format('G') * 60 + (int) $time->format('i');
    }

    private static function minutesFromNoon(string $dateTime): int
    {
        return (self::minutesOfDay($dateTime) - self::DAY_STARTS_AT_MINUTE + 1440) % 1440;
    }
}
