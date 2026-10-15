<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

/**
 * Prepares everything a new user needs before the user's first request.
 */
interface UserProvisioner
{
    public function provision(string $name): void;
}
