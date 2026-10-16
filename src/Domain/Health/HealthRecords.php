<?php

declare(strict_types=1);

namespace App\Domain\Health;

/**
 * Everything one delivery of a source contained, ready to be stored.
 */
final class HealthRecords
{
    /** @var array<string, DailySummary> */
    private array $dailySummaries = [];
    /** @var array<string, SleepSession> */
    private array $sleepSessions = [];
    /** @var array<string, HrvSummary> */
    private array $hrvSummaries = [];
    /** @var array<string, array{int, ?string}> */
    private array $trainingReadiness = [];
    /** @var array<string, array{?float, ?float}> */
    private array $vo2Max = [];
    /** @var array<string, BodyMeasurement> */
    private array $bodyMeasurements = [];

    public static function empty(): self
    {
        return new self();
    }

    public function addDailySummary(DailySummary $dailySummary): void
    {
        $this->dailySummaries[$dailySummary->day] = $dailySummary;
    }

    public function addSleepSession(SleepSession $sleepSession): void
    {
        $this->sleepSessions[$sleepSession->day] = $sleepSession;
    }

    public function addHrvSummary(HrvSummary $hrvSummary): void
    {
        $this->hrvSummaries[$hrvSummary->day] = $hrvSummary;
    }

    public function addTrainingReadiness(string $day, int $score, ?string $level): void
    {
        $this->trainingReadiness[$day] = [$score, $level];
    }

    public function addVo2Max(string $day, ?float $running, ?float $cycling): void
    {
        $this->vo2Max[$day] = [$running, $cycling];
    }

    public function addBodyMeasurement(BodyMeasurement $bodyMeasurement): void
    {
        $this->bodyMeasurements[$bodyMeasurement->id] = $bodyMeasurement;
    }

    /**
     * @return list<DailySummary>
     */
    public function getDailySummaries(): array
    {
        return array_values($this->dailySummaries);
    }

    /**
     * @return list<SleepSession>
     */
    public function getSleepSessions(): array
    {
        return array_values($this->sleepSessions);
    }

    /**
     * @return list<HrvSummary>
     */
    public function getHrvSummaries(): array
    {
        return array_values($this->hrvSummaries);
    }

    /**
     * @return array<string, array{int, ?string}> score and level per day
     */
    public function getTrainingReadiness(): array
    {
        return $this->trainingReadiness;
    }

    /**
     * @return array<string, array{?float, ?float}> running and cycling VO2max per day
     */
    public function getVo2Max(): array
    {
        return $this->vo2Max;
    }

    /**
     * @return list<BodyMeasurement>
     */
    public function getBodyMeasurements(): array
    {
        return array_values($this->bodyMeasurements);
    }

    public function isEmpty(): bool
    {
        return [] === $this->dailySummaries && [] === $this->sleepSessions && [] === $this->hrvSummaries
            && [] === $this->trainingReadiness && [] === $this->vo2Max && [] === $this->bodyMeasurements;
    }
}
