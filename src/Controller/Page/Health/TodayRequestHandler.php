<?php

declare(strict_types=1);

namespace App\Controller\Page\Health;

use App\Application\Navigation\NavigationSection;
use App\Application\PageRenderer;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Athlete\Weight\AthleteWeightHistoryProvider;
use App\Domain\Health\Baseline\BaselineCalculator;
use App\Domain\Health\HealthDataSet;
use App\Domain\Health\HealthMetric;
use App\Domain\Health\HealthRepository;
use App\Domain\Health\MetricOverview;
use App\Infrastructure\Cache\Cacheability;
use App\Infrastructure\Cache\Context\AuthenticatedCacheContext;
use App\Infrastructure\Cache\Context\CacheContexts;
use App\Infrastructure\Cache\Tag\CacheTags;
use App\Infrastructure\Cache\Tag\RootCacheTag;
use App\Infrastructure\Http\HtmlResponse;
use App\Infrastructure\Time\Clock\Clock;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * The home page: last night and today at a glance, each number next to your own normal.
 */
#[AsController]
final readonly class TodayRequestHandler
{
    public const array METRICS = [
        HealthMetric::SLEEP_DURATION,
        HealthMetric::BODY_BATTERY_HIGH,
        HealthMetric::HRV,
        HealthMetric::RESTING_HEART_RATE,
        HealthMetric::STRESS,
        HealthMetric::STEPS,
        HealthMetric::TRAINING_READINESS,
    ];

    public function __construct(
        private HealthRepository $healthRepository,
        private ActivityRepository $activityRepository,
        private AthleteWeightHistoryProvider $athleteWeightHistoryProvider,
        private Clock $clock,
        private Environment $twig,
        private PageRenderer $pageRenderer,
    ) {
    }

    #[Route(path: '/', name: 'home', methods: ['GET'])]
    #[Route(path: '/today', name: 'today', methods: ['GET'])]
    public function handle(): HtmlResponse
    {
        return $this->pageRenderer->render(
            cacheability: Cacheability::for(
                cacheKey: 'today',
                cacheTags: CacheTags::of(RootCacheTag::HEALTH, RootCacheTag::ACTIVITIES),
                cacheContexts: CacheContexts::of(AuthenticatedCacheContext::class),
                ttlInSeconds: $this->clock->getCurrentDateTimeImmutable()->getSecondsUntilMidnight(),
            ),
            render: fn (): string => $this->renderFor(),
            navigationSection: NavigationSection::TODAY,
            requiresActivities: false,
        );
    }

    private function renderFor(): string
    {
        $today = $this->clock->getCurrentDateTimeImmutable();
        $weights = $this->athleteWeightHistoryProvider->get()->findAll();
        $data = HealthDataSet::load(
            repository: $this->healthRepository,
            from: $today->modify(sprintf('-%d days', BaselineCalculator::HISTORY_DAYS + 7)),
            till: $today,
            weights: array_values($weights),
        );

        return $this->twig->load('html/health/today.html.twig')->render([
            'hasHealthData' => $this->healthRepository->hasHealthData(),
            'overviews' => array_map(static fn (HealthMetric $metric): MetricOverview => MetricOverview::for($metric, $data), self::METRICS),
            'sleep' => $data->sleepSessions[$today->format('Y-m-d')] ?? null,
            'latestActivity' => $this->activityRepository->findMostRecent(1)->getFirst(),
            'latestWeight' => [] === $weights ? null : reset($weights),
            'today' => $today,
        ]);
    }
}
