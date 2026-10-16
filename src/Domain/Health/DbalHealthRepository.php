<?php

declare(strict_types=1);

namespace App\Domain\Health;

use App\Infrastructure\Repository\DbalRepository;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\DBAL\ParameterType;

final readonly class DbalHealthRepository extends DbalRepository implements HealthRepository
{
    public function saveSourceRecord(SourceRecord $sourceRecord): void
    {
        $this->connection->executeStatement(
            'INSERT OR REPLACE INTO SourceRecord (id, source, kind, recordKey, receivedOn, payload)
             VALUES (:id, :source, :kind, :recordKey, :receivedOn, :payload)',
            get_object_vars($sourceRecord),
            ['payload' => ParameterType::LARGE_OBJECT],
        );
    }

    public function save(HealthRecords $records): void
    {
        $this->connection->transactional(function () use ($records): void {
            foreach ($records->getDailySummaries() as $dailySummary) {
                $this->replace('DailySummary', $dailySummary);
            }
            foreach ($records->getSleepSessions() as $sleepSession) {
                $this->replace('SleepSession', $sleepSession);
            }
            foreach ($records->getHrvSummaries() as $hrvSummary) {
                $this->replace('HrvSummary', $hrvSummary);
            }
            // Readiness and VO2max arrive separately: each only updates its own columns.
            foreach ($records->getTrainingReadiness() as $day => [$score, $level]) {
                $this->connection->executeStatement(
                    'INSERT INTO FitnessStatus (day, source, trainingReadiness, trainingReadinessLevel) VALUES (:day, :source, :score, :level)
                     ON CONFLICT (day) DO UPDATE SET trainingReadiness = excluded.trainingReadiness, trainingReadinessLevel = excluded.trainingReadinessLevel',
                    ['day' => $day, 'source' => 'garmin', 'score' => $score, 'level' => $level],
                );
            }
            foreach ($records->getVo2Max() as $day => [$running, $cycling]) {
                $this->connection->executeStatement(
                    'INSERT INTO FitnessStatus (day, source, vo2MaxRunning, vo2MaxCycling) VALUES (:day, :source, :running, :cycling)
                     ON CONFLICT (day) DO UPDATE SET vo2MaxRunning = COALESCE(excluded.vo2MaxRunning, vo2MaxRunning), vo2MaxCycling = COALESCE(excluded.vo2MaxCycling, vo2MaxCycling)',
                    ['day' => $day, 'source' => 'garmin', 'running' => $running, 'cycling' => $cycling],
                );
            }
            foreach ($records->getBodyMeasurements() as $bodyMeasurement) {
                $this->replace('BodyMeasurement', $bodyMeasurement);
            }
        });
    }

    public function saveBodyMeasurement(BodyMeasurement $bodyMeasurement): void
    {
        $this->replace('BodyMeasurement', $bodyMeasurement);
    }

    public function deleteBodyMeasurement(string $id): void
    {
        $this->connection->executeStatement('DELETE FROM BodyMeasurement WHERE id = :id', ['id' => $id]);
    }

    public function hasHealthData(): bool
    {
        return array_any(['DailySummary', 'SleepSession', 'HrvSummary', 'FitnessStatus'], fn (string $table): bool => false !== $this->connection->fetchOne(sprintf('SELECT 1 FROM %s LIMIT 1', $table)));
    }

    public function findDailySummaries(SerializableDateTime $from, SerializableDateTime $till): array
    {
        return $this->findPerDay('DailySummary', DailySummary::class, $from, $till);
    }

    public function findSleepSessions(SerializableDateTime $from, SerializableDateTime $till): array
    {
        return $this->findPerDay('SleepSession', SleepSession::class, $from, $till);
    }

    public function findHrvSummaries(SerializableDateTime $from, SerializableDateTime $till): array
    {
        return $this->findPerDay('HrvSummary', HrvSummary::class, $from, $till);
    }

    public function findFitnessStatuses(SerializableDateTime $from, SerializableDateTime $till): array
    {
        return $this->findPerDay('FitnessStatus', FitnessStatus::class, $from, $till);
    }

    public function findBodyMeasurements(?SerializableDateTime $from = null, ?SerializableDateTime $till = null): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM BodyMeasurement WHERE day >= :from AND day <= :till ORDER BY measuredOn ASC',
            ['from' => $from?->format('Y-m-d') ?? '0000-00-00', 'till' => $till?->format('Y-m-d') ?? '9999-99-99'],
        );

        return array_map(static fn (array $row): BodyMeasurement => new BodyMeasurement(...$row), $rows);
    }

    public function findLatestBodyMeasurement(): ?BodyMeasurement
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM BodyMeasurement WHERE weightInKg IS NOT NULL ORDER BY measuredOn DESC LIMIT 1');

        return false === $row ? null : new BodyMeasurement(...$row);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return array<string, T>
     */
    private function findPerDay(string $table, string $class, SerializableDateTime $from, SerializableDateTime $till): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT * FROM %s WHERE day >= :from AND day <= :till ORDER BY day ASC', $table),
            ['from' => $from->format('Y-m-d'), 'till' => $till->format('Y-m-d')],
        );

        $records = [];
        foreach ($rows as $row) {
            $records[(string) $row['day']] = new $class(...$row);
        }

        return $records;
    }

    private function replace(string $table, object $record): void
    {
        $values = get_object_vars($record);
        $this->connection->executeStatement(
            sprintf(
                'INSERT OR REPLACE INTO %s (%s) VALUES (%s)',
                $table,
                implode(', ', array_keys($values)),
                implode(', ', array_map(static fn (string $column): string => ':'.$column, array_keys($values))),
            ),
            $values,
        );
    }
}
