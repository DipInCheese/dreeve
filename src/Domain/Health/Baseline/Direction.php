<?php

declare(strict_types=1);

namespace App\Domain\Health\Baseline;

enum Direction
{
    // HRV, sleep score, Body Battery, steps.
    case HIGHER_IS_BETTER;
    // Resting heart rate, stress.
    case LOWER_IS_BETTER;
    // Weight: neither by itself.
    case NEUTRAL;
}
