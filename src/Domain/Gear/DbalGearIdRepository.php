<?php

declare(strict_types=1);

namespace App\Domain\Gear;

use App\Infrastructure\Repository\DbalRepository;

final readonly class DbalGearIdRepository extends DbalRepository implements GearIdRepository
{
    public function findAll(): GearIds
    {
        return $this->fetchGearIds('SELECT gearId FROM Gear');
    }

    public function findRetired(): GearIds
    {
        return $this->fetchGearIds('SELECT gearId FROM Gear WHERE isRetired = 1');
    }

    private function fetchGearIds(string $query): GearIds
    {
        return GearIds::fromArray(array_map(
            GearId::fromString(...),
            $this->connection->executeQuery($query)->fetchFirstColumn(),
        ));
    }
}
