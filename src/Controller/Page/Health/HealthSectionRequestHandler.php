<?php

declare(strict_types=1);

namespace App\Controller\Page\Health;

use App\Application\Navigation\NavigationSection;
use App\Application\PageRenderer;
use App\Domain\Athlete\Weight\AthleteWeightHistoryProvider;
use App\Domain\Health\Baseline\BaselineCalculator;
use App\Domain\Health\HealthChart;
use App\Domain\Health\HealthDataSet;
use App\Domain\Health\HealthMetric;
use App\Domain\Health\HealthRepository;
use App\Domain\Health\HealthSection;
use App\Domain\Health\MetricOverview;
use App\Infrastructure\Cache\Cacheability;
use App\Infrastructure\Cache\Context\AuthenticatedCacheContext;
use App\Infrastructure\Cache\Context\CacheContexts;
use App\Infrastructure\Cache\Tag\CacheTags;
use App\Infrastructure\Cache\Tag\RootCacheTag;
use App\Infrastructure\Http\HtmlResponse;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Time\Clock\Clock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * One area of health (sleep, heart, ...): every metric of it over time, next to your normal.
 */
#[AsController]
final readonly class HealthSectionRequestHandler
{
    public const array RANGES = ['7d' => 7, '30d' => 30, '90d' => 90, '365d' => 365];
    private const string DEFAULT_RANGE = '30d';

    public function __construct(
        private HealthRepository $healthRepository,
        private AthleteWeightHistoryProvider $athleteWeightHistoryProvider,
        private Clock $clock,
        private TranslatorInterface $translator,
        private Environment $twig,
        private PageRenderer $pageRenderer,
    ) {
    }

    #[Route(path: '/health/{section}', name: 'health_section', requirements: ['section' => 'sleep|heart|stress|body|fitness'], methods: ['GET'])]
    public function handle(string $section, Request $request): HtmlResponse
    {
        $healthSection = HealthSection::tryFrom($section) ?? throw new NotFoundHttpException();
        $range = (string) $request->query->get('range', self::DEFAULT_RANGE);
        if (!array_key_exists($range, self::RANGES)) {
            $range = self::DEFAULT_RANGE;
        }

        return $this->pageRenderer->render(
            cacheability: Cacheability::for(
                cacheKey: sprintf('health.%s.%s', $healthSection->value, $range),
                cacheTags: CacheTags::of(RootCacheTag::HEALTH),
                cacheContexts: CacheContexts::of(AuthenticatedCacheContext::class),
                ttlInSeconds: $this->clock->getCurrentDateTimeImmutable()->getSecondsUntilMidnight(),
            ),
            render: fn (): string => $this->renderFor($healthSection, $range),
            navigationSection: NavigationSection::from('health-'.$healthSection->value),
            requiresActivities: false,
        );
    }

    private function renderFor(HealthSection $section, string $range): string
    {
        $today = $this->clock->getCurrentDateTimeImmutable();
        $days = self::RANGES[$range];
        // The history before the range is needed to know your normal at its start.
        $data = HealthDataSet::load(
            repository: $this->healthRepository,
            from: $today->modify(sprintf('-%d days', $days + BaselineCalculator::HISTORY_DAYS)),
            till: $today,
            weights: array_values($this->athleteWeightHistoryProvider->get()->findAll()),
        );
        $rangeStart = $today->modify(sprintf('-%d days', $days - 1))->format('Y-m-d');

        $metrics = [];
        foreach (HealthMetric::forSection($section) as $metric) {
            $overview = MetricOverview::for($metric, $data);
            $visible = array_filter($overview->series, static fn (string $day): bool => $day >= $rangeStart, ARRAY_FILTER_USE_KEY);
            if ([] === array_filter($visible, static fn (?float $value): bool => null !== $value)) {
                continue;
            }
            $metrics[] = [
                'overview' => $overview,
                'chart' => Json::encode(HealthChart::build($metric, $visible, $overview->baseline, $this->translator)),
            ];
        }

        return $this->twig->load('html/health/section.html.twig')->render([
            'section' => $section,
            'description' => $section->description($this->translator),
            'sections' => HealthSection::cases(),
            'metrics' => $metrics,
            'range' => $range,
            'ranges' => array_keys(self::RANGES),
            'hasHealthData' => $this->healthRepository->hasHealthData(),
        ]);
    }
}
