<?php

namespace App\Tests\Domain\Health\Garmin;

use App\Domain\Health\BodyMeasurement;
use App\Domain\Health\DailySummary;
use App\Domain\Health\Garmin\GarminHealthParser;
use App\Domain\Health\HrvSummary;
use App\Domain\Health\SleepSession;
use PHPUnit\Framework\TestCase;

class GarminHealthParserTest extends TestCase
{
    public static function day(): array
    {
        return [
            'source' => 'garmin',
            'kind' => 'day',
            'date' => '2026-10-16',
            'summary' => [
                'calendarDate' => '2026-10-16',
                'totalSteps' => 8421,
                'totalDistanceMeters' => 6532,
                'floorsAscended' => 7.5,
                'activeKilocalories' => 512.0,
                'totalKilocalories' => 2480.0,
                'moderateIntensityMinutes' => 20,
                'vigorousIntensityMinutes' => 15,
                'restingHeartRate' => 48,
                'minHeartRate' => 44,
                'maxHeartRate' => 168,
                'averageStressLevel' => 28,
                'maxStressLevel' => 91,
                'bodyBatteryHighestValue' => 92,
                'bodyBatteryLowestValue' => 18,
                'bodyBatteryChargedValue' => 64,
                'bodyBatteryDrainedValue' => 70,
                'bodyBatteryMostRecentValue' => 41,
                'averageSpo2' => 95.0,
                'lowestSpo2' => 88,
                'avgWakingRespirationValue' => 14.0,
            ],
            'sleep' => [
                'dailySleepDTO' => [
                    'calendarDate' => '2026-10-16',
                    'sleepTimeSeconds' => 27000,
                    'sleepStartTimestampLocal' => 1792101600000,
                    'sleepEndTimestampLocal' => 1792130400000,
                    'deepSleepSeconds' => 5400,
                    'lightSleepSeconds' => 14400,
                    'remSleepSeconds' => 7200,
                    'awakeSleepSeconds' => 1800,
                    'averageRespirationValue' => 13.5,
                    'avgSleepHRV' => 52.0,
                    'sleepScores' => ['overall' => ['value' => 81, 'qualifierKey' => 'GOOD']],
                ],
            ],
            'hrv' => [
                'hrvSummary' => [
                    'calendarDate' => '2026-10-16',
                    'weeklyAvg' => 55,
                    'lastNightAvg' => 52,
                    'lastNight5MinHigh' => 77,
                    'status' => 'BALANCED',
                    'baseline' => ['lowUpper' => 45, 'balancedLow' => 49, 'balancedUpper' => 63, 'markerValue' => 0.4],
                ],
            ],
            'readiness' => [
                ['calendarDate' => '2026-10-16', 'timestamp' => '2026-10-16T05:10:00.0', 'score' => 61, 'level' => 'MODERATE'],
                ['calendarDate' => '2026-10-16', 'timestamp' => '2026-10-16T07:30:00.0', 'score' => 74, 'level' => 'HIGH'],
            ],
        ];
    }

    public function testItParsesADay(): void
    {
        $records = new GarminHealthParser()->parse(self::day());

        $this->assertEquals([new DailySummary(
            day: '2026-10-16',
            source: 'garmin',
            steps: 8421,
            distanceInMeters: 6532,
            floorsAscended: 7.5,
            activeKilocalories: 512,
            totalKilocalories: 2480,
            moderateIntensityMinutes: 20,
            vigorousIntensityMinutes: 15,
            restingHeartRate: 48,
            minHeartRate: 44,
            maxHeartRate: 168,
            averageStress: 28,
            maxStress: 91,
            bodyBatteryHighest: 92,
            bodyBatteryLowest: 18,
            bodyBatteryCharged: 64,
            bodyBatteryDrained: 70,
            bodyBatteryMostRecent: 41,
            averageSpo2: 95.0,
            lowestSpo2: 88,
            averageRespiration: 14.0,
        )], $records->getDailySummaries());
        $this->assertSame(50, $records->getDailySummaries()[0]->getIntensityMinutes());

        $this->assertEquals([new SleepSession(
            day: '2026-10-16',
            source: 'garmin',
            startsAt: '2026-10-15 22:00:00',
            endsAt: '2026-10-16 06:00:00',
            sleepSeconds: 27000,
            deepSleepSeconds: 5400,
            lightSleepSeconds: 14400,
            remSleepSeconds: 7200,
            awakeSeconds: 1800,
            score: 81,
            averageRespiration: 13.5,
            averageHrv: 52.0,
        )], $records->getSleepSessions());

        $this->assertEquals([new HrvSummary(
            day: '2026-10-16',
            source: 'garmin',
            lastNightAverage: 52.0,
            weeklyAverage: 55.0,
            lastNightHighest: 77.0,
            status: 'BALANCED',
            balancedLow: 49.0,
            balancedHigh: 63.0,
        )], $records->getHrvSummaries());

        // The latest readiness snapshot of the day counts.
        $this->assertSame(['2026-10-16' => [74, 'HIGH']], $records->getTrainingReadiness());
    }

    public function testItLeavesOutWhatGarminDidNotMeasure(): void
    {
        $records = new GarminHealthParser()->parse([
            'source' => 'garmin',
            'kind' => 'day',
            'date' => '2026-10-16',
            // Stress -1 and -2 mean "not enough data"; no steps, no heart rate, no Body Battery.
            'summary' => ['averageStressLevel' => -1, 'maxStressLevel' => -2, 'restingHeartRate' => 0],
            'sleep' => ['dailySleepDTO' => ['sleepTimeSeconds' => null]],
            'hrv' => null,
            'readiness' => [],
        ]);

        $this->assertTrue($records->isEmpty());
    }

    public function testItParsesARange(): void
    {
        $records = new GarminHealthParser()->parse([
            'source' => 'garmin',
            'kind' => 'range',
            'start' => '2026-10-01',
            'end' => '2026-10-16',
            'maxMetrics' => [
                ['generic' => ['calendarDate' => '2026-10-14', 'vo2MaxPreciseValue' => 52.6, 'vo2MaxValue' => 53], 'cycling' => null],
                ['generic' => null, 'cycling' => ['calendarDate' => '2026-10-15', 'vo2MaxValue' => 55]],
            ],
            'bodyComposition' => [
                'dateWeightList' => [[
                    'samplePk' => 1792050000000,
                    'date' => 1792136400000,
                    'calendarDate' => '2026-10-16',
                    'weight' => 72450.0,
                    'bmi' => 22.4,
                    'bodyFat' => 15.2,
                    'bodyWater' => 60.1,
                    'boneMass' => 3200,
                    'muscleMass' => 34800,
                    'visceralFat' => null,
                ]],
            ],
        ]);

        $this->assertSame([
            '2026-10-14' => [52.6, null],
            '2026-10-15' => [null, 55.0],
        ], $records->getVo2Max());
        $this->assertEquals([new BodyMeasurement(
            id: 'garmin:1792050000000',
            source: 'garmin',
            day: '2026-10-16',
            measuredOn: '2026-10-16 07:40:00',
            weightInKg: 72.45,
            bmi: 22.4,
            bodyFatPercentage: 15.2,
            muscleMassInKg: 34.8,
            boneMassInKg: 3.2,
            bodyWaterPercentage: 60.1,
        )], $records->getBodyMeasurements());
    }

    public function testItIgnoresUnknownDeliveries(): void
    {
        $this->assertTrue(new GarminHealthParser()->parse(['kind' => 'day', 'date' => 'yesterday'])->isEmpty());
        $this->assertTrue(new GarminHealthParser()->parse(['kind' => 'something'])->isEmpty());
        $this->assertTrue(new GarminHealthParser()->parse([])->isEmpty());
    }
}
