<?php

declare(strict_types=1);

namespace App\Domain\Health\Garmin;

use App\Domain\Health\BodyMeasurement;
use App\Domain\Health\DailySummary;
use App\Domain\Health\HealthRecords;
use App\Domain\Health\HrvSummary;
use App\Domain\Health\SleepSession;

/**
 * Turns the answers of Garmin Connect, as health-sync stored them, into health records. Garmin does
 * not document these payloads: every field is optional and anything unexpected is left out.
 */
final readonly class GarminHealthParser
{
    public const string SOURCE = 'garmin';

    /**
     * @param array<string, mixed> $payload
     */
    public function parse(array $payload): HealthRecords
    {
        $records = HealthRecords::empty();

        if ('day' === ($payload['kind'] ?? null) && is_string($day = $payload['date'] ?? null) && $this->isDay($day)) {
            $this->parseSummary($day, $this->map($payload['summary'] ?? null), $records);
            $this->parseSleep($day, $this->map($payload['sleep'] ?? null), $records);
            $this->parseHrv($day, $this->map($payload['hrv'] ?? null), $records);
            $this->parseReadiness($day, $payload['readiness'] ?? null, $records);
        }

        if ('range' === ($payload['kind'] ?? null)) {
            $this->parseMaxMetrics($payload['maxMetrics'] ?? null, $records);
            $this->parseBodyComposition($this->map($payload['bodyComposition'] ?? null), $records);
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function parseSummary(string $day, array $summary, HealthRecords $records): void
    {
        $dailySummary = new DailySummary(
            day: $day,
            source: self::SOURCE,
            steps: $this->int($summary['totalSteps'] ?? null),
            distanceInMeters: $this->int($summary['totalDistanceMeters'] ?? null),
            floorsAscended: self::float($summary['floorsAscended'] ?? null),
            activeKilocalories: $this->int($summary['activeKilocalories'] ?? null),
            totalKilocalories: $this->int($summary['totalKilocalories'] ?? null),
            moderateIntensityMinutes: $this->int($summary['moderateIntensityMinutes'] ?? null),
            vigorousIntensityMinutes: $this->int($summary['vigorousIntensityMinutes'] ?? null),
            restingHeartRate: $this->positiveInt($summary['restingHeartRate'] ?? null),
            minHeartRate: $this->positiveInt($summary['minHeartRate'] ?? null),
            maxHeartRate: $this->positiveInt($summary['maxHeartRate'] ?? null),
            // Garmin reports -1 and -2 when there was not enough data to measure stress.
            averageStress: $this->nonNegativeInt($summary['averageStressLevel'] ?? null),
            maxStress: $this->nonNegativeInt($summary['maxStressLevel'] ?? null),
            bodyBatteryHighest: $this->nonNegativeInt($summary['bodyBatteryHighestValue'] ?? null),
            bodyBatteryLowest: $this->nonNegativeInt($summary['bodyBatteryLowestValue'] ?? null),
            bodyBatteryCharged: $this->nonNegativeInt($summary['bodyBatteryChargedValue'] ?? null),
            bodyBatteryDrained: $this->nonNegativeInt($summary['bodyBatteryDrainedValue'] ?? null),
            bodyBatteryMostRecent: $this->nonNegativeInt($summary['bodyBatteryMostRecentValue'] ?? null),
            averageSpo2: $this->positiveFloat($summary['averageSpo2'] ?? null),
            lowestSpo2: $this->positiveInt($summary['lowestSpo2'] ?? null),
            averageRespiration: $this->positiveFloat($summary['avgWakingRespirationValue'] ?? null),
        );

        if (null !== $dailySummary->steps || null !== $dailySummary->restingHeartRate || null !== $dailySummary->bodyBatteryHighest || null !== $dailySummary->averageStress) {
            $records->addDailySummary($dailySummary);
        }
    }

    /**
     * @param array<string, mixed> $sleep
     */
    private function parseSleep(string $day, array $sleep, HealthRecords $records): void
    {
        $dto = $this->map($sleep['dailySleepDTO'] ?? null);
        $sleepSeconds = $this->positiveInt($dto['sleepTimeSeconds'] ?? null);
        if (null === $sleepSeconds) {
            return;
        }

        $records->addSleepSession(new SleepSession(
            day: $day,
            source: self::SOURCE,
            startsAt: $this->localTime($dto['sleepStartTimestampLocal'] ?? null),
            endsAt: $this->localTime($dto['sleepEndTimestampLocal'] ?? null),
            sleepSeconds: $sleepSeconds,
            deepSleepSeconds: $this->int($dto['deepSleepSeconds'] ?? null),
            lightSleepSeconds: $this->int($dto['lightSleepSeconds'] ?? null),
            remSleepSeconds: $this->int($dto['remSleepSeconds'] ?? null),
            awakeSeconds: $this->int($dto['awakeSleepSeconds'] ?? null),
            score: $this->positiveInt($this->map($this->map($dto['sleepScores'] ?? null)['overall'] ?? null)['value'] ?? null),
            averageRespiration: $this->positiveFloat($dto['averageRespirationValue'] ?? null),
            averageHrv: $this->positiveFloat($dto['avgSleepHRV'] ?? $sleep['avgOvernightHrv'] ?? null),
            averageSpo2: $this->positiveFloat($dto['avgSpO2'] ?? $dto['averageSpO2Value'] ?? null),
        ));
    }

    /**
     * @param array<string, mixed> $hrv
     */
    private function parseHrv(string $day, array $hrv, HealthRecords $records): void
    {
        $summary = $this->map($hrv['hrvSummary'] ?? null);
        $lastNight = $this->positiveFloat($summary['lastNightAvg'] ?? null);
        $weekly = $this->positiveFloat($summary['weeklyAvg'] ?? null);
        if (null === $lastNight && null === $weekly) {
            return;
        }
        $baseline = $this->map($summary['baseline'] ?? null);

        $records->addHrvSummary(new HrvSummary(
            day: $day,
            source: self::SOURCE,
            lastNightAverage: $lastNight,
            weeklyAverage: $weekly,
            lastNightHighest: $this->positiveFloat($summary['lastNight5MinHigh'] ?? null),
            status: is_string($summary['status'] ?? null) ? $summary['status'] : null,
            balancedLow: $this->positiveFloat($baseline['balancedLow'] ?? null),
            balancedHigh: $this->positiveFloat($baseline['balancedUpper'] ?? null),
        ));
    }

    private function parseReadiness(string $day, mixed $readiness, HealthRecords $records): void
    {
        if (!is_array($readiness)) {
            return;
        }
        // Several snapshots per day, one per wake-up or update: the latest one counts.
        $latest = null;
        foreach ($readiness as $snapshot) {
            $snapshot = $this->map($snapshot);
            if (null === $this->int($snapshot['score'] ?? null)) {
                continue;
            }
            if (null === $latest || (string) ($snapshot['timestamp'] ?? '') >= (string) ($latest['timestamp'] ?? '')) {
                $latest = $snapshot;
            }
        }
        if (null !== $latest) {
            $records->addTrainingReadiness($day, (int) $this->int($latest['score']), is_string($latest['level'] ?? null) ? $latest['level'] : null);
        }
    }

    private function parseMaxMetrics(mixed $maxMetrics, HealthRecords $records): void
    {
        if (!is_array($maxMetrics)) {
            return;
        }
        foreach ($maxMetrics as $entry) {
            $entry = $this->map($entry);
            $generic = $this->map($entry['generic'] ?? null);
            $cycling = $this->map($entry['cycling'] ?? null);
            $day = $generic['calendarDate'] ?? $cycling['calendarDate'] ?? $entry['calendarDate'] ?? null;
            if (!is_string($day) || !$this->isDay($day)) {
                continue;
            }
            $running = $this->positiveFloat($generic['vo2MaxPreciseValue'] ?? $generic['vo2MaxValue'] ?? null);
            $cyclingValue = $this->positiveFloat($cycling['vo2MaxPreciseValue'] ?? $cycling['vo2MaxValue'] ?? null);
            if (null !== $running || null !== $cyclingValue) {
                $records->addVo2Max($day, $running, $cyclingValue);
            }
        }
    }

    /**
     * @param array<string, mixed> $bodyComposition
     */
    private function parseBodyComposition(array $bodyComposition, HealthRecords $records): void
    {
        $weighIns = $bodyComposition['dateWeightList'] ?? null;
        if (!is_array($weighIns)) {
            return;
        }
        foreach ($weighIns as $weighIn) {
            $weighIn = $this->map($weighIn);
            // Garmin stores masses in grams.
            $weight = $this->grams($weighIn['weight'] ?? null);
            $measuredOn = $this->localTime($weighIn['date'] ?? null) ?? $this->utcTime($weighIn['timestampGMT'] ?? null);
            $day = is_string($weighIn['calendarDate'] ?? null) ? $weighIn['calendarDate'] : substr((string) $measuredOn, 0, 10);
            $externalId = (string) ($weighIn['samplePk'] ?? $weighIn['date'] ?? '');
            if (null === $weight || null === $measuredOn || '' === $externalId || !$this->isDay($day)) {
                continue;
            }

            $records->addBodyMeasurement(new BodyMeasurement(
                id: BodyMeasurement::idFor(self::SOURCE, $externalId),
                source: self::SOURCE,
                day: $day,
                measuredOn: $measuredOn,
                weightInKg: $weight,
                bmi: $this->positiveFloat($weighIn['bmi'] ?? null),
                bodyFatPercentage: $this->positiveFloat($weighIn['bodyFat'] ?? null),
                muscleMassInKg: $this->grams($weighIn['muscleMass'] ?? null),
                boneMassInKg: $this->grams($weighIn['boneMass'] ?? null),
                bodyWaterPercentage: $this->positiveFloat($weighIn['bodyWater'] ?? null),
                visceralFat: $this->positiveFloat($weighIn['visceralFat'] ?? null),
            ));
        }
    }

    private function isDay(string $value): bool
    {
        return 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function int(mixed $value): ?int
    {
        return is_int($value) || is_float($value) ? (int) round($value) : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        $int = $this->int($value);

        return null !== $int && $int > 0 ? $int : null;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        $int = $this->int($value);

        return null !== $int && $int >= 0 ? $int : null;
    }

    private static function float(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    private function positiveFloat(mixed $value): ?float
    {
        $float = self::float($value);

        return null !== $float && $float > 0 ? $float : null;
    }

    private function grams(mixed $value): ?float
    {
        $grams = $this->positiveFloat($value);

        return null === $grams ? null : round($grams / 1000, 2);
    }

    /**
     * Garmin writes local times as milliseconds since the epoch, as if local time were UTC.
     */
    private function localTime(mixed $value): ?string
    {
        $milliseconds = $this->int($value);

        return null === $milliseconds || $milliseconds <= 0 ? null : gmdate('Y-m-d H:i:s', intdiv($milliseconds, 1000));
    }

    private function utcTime(mixed $value): ?string
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }
        $timestamp = strtotime($value.(str_ends_with($value, 'Z') ? '' : 'Z'));

        return false === $timestamp ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }
}
