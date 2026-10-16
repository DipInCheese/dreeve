<?php

declare(strict_types=1);

namespace App\Domain\Health;

use Doctrine\ORM\Mapping as ORM;

/**
 * Heart rate variability measured overnight, with the status and balanced range Garmin gives it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'HrvSummary')]
final readonly class HrvSummary
{
    public function __construct(
        #[ORM\Id, ORM\Column(type: 'string')]
        public string $day,
        #[ORM\Column(type: 'string')]
        public string $source,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $lastNightAverage = null,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $weeklyAverage = null,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $lastNightHighest = null,
        #[ORM\Column(type: 'string', nullable: true)]
        public ?string $status = null,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $balancedLow = null,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $balancedHigh = null,
    ) {
    }
}
