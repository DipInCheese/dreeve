<?php

declare(strict_types=1);

namespace App\Domain\Health\Baseline;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum Comparison: string implements TranslatableInterface
{
    case BELOW = 'below';
    case WITHIN = 'within';
    case ABOVE = 'above';
    case UNKNOWN = 'unknown';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return match ($this) {
            self::BELOW => $translator->trans('Below your normal', locale: $locale),
            self::WITHIN => $translator->trans('Within your normal', locale: $locale),
            self::ABOVE => $translator->trans('Above your normal', locale: $locale),
            self::UNKNOWN => $translator->trans('Not enough history yet', locale: $locale),
        };
    }

    /**
     * Whether being here is good news, bad news or neither, for a metric where higher is better or worse.
     */
    public function tone(Direction $direction): string
    {
        if (self::WITHIN === $this || self::UNKNOWN === $this || Direction::NEUTRAL === $direction) {
            return 'neutral';
        }
        $isHigher = self::ABOVE === $this;

        return $isHigher === (Direction::HIGHER_IS_BETTER === $direction) ? 'good' : 'bad';
    }
}
