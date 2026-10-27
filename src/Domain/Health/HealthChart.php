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
            'color' => ['#E34902'],
            'connectNulls' => true,
            'smooth' => 0.3,
            'showSymbol' => count($points) <= 31,
            'symbolSize' => 5,
            'lineStyle' => ['width' => 2.5],
            'data' => $points,
        ];
        if (!$isBar) {
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
            'animation' => false,
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
