<?php

declare(strict_types=1);

namespace App\Domain\Health;

use App\Infrastructure\ValueObject\Time\SerializableDateTime;

interface HealthRepository
{
    public function saveSourceRecord(SourceRecord $sourceRecord): void;

    public function save(HealthRecords $records): void;

    public function saveBodyMeasurement(BodyMeasurement $bodyMeasurement): void;

    public function deleteBodyMeasurement(string $id): void;

    public function hasHealthData(): bool;

    /**
     * @return array<string, DailySummary> keyed by day, oldest first
     */
    public function findDailySummaries(SerializableDateTime $from, SerializableDateTime $till): array;

    /**
     * @return array<string, SleepSession> keyed by day, oldest first
     */
    public function findSleepSessions(SerializableDateTime $from, SerializableDateTime $till): array;

    /**
     * @return array<string, HrvSummary> keyed by day, oldest first
     */
    public function findHrvSummaries(SerializableDateTime $from, SerializableDateTime $till): array;

    /**
     * @return array<string, FitnessStatus> keyed by day, oldest first
     */
    public function findFitnessStatuses(SerializableDateTime $from, SerializableDateTime $till): array;

    /**
     * @return list<BodyMeasurement> oldest first
     */
    public function findBodyMeasurements(?SerializableDateTime $from = null, ?SerializableDateTime $till = null): array;

    public function findLatestBodyMeasurement(): ?BodyMeasurement;
}
