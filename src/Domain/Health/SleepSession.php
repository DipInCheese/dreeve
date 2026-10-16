<?php

declare(strict_types=1);

namespace App\Domain\Health;

use Doctrine\ORM\Mapping as ORM;

/**
 * The main sleep of a night, stored on the day it ended.
 */
#[ORM\Entity]
#[ORM\Table(name: 'SleepSession')]
final readonly class SleepSession
{
    public function __construct(
        #[ORM\Id, ORM\Column(type: 'string')]
        public string $day,
        #[ORM\Column(type: 'string')]
        public string $source,
        #[ORM\Column(type: 'string', nullable: true)]
        public ?string $startsAt = null,
        #[ORM\Column(type: 'string', nullable: true)]
        public ?string $endsAt = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $sleepSeconds = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $deepSleepSeconds = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $lightSleepSeconds = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $remSleepSeconds = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $awakeSeconds = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $score = null,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $averageRespiration = null,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $averageHrv = null,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $averageSpo2 = null,
    ) {
    }
}
