<?php

declare(strict_types=1);

namespace App\Infrastructure\HealthSync;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How far back the first sync reaches.
 */
enum GarminHistory: string implements TranslatableInterface
{
    case THREE_MONTHS = '90d';
    case ONE_YEAR = '365d';
    case EVERYTHING = 'all';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return match ($this) {
            self::THREE_MONTHS => $translator->trans('The last 3 months', domain: 'admin', locale: $locale),
            self::ONE_YEAR => $translator->trans('The last year', domain: 'admin', locale: $locale),
            self::EVERYTHING => $translator->trans('Everything', domain: 'admin', locale: $locale),
        };
    }
}
