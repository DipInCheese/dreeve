<?php

declare(strict_types=1);

namespace App\Domain\Health;

use App\Domain\Health\Baseline\Baseline;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A metric over time, with your normal range shaded behind it.
 */
final readonly class HealthChart
{
    /**
     * @param array<string, float|null> $series
     *
     * @return array<mixed>
     */
    public static function build(HealthMetric $metric, array $series, Baseline $baseline, TranslatorInterface $translator): array
    {
        $points = [];
        foreach ($series as $day => $value) {
            $points[] = [$day, null === $value ? null : round($value, $metric->decimals())];
        }
        $isBar = in_array($metric, [HealthMetric::STEPS, HealthMetric::INTENSITY_MINUTES], true);
        $unit = '' === $metric->unit() ? '' : ' '.$metric->unit();

        $line = [
            'name' => $metric->trans($translator),
            'type' => $isBar ? 'bar' : 'line',
            'color' => ['#F26722'],
            'connectNulls' => true,
            'smooth' => 0.35,
            // Smooth, but never dipping past the real values between two days.
            'smoothMonotone' => 'x',
            'showSymbol' => count($points) <= 31,
            'symbolSize' => 6,
            'lineStyle' => ['width' => 3, 'cap' => 'round', 'join' => 'round'],
            'data' => $points,
        ];
        if ($isBar) {
            // Bars catch the light at the top, like the glass around them.
            $line['itemStyle'] = ['borderRadius' => [8, 8, 0, 0], 'color' => [
                'type' => 'linear', 'x' => 0, 'y' => 0, 'x2' => 0, 'y2' => 1,
                'colorStops' => [['offset' => 0, 'color' => '#FFB07A'], ['offset' => 1, 'color' => '#D2561A']],
            ]];
        } else {
            // A soft glow under the line that fades out towards the axis.
            $line['areaStyle'] = ['color' => [
                'type' => 'linear', 'x' => 0, 'y' => 0, 'x2' => 0, 'y2' => 1,
                'colorStops' => [['offset' => 0, 'color' => 'rgba(242, 103, 34, 0.35)'], ['offset' => 1, 'color' => 'rgba(242, 103, 34, 0)']],
            ]];
        }
        if ($baseline->hasNormalRange()) {
            $line['markArea'] = [
                'silent' => true,
                'itemStyle' => ['color' => 'rgba(255, 200, 150, 0.14)'],
                'label' => ['position' => 'insideTopRight', 'color' => '#d8c8b8', 'fontSize' => 11, 'textBorderWidth' => 0],
                'data' => [[
                    ['name' => $translator->trans('Your normal'), 'yAxis' => $baseline->normalLow],
                    ['yAxis' => $baseline->normalHigh],
                ]],
            ];
        }

        return [
            'animation' => true,
            'backgroundColor' => null,
            'tooltip' => ['trigger' => 'axis'],
            'grid' => ['top' => '8%', 'left' => '3%', 'right' => '4%', 'bottom' => '3%', 'containLabel' => true],
            'xAxis' => [[
                'type' => 'time',
                'boundaryGap' => false,
                'axisTick' => ['show' => false],
                'splitLine' => ['show' => false],
            ]],
            'yAxis' => [[
                'type' => 'value',
                'scale' => !$isBar,
                'splitLine' => ['show' => true],
                'axisLabel' => ['formatter' => '{value}'.$unit],
            ]],
            'series' => [$line],
        ];
    }
}
