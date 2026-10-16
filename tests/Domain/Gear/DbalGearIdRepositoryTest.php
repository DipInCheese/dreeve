<?php

namespace App\Tests\Domain\Gear;

use App\Domain\Gear\DbalGearIdRepository;
use App\Domain\Gear\GearId;
use App\Domain\Gear\GearIdRepository;
use App\Domain\Gear\GearRepository;
use App\Tests\ContainerTestCase;

class DbalGearIdRepositoryTest extends ContainerTestCase
{
    private GearIdRepository $gearIdRepository;

    public function testFindAllAndFindRetired(): void
    {
        $this->assertEmpty($this->gearIdRepository->findAll()->toArray());
        $this->assertEmpty($this->gearIdRepository->findRetired()->toArray());

        $gearRepository = $this->getContainer()->get(GearRepository::class);
        $gearRepository->add(
            GearBuilder::fromDefaults()
                ->withGearId(GearId::fromUnprefixed(1))
                ->withIsRetired(false)
                ->build()
        );
        $gearRepository->add(
            GearBuilder::fromDefaults()
                ->withGearId(GearId::fromUnprefixed(2))
                ->withIsRetired(true)
                ->build()
        );

        $this->assertEquals(
            [GearId::fromUnprefixed(1), GearId::fromUnprefixed(2)],
            $this->gearIdRepository->findAll()->toArray()
        );
        $this->assertEquals(
            [GearId::fromUnprefixed(2)],
            $this->gearIdRepository->findRetired()->toArray()
        );
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->gearIdRepository = new DbalGearIdRepository(
            $this->getConnection(),
        );
    }
}
