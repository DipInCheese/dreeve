<?php

declare(strict_types=1);

namespace App\Domain\Insight\Overview;

use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\DailyTrainingLoad;
use App\Domain\Health\HealthRepository;
use App\Domain\Health\Snapshot\HealthSnapshotBuilder;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

/**
 * Everything an overview may use, as plain facts: today so far, last night, and the last 7 days
 * compared with the user's normal. Nothing else is handed to the model.
 */
final readonly class OverviewFacts
{
    public function __construct(
        private HealthSnapshotBuilder $healthSnapshotBuilder,
        private HealthRepository $healthRepository,
        private ActivityRepository $activityRepository,
        private DailyTrainingLoad $dailyTrainingLoad,
        private SettingsRepository $settingsRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function for(OverviewSlot $slot, SerializableDateTime $now): array
    {
        $day = $now->format('Y-m-d');
        $startOfDay = SerializableDateTime::fromString($day.' 00:00:00');
        $summary = $this->healthRepository->findDailySummaries($startOfDay, $now)[$day] ?? null;
        $sleep = $this->healthRepository->findSleepSessions($startOfDay, $now)[$day] ?? null;
        $hrv = $this->healthRepository->findHrvSummaries($startOfDay, $now)[$day] ?? null;
        $fitness = $this->healthRepository->findFitnessStatuses($startOfDay, $now)[$day] ?? null;

        $activitiesToday = [];
        foreach ($this->activityRepository->findByDateRange($startOfDay, $now) as $activity) {
            $activitiesToday[] = [
                'name' => $activity->getName(),
                'sport' => $activity->getSportType()->value,
                'startedAt' => $activity->getStartDate()->format('H:i'),
                'minutes' => round($activity->getMovingTimeInSeconds() / 60),
                'distanceInKm' => round($activity->getDistance()->toFloat(), 1),
            ];
        }

        return [
            'now' => $now->format('Y-m-d H:i'),
            'moment' => $slot->value,
            'today' => [
                'bodyBatteryNow' => $summary?->bodyBatteryMostRecent,
                'bodyBatteryChargedTo' => $summary?->bodyBatteryHighest,
                'bodyBatteryLowest' => $summary?->bodyBatteryLowest,
                'steps' => $summary?->steps,
                'averageStress' => $summary?->averageStress,
                'restingHeartRate' => $summary?->restingHeartRate,
                'intensityMinutes' => $summary?->getIntensityMinutes(),
                'activities' => $activitiesToday,
            ],
            'lastNight' => [
                'sleepHours' => null === $sleep?->sleepSeconds ? null : round($sleep->sleepSeconds / 3600, 1),
                'sleepScore' => $sleep?->score,
                'bedtime' => null === $sleep?->startsAt ? null : SerializableDateTime::fromString($sleep->startsAt)->format('H:i'),
                'wakeTime' => null === $sleep?->endsAt ? null : SerializableDateTime::fromString($sleep->endsAt)->format('H:i'),
                'deepSleepMinutes' => null === $sleep?->deepSleepSeconds ? null : (int) round($sleep->deepSleepSeconds / 60),
                'remSleepMinutes' => null === $sleep?->remSleepSeconds ? null : (int) round($sleep->remSleepSeconds / 60),
                'hrvInMs' => $hrv?->lastNightAverage,
                'hrvStatus' => $hrv?->status,
                'trainingReadiness' => $fitness?->trainingReadiness,
            ],
            'training' => $this->training($now),
            'last7Days' => $this->healthSnapshotBuilder->lastDays(7, $now)->toArray(),
            'scoresAreFrom0To100' => ['sleepScore', 'averageStress', 'bodyBattery', 'trainingReadiness'],
        ];
    }

    /**
     * The user's plan for today and how hard the last week was, so advice fits both: after a hard
     * session, or on a planned rest day, recovery comes first.
     *
     * @return array<string, mixed>
     */
    private function training(SerializableDateTime $now): array
    {
        $general = $this->settingsRepository->general();
        $weekAgo = SerializableDateTime::fromString($now->modify('-6 days')->format('Y-m-d 00:00:00'));
        $loadPerDay = $this->dailyTrainingLoad->calculateForDateRange(DateRange::fromDates($weekAgo, $now));

        $recent = [];
        foreach ($this->activityRepository->findByDateRange($weekAgo, $now) as $activity) {
            $day = $activity->getStartDate()->format('Y-m-d');
            $recent[] = [
                'day' => $day,
                'weekday' => $activity->getStartDate()->format('l'),
                'daysAgo' => (int) SerializableDateTime::fromString($day)->diff(SerializableDateTime::fromString($now->format('Y-m-d')))->days,
                'name' => $activity->getName(),
                'sport' => $activity->getSportType()->value,
                'minutes' => round($activity->getMovingTimeInSeconds() / 60),
                'distanceInKm' => round($activity->getDistance()->toFloat(), 1),
                'averageHeartRate' => $activity->getAverageHeartRate(),
            ];
        }
        usort($recent, static fn (array $a, array $b): int => $a['daysAgo'] <=> $b['daysAgo']);

        $lastActivity = $this->activityRepository->findMostRecent(1)->getFirst();

        return [
            'today' => $now->format('l'),
            // null when the user did not set training days.
            'todayIsAPlannedRestDay' => $general->isPlannedRestDay($now),
            'plannedTrainingDays' => $general->getTrainingDays(),
            'daysSinceLastActivity' => null === $lastActivity ? null : (int) SerializableDateTime::fromString($lastActivity->getStartDate()->format('Y-m-d'))->diff(SerializableDateTime::fromString($now->format('Y-m-d')))->days,
            // Per day, about 100 is an hour at threshold effort; 0 is a day without training.
            'trainingLoadPerDay' => $loadPerDay,
            'activitiesLast7Days' => $recent,
        ];
    }

    /**
     * @param array<string, mixed> $facts
     */
    public static function hasHealthData(array $facts): bool
    {
        $values = [...array_values(array_filter((array) $facts['today'], static fn (mixed $value): bool => !is_array($value))), ...array_values((array) $facts['lastNight'])];

        return [] !== array_filter($values, static fn (mixed $value): bool => null !== $value)
            || (is_array($facts['last7Days']['metrics'] ?? null) && [] !== array_filter($facts['last7Days']['metrics'], static fn (mixed $metric): bool => is_array($metric) && ($metric['daysMeasured'] ?? 0) > 0));
    }
}
