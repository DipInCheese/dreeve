<?php

declare(strict_types=1);

namespace App\Domain\Health;

use Doctrine\ORM\Mapping as ORM;

/**
 * One day of all-day measurements: steps, heart rate, stress, Body Battery.
 */
#[ORM\Entity]
#[ORM\Table(name: 'DailySummary')]
final readonly class DailySummary
{
    public function __construct(
        #[ORM\Id, ORM\Column(type: 'string')]
        public string $day,
        #[ORM\Column(type: 'string')]
        public string $source,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $steps = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $distanceInMeters = null,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $floorsAscended = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $activeKilocalories = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $totalKilocalories = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $moderateIntensityMinutes = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $vigorousIntensityMinutes = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $restingHeartRate = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $minHeartRate = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $maxHeartRate = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $averageStress = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $maxStress = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $bodyBatteryHighest = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $bodyBatteryLowest = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $bodyBatteryCharged = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $bodyBatteryDrained = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $bodyBatteryMostRecent = null,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $averageSpo2 = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $lowestSpo2 = null,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $averageRespiration = null,
    ) {
    }

    public function getIntensityMinutes(): ?int
    {
        if (null === $this->moderateIntensityMinutes && null === $this->vigorousIntensityMinutes) {
            return null;
        }

        // Vigorous minutes count double, the way Garmin and the WHO guidelines count them.
        return ($this->moderateIntensityMinutes ?? 0) + 2 * ($this->vigorousIntensityMinutes ?? 0);
    }
}
