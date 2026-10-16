<?php

namespace App\Tests\Domain\Activity;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityName;
use App\Domain\Activity\ImportSource;
use App\Domain\Activity\Route\RouteGeography;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\WorkoutType;
use App\Domain\Activity\WorldType;
use App\Domain\Gear\GearId;
use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\Measurement\Length\Meter;
use App\Infrastructure\Measurement\Velocity\MetersPerSecond;
use App\Infrastructure\ValueObject\Geography\Coordinate;
use App\Infrastructure\ValueObject\Geography\Latitude;
use App\Infrastructure\ValueObject\Geography\Longitude;
use App\Infrastructure\ValueObject\String\ExternalReferenceId;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Infrastructure\ValueObject\Time\SerializableTimezone;

/**
 * Builds activities from the raw Strava API payloads the test fixtures were recorded with.
 * The app no longer imports from Strava, but activities imported before keep their data.
 */
final class RawStravaActivity
{
    /**
     * @param array<mixed> $rawData
     */
    public static function toActivity(array $rawData): Activity
    {
        $startDate = SerializableDateTime::createFromFormat(
            format: Activity::DATE_TIME_FORMAT,
            datetime: $rawData['start_date_local'],
            timezone: SerializableTimezone::default(),
        );

        $deviceName = $rawData['device_name'] ?? null;

        return Activity::create(
            activityId: ActivityId::fromUnprefixed((string) $rawData['id']),
            startDateTime: $startDate,
            sportType: SportType::from($rawData['sport_type']),
            worldType: WorldType::fromDeviceAndActivityName($deviceName, $rawData['name'] ?? ''),
            importSource: ImportSource::STRAVA_API,
            externalReferenceId: ExternalReferenceId::fromOptionalString($rawData['external_id'] ?? ''),
            name: ActivityName::fromString($rawData['name']),
            description: $rawData['description'],
            distance: Kilometer::from(round($rawData['distance'] / 1000, 3)),
            elevation: Meter::from(round($rawData['total_elevation_gain'])),
            startingCoordinate: Coordinate::createFromOptionalLatAndLng(
                Latitude::fromOptionalString(isset($rawData['start_latlng'][0]) ? (string) $rawData['start_latlng'][0] : null),
                Longitude::fromOptionalString(isset($rawData['start_latlng'][1]) ? (string) $rawData['start_latlng'][1] : null),
            ),
            calories: (int) ($rawData['calories'] ?? 0),
            kilojoules: isset($rawData['kilojoules']) ? (int) $rawData['kilojoules'] : null,
            averagePower: isset($rawData['average_watts']) ? (int) $rawData['average_watts'] : null,
            maxPower: isset($rawData['max_watts']) ? (int) $rawData['max_watts'] : null,
            averageSpeed: MetersPerSecond::from($rawData['average_speed'])->toKmPerHour(),
            maxSpeed: MetersPerSecond::from($rawData['max_speed'])->toKmPerHour(),
            averageHeartRate: isset($rawData['average_heartrate']) ? (int) round($rawData['average_heartrate']) : null,
            maxHeartRate: isset($rawData['max_heartrate']) ? (int) round($rawData['max_heartrate']) : null,
            averageCadence: isset($rawData['average_cadence']) ? (int) round($rawData['average_cadence']) : null,
            movingTimeInSeconds: $rawData['moving_time'] ?? 0,
            elapsedTimeInSeconds: $rawData['elapsed_time'] ?? 0,
            deviceName: $deviceName,
            connectedSensors: null,
            totalImageCount: $rawData['total_photo_count'] ?? 0,
            localImagePaths: [],
            polyline: $rawData['map']['summary_polyline'] ?? null,
            routeGeography: RouteGeography::create([]),
            weather: null,
            gearId: GearId::fromOptionalUnprefixed($rawData['gear_id'] ?? null),
            isCommute: $rawData['commute'] ?? false,
            isGroupActivity: ($rawData['athlete_count'] ?? 1) > 1,
            workoutType: self::workoutType($rawData['workout_type'] ?? null),
        );
    }

    private static function workoutType(?int $workoutType): ?WorkoutType
    {
        return match ($workoutType) {
            1, 11 => WorkoutType::RACE,
            3, 12 => WorkoutType::WORKOUT,
            2 => WorkoutType::LONG_RUN,
            // 0 and 10 are "none" in Strava.
            default => null,
        };
    }
}
