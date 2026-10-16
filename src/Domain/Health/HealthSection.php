<?php

declare(strict_types=1);

namespace App\Domain\Health;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum HealthSection: string implements TranslatableInterface
{
    case SLEEP = 'sleep';
    case HEART = 'heart';
    case STRESS_AND_BODY_BATTERY = 'stress';
    case BODY = 'body';
    case FITNESS = 'fitness';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return match ($this) {
            self::SLEEP => $translator->trans('Sleep', locale: $locale),
            self::HEART => $translator->trans('Heart', locale: $locale),
            self::STRESS_AND_BODY_BATTERY => $translator->trans('Stress & Body Battery', locale: $locale),
            self::BODY => $translator->trans('Body', locale: $locale),
            self::FITNESS => $translator->trans('Fitness', locale: $locale),
        };
    }

    public function description(TranslatorInterface $translator): string
    {
        return match ($this) {
            self::SLEEP => $translator->trans('How long and how well you slept, night by night.'),
            self::HEART => $translator->trans('Resting heart rate and heart rate variability, measured overnight. They often change before you feel tired or ill.'),
            self::STRESS_AND_BODY_BATTERY => $translator->trans('Stress through the day, and the energy your body built up and used.'),
            self::BODY => $translator->trans('Weight and body composition, from Garmin, a smart scale or what you typed in.'),
            self::FITNESS => $translator->trans('Daily movement, VO2max and how ready you are to train.'),
        };
    }
}
