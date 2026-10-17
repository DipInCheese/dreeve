<?php

declare(strict_types=1);

namespace App\Domain\Health\Snapshot;

use App\Domain\Activity\Activities;
use App\Domain\Activity\Activity;

/**
 * What you did over a period: how much, which sports, and the biggest sessions.
 */
final readonly class TrainingSummary
{
    private const int NOTABLE_ACTIVITIES = 3;

    /**
     * @param array<string, int>                $activitiesPerSport
     * @param list<array<string, string|float>> $longestActivities
     */
    private function __construct(
        public int $activities,
        public int $movingTimeInSeconds,
        public float $distanceInKm,
        public float $elevationInMeters,
        public array $activitiesPerSport,
        public array $longestActivities,
    ) {
    }

    public static function from(Activities $activities): self
    {
        $movingTime = 0;
        $distance = $elevation = 0.0;
        $perSport = [];
        $all = [];
        foreach ($activities as $activity) {
            $movingTime += $activity->getMovingTimeInSeconds();
            $distance += $activity->getDistance()->toFloat();
            $elevation += $activity->getElevation()->toFloat();
            $sport = $activity->getSportType()->value;
            $perSport[$sport] = ($perSport[$sport] ?? 0) + 1;
            $all[] = $activity;
        }
        arsort($perSport);
        usort($all, static fn (Activity $a, Activity $b): int => $b->getMovingTimeInSeconds() <=> $a->getMovingTimeInSeconds());

        return new self(
            activities: count($all),
            movingTimeInSeconds: $movingTime,
            distanceInKm: round($distance, 1),
            elevationInMeters: round($elevation),
            activitiesPerSport: $perSport,
            longestActivities: array_map(static fn (Activity $activity): array => [
                'name' => $activity->getName(),
                'day' => $activity->getStartDate()->format('Y-m-d'),
                'sport' => $activity->getSportType()->value,
                'minutes' => round($activity->getMovingTimeInSeconds() / 60),
                'distanceInKm' => round($activity->getDistance()->toFloat(), 1),
            ], array_slice($all, 0, self::NOTABLE_ACTIVITIES)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'activities' => $this->activities,
            'movingHours' => round($this->movingTimeInSeconds / 3600, 1),
            'distanceInKm' => $this->distanceInKm,
            'elevationInMeters' => $this->elevationInMeters,
            'activitiesPerSport' => $this->activitiesPerSport,
            'longestActivities' => $this->longestActivities,
        ];
    }
}
