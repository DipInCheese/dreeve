<?php

declare(strict_types=1);

namespace App\Domain\Health;

/**
 * Weigh-ins in Dreeve's own format, from any source: a smart scale bridge (e.g. Eufy), a script,
 * or a home automation. Dropped as a JSON file in the user's health inbox:
 *
 *   {
 *     "source": "eufy",
 *     "kind": "body",
 *     "measurements": [
 *       {"id": "abc123", "measuredOn": "2026-10-17T07:02:00+02:00", "weightInKg": 72.4,
 *        "bmi": 22.1, "bodyFatPercentage": 18.2, "muscleMassInKg": 56.3, "boneMassInKg": 3.1,
 *        "bodyWaterPercentage": 57.9, "visceralFat": 7}
 *     ]
 *   }
 *
 * Everything but "id" and "measuredOn" is optional. The same id from the same source is stored once.
 */
final readonly class BodyMeasurementDelivery
{
    public const string KIND = 'body';
    private const string SOURCE_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/';

    public static function isSource(string $source): bool
    {
        return 1 === preg_match(self::SOURCE_PATTERN, $source);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function parse(array $payload): HealthRecords
    {
        $source = (string) ($payload['source'] ?? '');
        if (!self::isSource($source)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid source name', $source));
        }
        if (!is_array($payload['measurements'] ?? null)) {
            throw new \InvalidArgumentException('Expected a list of "measurements"');
        }

        $records = HealthRecords::empty();
        foreach ($payload['measurements'] as $measurement) {
            if (!is_array($measurement) || !is_scalar($measurement['id'] ?? null) || !is_string($measurement['measuredOn'] ?? null)) {
                continue;
            }
            try {
                $measuredOn = new \DateTimeImmutable($measurement['measuredOn']);
            } catch (\Exception) {
                continue;
            }

            $records->addBodyMeasurement(new BodyMeasurement(
                id: BodyMeasurement::idFor($source, (string) $measurement['id']),
                source: $source,
                day: $measuredOn->format('Y-m-d'),
                measuredOn: $measuredOn->format('Y-m-d H:i:s'),
                weightInKg: self::positive($measurement['weightInKg'] ?? null),
                bmi: self::positive($measurement['bmi'] ?? null),
                bodyFatPercentage: self::positive($measurement['bodyFatPercentage'] ?? null),
                muscleMassInKg: self::positive($measurement['muscleMassInKg'] ?? null),
                boneMassInKg: self::positive($measurement['boneMassInKg'] ?? null),
                bodyWaterPercentage: self::positive($measurement['bodyWaterPercentage'] ?? null),
                visceralFat: self::positive($measurement['visceralFat'] ?? null),
            ));
        }

        return $records;
    }

    private static function positive(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? ($value > 0 ? (float) $value : null) : null;
    }
}
