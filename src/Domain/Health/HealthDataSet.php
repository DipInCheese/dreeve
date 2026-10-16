<?php

declare(strict_types=1);

namespace App\Domain\Health;

use App\Domain\Athlete\Weight\AthleteWeight;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

/**
 * All health records of a period, loaded once, for metrics to read from.
 */
final readonly class HealthDataSet
{
    /**
     * @param array<string, DailySummary>  $dailySummaries
     * @param array<string, SleepSession>  $sleepSessions
     * @param array<string, HrvSummary>    $hrvSummaries
     * @param array<string, FitnessStatus> $fitnessStatuses
     * @param list<BodyMeasurement>        $bodyMeasurements
     * @param array<string, float>         $weightsPerDay    every known weight, typed in or measured
     */
    public function __construct(
        public SerializableDateTime $from,
        public SerializableDateTime $till,
        public array $dailySummaries,
        public array $sleepSessions,
        public array $hrvSummaries,
        public array $fitnessStatuses,
        public array $bodyMeasurements,
        public array $weightsPerDay,
    ) {
    }

    /**
     * @param list<AthleteWeight> $weights
     */
    public static function load(HealthRepository $repository, SerializableDateTime $from, SerializableDateTime $till, array $weights): self
    {
        $weightsPerDay = [];
        foreach ($weights as $weight) {
            $day = $weight->getOn()->format('Y-m-d');
            if ($day >= $from->format('Y-m-d') && $day <= $till->format('Y-m-d')) {
                $weightsPerDay[$day] = $weight->getWeightInKg()->toFloat();
            }
        }
        ksort($weightsPerDay);

        return new self(
            from: $from,
            till: $till,
            dailySummaries: $repository->findDailySummaries($from, $till),
            sleepSessions: $repository->findSleepSessions($from, $till),
            hrvSummaries: $repository->findHrvSummaries($from, $till),
            fitnessStatuses: $repository->findFitnessStatuses($from, $till),
            bodyMeasurements: $repository->findBodyMeasurements($from, $till),
            weightsPerDay: $weightsPerDay,
        );
    }

    /**
     * @return list<string> every day of the period, oldest first
     */
    public function days(): array
    {
        $days = [];
        for ($day = $this->from; $day->format('Y-m-d') <= $this->till->format('Y-m-d'); $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    public function latestBodyMeasurement(): ?BodyMeasurement
    {
        return [] === $this->bodyMeasurements ? null : array_last($this->bodyMeasurements);
    }
}
