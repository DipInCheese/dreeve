<?php

declare(strict_types=1);

namespace App\Domain\Insight;

/**
 * The model could not be reached or did not answer usefully, for example because the PC running it
 * is switched off. Trying again later is fine.
 */
final class InsightProviderUnavailable extends \RuntimeException
{
}
