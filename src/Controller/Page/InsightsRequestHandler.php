<?php

declare(strict_types=1);

namespace App\Controller\Page;

use App\Application\Navigation\NavigationSection;
use App\Application\PageRenderer;
use App\Domain\Health\Snapshot\HealthSnapshotBuilder;
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
 * Your last week at a glance: every number next to your normal and the week before, how you slept
 * and what you trained. The AI analysis builds on the same summary later.
 */
#[AsController]
final readonly class InsightsRequestHandler
{
    public function __construct(
        private Environment $twig,
        private PageRenderer $pageRenderer,
        private HealthSnapshotBuilder $healthSnapshotBuilder,
        private Clock $clock,
    ) {
    }

    #[Route(path: '/insights', name: 'insights', methods: ['GET'])]
    public function handle(): HtmlResponse
    {
        return $this->pageRenderer->render(
            cacheability: Cacheability::for(
                cacheKey: 'insights',
                cacheTags: CacheTags::of(RootCacheTag::HEALTH, RootCacheTag::ACTIVITIES, RootCacheTag::SETTINGS_INTEGRATIONS),
                cacheContexts: CacheContexts::of(AuthenticatedCacheContext::class),
                ttlInSeconds: $this->clock->getCurrentDateTimeImmutable()->getSecondsUntilMidnight(),
            ),
            render: fn (): string => $this->twig->load('html/insights.html.twig')->render([
                'snapshot' => $this->healthSnapshotBuilder->lastDays(7, $this->clock->getCurrentDateTimeImmutable()),
            ]),
            navigationSection: NavigationSection::INSIGHTS,
            requiresActivities: false,
        );
    }
}
