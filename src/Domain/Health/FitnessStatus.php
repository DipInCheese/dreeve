<?php

declare(strict_types=1);

namespace App\Domain\Health;

use Doctrine\ORM\Mapping as ORM;

/**
 * Fitness as Garmin estimates it for a day: VO2max and training readiness.
 */
#[ORM\Entity]
#[ORM\Table(name: 'FitnessStatus')]
final readonly class FitnessStatus
{
    public function __construct(
        #[ORM\Id, ORM\Column(type: 'string')]
        public string $day,
        #[ORM\Column(type: 'string')]
        public string $source,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $vo2MaxRunning = null,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $vo2MaxCycling = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $trainingReadiness = null,
        #[ORM\Column(type: 'string', nullable: true)]
        public ?string $trainingReadinessLevel = null,
    ) {
    }
}
