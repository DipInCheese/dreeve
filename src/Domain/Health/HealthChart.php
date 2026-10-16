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
            'smooth' => false,
            'showSymbol' => count($points) <= 31,
            'symbolSize' => 5,
            'lineStyle' => ['width' => 2],
            'data' => $points,
        ];
        if ($baseline->hasNormalRange()) {
            $line['markArea'] = [
                'silent' => true,
                'itemStyle' => ['color' => 'rgba(34, 197, 94, 0.12)'],
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
                'splitLine' => ['show' => true, 'lineStyle' => ['color' => '#E0E6F1']],
            ]],
            'yAxis' => [[
                'type' => 'value',
                'scale' => !$isBar,
                'splitLine' => ['show' => false],
                'axisLabel' => ['formatter' => '{value}'.$unit],
            ]],
            'series' => [$line],
        ];
    }
}
