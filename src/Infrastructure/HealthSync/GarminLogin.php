<?php

declare(strict_types=1);

namespace App\Infrastructure\HealthSync;

enum GarminLogin
{
    case CONNECTED;
    case CODE_REQUIRED;
}
