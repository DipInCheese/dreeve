<?php

namespace App\Tests\Domain\Activity;

use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\VelocityDistributionChart;
use App\Infrastructure\Measurement\UnitSystem;
use App\Infrastructure\Measurement\Velocity\KmPerHour;
use PHPUnit\Framework\TestCase;

class VelocityDistributionChartTest extends TestCase
{
    /**
     * Sail activities are shown in km/h like everything else: no knots.
     */
    public function testItMarksTheAverageInKmPerHourForSailActivities(): void
    {
        $chart = VelocityDistributionChart::create(
            velocityData: [16 => 60, 17 => 120, 18 => 200, 19 => 150, 20 => 90],
            averageSpeed: KmPerHour::from(18.52),
            sportType: SportType::SAIL,
            unitSystem: UnitSystem::METRIC,
        )->build();

        $this->assertNotNull($chart);
        $this->assertEqualsWithDelta(18.5, $chart['series'][0]['markPoint']['data'][0]['value'], 0.05);
    }

    public function testItMarksTheAverageInKmPerHourForRideActivities(): void
    {
        $chart = VelocityDistributionChart::create(
            velocityData: [28 => 60, 29 => 120, 30 => 200, 31 => 150, 32 => 90],
            averageSpeed: KmPerHour::from(30),
            sportType: SportType::RIDE,
            unitSystem: UnitSystem::METRIC,
        )->build();

        $this->assertNotNull($chart);
        $this->assertEquals(30.0, $chart['series'][0]['markPoint']['data'][0]['value']);
    }
}
