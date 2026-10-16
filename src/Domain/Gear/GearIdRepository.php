<?php

declare(strict_types=1);

namespace App\Domain\Gear;

interface GearIdRepository
{
    public function findAll(): GearIds;

    public function findRetired(): GearIds;
}
