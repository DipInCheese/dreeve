<?php

namespace App\Tests\Infrastructure\Tenancy;

use App\Infrastructure\Tenancy\UserProvisioner;

final class SpyUserProvisioner implements UserProvisioner
{
    /** @var string[] */
    public array $provisionedUsers = [];

    public function provision(string $name): void
    {
        $this->provisionedUsers[] = $name;
    }
}
