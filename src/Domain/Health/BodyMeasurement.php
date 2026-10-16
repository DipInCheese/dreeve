<?php

declare(strict_types=1);

namespace App\Domain\Health;

use Doctrine\ORM\Mapping as ORM;

/**
 * One weigh-in, from any source: Garmin, a smart scale, or typed in by hand. Each source keeps its
 * own id, so the same weigh-in arriving twice is stored once.
 */
#[ORM\Entity]
#[ORM\Table(name: 'BodyMeasurement')]
#[ORM\Index(name: 'BodyMeasurement_dayIndex', columns: ['day'])]
final readonly class BodyMeasurement
{
    public function __construct(
        #[ORM\Id, ORM\Column(type: 'string')]
        public string $id,
        #[ORM\Column(type: 'string')]
        public string $source,
        #[ORM\Column(type: 'string')]
        public string $day,
        #[ORM\Column(type: 'string')]
        public string $measuredOn,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $weightInKg = null,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $bmi = null,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $bodyFatPercentage = null,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $muscleMassInKg = null,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $boneMassInKg = null,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $bodyWaterPercentage = null,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $visceralFat = null,
    ) {
    }

    public static function idFor(string $source, string $externalId): string
    {
        return $source.':'.$externalId;
    }
}
