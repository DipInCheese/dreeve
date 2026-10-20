<?php

declare(strict_types=1);

namespace App\Domain\Health;

use App\Domain\Health\Baseline\Direction;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every health number Dreeve shows: what it is called, its unit, whether higher is better, and
 * where in the health data it comes from.
 */
enum HealthMetric: string implements TranslatableInterface
{
    case SLEEP_DURATION = 'sleep-duration';
    case SLEEP_SCORE = 'sleep-score';
    case DEEP_SLEEP = 'deep-sleep';
    case REM_SLEEP = 'rem-sleep';
    case RESTING_HEART_RATE = 'resting-heart-rate';
    case HRV = 'hrv';
    case STRESS = 'stress';
    case BODY_BATTERY_HIGH = 'body-battery-high';
    case BODY_BATTERY_LOW = 'body-battery-low';
    case STEPS = 'steps';
    case INTENSITY_MINUTES = 'intensity-minutes';
    case SPO2 = 'spo2';
    case RESPIRATION = 'respiration';
    case VO2MAX_RUNNING = 'vo2max-running';
    case VO2MAX_CYCLING = 'vo2max-cycling';
    case TRAINING_READINESS = 'training-readiness';
    case WEIGHT = 'weight';
    case BODY_FAT = 'body-fat';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return match ($this) {
            self::SLEEP_DURATION => $translator->trans('Sleep', locale: $locale),
            self::SLEEP_SCORE => $translator->trans('Sleep score', locale: $locale),
            self::DEEP_SLEEP => $translator->trans('Deep sleep', locale: $locale),
            self::REM_SLEEP => $translator->trans('REM sleep', locale: $locale),
            self::RESTING_HEART_RATE => $translator->trans('Resting heart rate', locale: $locale),
            self::HRV => $translator->trans('HRV', locale: $locale),
            self::STRESS => $translator->trans('Stress', locale: $locale),
            self::BODY_BATTERY_HIGH => $translator->trans('Body Battery', locale: $locale),
            self::BODY_BATTERY_LOW => $translator->trans('Lowest Body Battery', locale: $locale),
            self::STEPS => $translator->trans('Steps', locale: $locale),
            self::INTENSITY_MINUTES => $translator->trans('Intensity minutes', locale: $locale),
            self::SPO2 => $translator->trans('Blood oxygen', locale: $locale),
            self::RESPIRATION => $translator->trans('Respiration', locale: $locale),
            self::VO2MAX_RUNNING => $translator->trans('VO2max running', locale: $locale),
            self::VO2MAX_CYCLING => $translator->trans('VO2max cycling', locale: $locale),
            self::TRAINING_READINESS => $translator->trans('Training readiness', locale: $locale),
            self::WEIGHT => $translator->trans('Weight', locale: $locale),
            self::BODY_FAT => $translator->trans('Body fat', locale: $locale),
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::SLEEP_DURATION, self::DEEP_SLEEP, self::REM_SLEEP => 'h',
            self::RESTING_HEART_RATE => 'bpm',
            self::HRV => 'ms',
            self::STEPS => '',
            self::INTENSITY_MINUTES => 'min',
            self::SPO2, self::BODY_FAT => '%',
            self::RESPIRATION => 'brpm',
            self::VO2MAX_RUNNING, self::VO2MAX_CYCLING => 'ml/kg/min',
            self::WEIGHT => 'kg',
            self::SLEEP_SCORE, self::STRESS, self::BODY_BATTERY_HIGH, self::BODY_BATTERY_LOW, self::TRAINING_READINESS => '',
        };
    }

    /**
     * The value as shown: durations as hours and minutes, everything else as a number.
     */
    public function format(?float $value): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($this->isDuration()) {
            $minutes = (int) round($value * 60);

            return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
        }

        return number_format($value, $this->decimals(), '.', ' ');
    }

    /**
     * The unit next to a formatted value: none for durations, which say it themselves.
     */
    public function displayUnit(): string
    {
        return $this->isDuration() ? '' : $this->unit();
    }

    public function isDuration(): bool
    {
        return in_array($this, [self::SLEEP_DURATION, self::DEEP_SLEEP, self::REM_SLEEP], true);
    }

    /**
     * The highest value the metric can have, for scores; null when there is no ceiling.
     */
    public function highestValue(): ?float
    {
        return match ($this) {
            self::SLEEP_SCORE, self::STRESS, self::BODY_BATTERY_HIGH, self::BODY_BATTERY_LOW, self::TRAINING_READINESS => 100.0,
            default => null,
        };
    }

    public function decimals(): int
    {
        return match ($this) {
            self::SLEEP_DURATION, self::DEEP_SLEEP, self::REM_SLEEP, self::WEIGHT, self::BODY_FAT, self::VO2MAX_RUNNING, self::VO2MAX_CYCLING, self::RESPIRATION => 1,
            default => 0,
        };
    }

    public function direction(): Direction
    {
        return match ($this) {
            self::RESTING_HEART_RATE, self::STRESS => Direction::LOWER_IS_BETTER,
            self::WEIGHT, self::BODY_FAT, self::RESPIRATION => Direction::NEUTRAL,
            default => Direction::HIGHER_IS_BETTER,
        };
    }

    public function section(): HealthSection
    {
        return match ($this) {
            self::SLEEP_DURATION, self::SLEEP_SCORE, self::DEEP_SLEEP, self::REM_SLEEP => HealthSection::SLEEP,
            self::RESTING_HEART_RATE, self::HRV, self::SPO2, self::RESPIRATION => HealthSection::HEART,
            self::STRESS, self::BODY_BATTERY_HIGH, self::BODY_BATTERY_LOW => HealthSection::STRESS_AND_BODY_BATTERY,
            self::WEIGHT, self::BODY_FAT => HealthSection::BODY,
            self::STEPS, self::INTENSITY_MINUTES, self::VO2MAX_RUNNING, self::VO2MAX_CYCLING, self::TRAINING_READINESS => HealthSection::FITNESS,
        };
    }

    /**
     * @return array<string, float|null> one value per day of the data set
     */
    public function seriesFrom(HealthDataSet $data): array
    {
        $series = [];
        foreach ($data->days() as $day) {
            $series[$day] = $this->valueOn($day, $data);
        }

        return $series;
    }

    private function valueOn(string $day, HealthDataSet $data): ?float
    {
        $summary = $data->dailySummaries[$day] ?? null;
        $sleep = $data->sleepSessions[$day] ?? null;
        $fitness = $data->fitnessStatuses[$day] ?? null;

        $value = match ($this) {
            self::SLEEP_DURATION => self::hours($sleep?->sleepSeconds),
            self::SLEEP_SCORE => $sleep?->score,
            self::DEEP_SLEEP => self::hours($sleep?->deepSleepSeconds),
            self::REM_SLEEP => self::hours($sleep?->remSleepSeconds),
            self::RESTING_HEART_RATE => $summary?->restingHeartRate,
            self::HRV => $data->hrvSummaries[$day]->lastNightAverage ?? null,
            self::STRESS => $summary?->averageStress,
            self::BODY_BATTERY_HIGH => $summary?->bodyBatteryHighest,
            self::BODY_BATTERY_LOW => $summary?->bodyBatteryLowest,
            self::STEPS => $summary?->steps,
            self::INTENSITY_MINUTES => $summary?->getIntensityMinutes(),
            self::SPO2 => $sleep->averageSpo2 ?? $summary?->averageSpo2,
            self::RESPIRATION => $sleep->averageRespiration ?? $summary?->averageRespiration,
            self::VO2MAX_RUNNING => $fitness?->vo2MaxRunning,
            self::VO2MAX_CYCLING => $fitness?->vo2MaxCycling,
            self::TRAINING_READINESS => $fitness?->trainingReadiness,
            self::WEIGHT => $data->weightsPerDay[$day] ?? null,
            self::BODY_FAT => self::lastBodyFatOn($day, $data),
        };

        return null === $value ? null : (float) $value;
    }

    private static function hours(?int $seconds): ?float
    {
        return null === $seconds ? null : round($seconds / 3600, 2);
    }

    private static function lastBodyFatOn(string $day, HealthDataSet $data): ?float
    {
        $bodyFat = null;
        foreach ($data->bodyMeasurements as $bodyMeasurement) {
            if ($bodyMeasurement->day === $day && null !== $bodyMeasurement->bodyFatPercentage) {
                $bodyFat = $bodyMeasurement->bodyFatPercentage;
            }
        }

        return $bodyFat;
    }

    /**
     * @return list<self>
     */
    public static function forSection(HealthSection $section): array
    {
        return array_values(array_filter(self::cases(), static fn (self $metric): bool => $metric->section() === $section));
    }
}
