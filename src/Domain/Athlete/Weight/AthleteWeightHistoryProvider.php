<?php

declare(strict_types=1);

namespace App\Domain\Athlete\Weight;

use App\Domain\Health\HealthRepository;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\Measurement\UnitSystem;

/**
 * Your weight over time: the weights you typed in, combined with every weigh-in from Garmin or a
 * smart scale. On a day with both, the weigh-in counts.
 */
final readonly class AthleteWeightHistoryProvider
{
    public function __construct(
        private SettingsRepository $settingsRepository,
        private HealthRepository $healthRepository,
    ) {
    }

    public function get(): AthleteWeightHistory
    {
        $weights = [];
        foreach (AthleteWeightHistoryPayload::fromStoredValue($this->settingsRepository->general()->getWeightHistory())->toArray() as $entry) {
            $on = is_array($entry) && is_string($entry['on'] ?? null) ? $entry['on'] : '';
            $weights[$on ?: count($weights)] = $entry;
        }
        // Oldest first, so the last weigh-in of a day wins.
        foreach ($this->healthRepository->findBodyMeasurements() as $bodyMeasurement) {
            if (null !== $bodyMeasurement->weightInKg) {
                $weights[$bodyMeasurement->day] = ['on' => $bodyMeasurement->day, 'weight' => $bodyMeasurement->weightInKg];
            }
        }

        return AthleteWeightHistory::fromArray(array_values($weights), UnitSystem::METRIC);
    }
}
