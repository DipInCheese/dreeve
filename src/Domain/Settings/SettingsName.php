<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Activity\Eddington\Config\EddingtonConfiguration;

enum SettingsName: string
{
    case MAX_HEART_RATE_FORMULA = 'maxHeartRateFormula';
    case RESTING_HEART_RATE_FORMULA = 'restingHeartRateFormula';
    case WEIGHT_HISTORY = 'weightHistory';
    case LOCALE = 'locale';
    case EDDINGTON = 'eddington';

    public function group(): SettingsGroup
    {
        return match ($this) {
            self::MAX_HEART_RATE_FORMULA, self::RESTING_HEART_RATE_FORMULA, self::WEIGHT_HISTORY => SettingsGroup::GENERAL,
            self::LOCALE => SettingsGroup::APPEARANCE,
            self::EDDINGTON => SettingsGroup::METRICS,
        };
    }

    public function defaultValue(): mixed
    {
        return match ($this) {
            self::MAX_HEART_RATE_FORMULA => 'fox',
            self::RESTING_HEART_RATE_FORMULA => 'heuristicAgeBased',
            self::EDDINGTON => EddingtonConfiguration::getDefaultConfig(),
            default => null,
        };
    }
}
