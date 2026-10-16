<?php

namespace App\Tests\Domain\Health\Baseline;

use App\Domain\Health\Baseline\BaselineCalculator;
use App\Domain\Health\Baseline\Comparison;
use App\Domain\Health\Baseline\Direction;
use PHPUnit\Framework\TestCase;

class BaselineCalculatorTest extends TestCase
{
    public function testItComparesTheLatestValueWithYourNormal(): void
    {
        $values = [];
        for ($day = 1; $day <= 30; ++$day) {
            // Resting heart rate between 46 and 50.
            $values[sprintf('2026-09-%02d', $day)] = 46 + ($day % 5);
        }
        $values['2026-10-01'] = 58;

        $baseline = new BaselineCalculator()->calculate($values);

        $this->assertSame(58.0, $baseline->latest);
        $this->assertSame('2026-10-01', $baseline->latestDay);
        $this->assertSame(30, $baseline->daysOfHistory);
        $this->assertSame(46.5, $baseline->normalLow);
        $this->assertSame(49.5, $baseline->normalHigh);
        $this->assertSame(Comparison::ABOVE, $baseline->compare());
        $this->assertSame('bad', $baseline->compare()->tone(Direction::LOWER_IS_BETTER));
        $this->assertSame('good', $baseline->compare()->tone(Direction::HIGHER_IS_BETTER));
        $this->assertSame('neutral', $baseline->compare()->tone(Direction::NEUTRAL));
    }

    public function testTheSevenDayAverageCoversTheLastWeek(): void
    {
        $values = ['2026-09-20' => 100, '2026-09-25' => 10, '2026-09-30' => 20, '2026-10-01' => 30];

        $this->assertSame(20.0, new BaselineCalculator()->calculate($values)->sevenDayAverage);
    }

    public function testItNeedsTwoWeeksOfHistoryForANormalRange(): void
    {
        $values = [];
        for ($day = 1; $day <= 13; ++$day) {
            $values[sprintf('2026-09-%02d', $day)] = 50;
        }
        $values['2026-09-14'] = 70;

        $baseline = new BaselineCalculator()->calculate($values);

        $this->assertFalse($baseline->hasNormalRange());
        $this->assertSame(Comparison::UNKNOWN, $baseline->compare());
        $this->assertSame(70.0, $baseline->latest);
    }

    public function testASteadyMetricStillHasARange(): void
    {
        $values = [];
        for ($day = 1; $day <= 20; ++$day) {
            $values[sprintf('2026-09-%02d', $day)] = 50;
        }
        $values['2026-09-21'] = 50.5;

        $baseline = new BaselineCalculator()->calculate($values);

        $this->assertSame(48.5, $baseline->normalLow);
        $this->assertSame(51.5, $baseline->normalHigh);
        $this->assertSame(Comparison::WITHIN, $baseline->compare());
    }

    public function testMissingDaysAreIgnored(): void
    {
        $this->assertFalse(new BaselineCalculator()->calculate(['2026-09-01' => null])->hasLatest());
        $this->assertFalse(new BaselineCalculator()->calculate([])->hasLatest());
    }
}
