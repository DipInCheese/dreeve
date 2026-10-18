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
use App\Domain\Insight\Insight;
use App\Domain\Insight\InsightProvider;
use App\Domain\Insight\InsightRepository;
use App\Domain\Insight\Overview\Overview;
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
        private InsightRepository $insightRepository,
        private InsightProvider $insightProvider,
    ) {
    }

    #[Route(path: '/', name: 'home', methods: ['GET'])]
    #[Route(path: '/today', name: 'today', methods: ['GET'])]
    public function handle(): HtmlResponse
    {
        return $this->pageRenderer->render(
            cacheability: Cacheability::for(
                cacheKey: 'today',
                cacheTags: CacheTags::of(RootCacheTag::HEALTH, RootCacheTag::ACTIVITIES, RootCacheTag::INSIGHTS, RootCacheTag::SETTINGS_INTEGRATIONS),
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
            // The Body Battery card compares the day's peak with your normal; its big number is where you are now.
            'currentBodyBattery' => ($data->dailySummaries[$today->format('Y-m-d')] ?? null)?->bodyBatteryMostRecent,
            'latestActivity' => $this->activityRepository->findMostRecent(1)->getFirst(),
            'latestWeight' => [] === $weights ? null : reset($weights),
            'today' => $today,
            'overview' => $this->latestOverview($today),
        ]);
    }

    /**
     * The latest AI overview, when AI is on and it is from the last day: an older one says nothing about today.
     */
    private function latestOverview(\DateTimeImmutable $now): ?Overview
    {
        if (!$this->insightProvider->isEnabled() || !($insight = $this->insightRepository->findLatest(Overview::INSIGHT_TYPE)) instanceof Insight) {
            return null;
        }
        $overview = Overview::fromInsight($insight);

        return $overview instanceof Overview && $overview->writtenOn > $now->modify('-1 day') ? $overview : null;
    }
}
