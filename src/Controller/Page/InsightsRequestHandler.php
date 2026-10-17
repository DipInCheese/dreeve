<?php

declare(strict_types=1);

namespace App\Controller\Page;

use App\Application\Navigation\NavigationSection;
use App\Application\PageRenderer;
use App\Infrastructure\Cache\Cacheability;
use App\Infrastructure\Cache\Context\AuthenticatedCacheContext;
use App\Infrastructure\Cache\Context\CacheContexts;
use App\Infrastructure\Cache\Tag\CacheTags;
use App\Infrastructure\Cache\Tag\RootCacheTag;
use App\Infrastructure\Http\HtmlResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Where the analysis of your health and training will live. For now it says what is coming.
 */
#[AsController]
final readonly class InsightsRequestHandler
{
    public function __construct(
        private Environment $twig,
        private PageRenderer $pageRenderer,
    ) {
    }

    #[Route(path: '/insights', name: 'insights', methods: ['GET'])]
    public function handle(): HtmlResponse
    {
        return $this->pageRenderer->render(
            cacheability: Cacheability::for(
                cacheKey: 'insights',
                cacheTags: CacheTags::of(RootCacheTag::SETTINGS_INTEGRATIONS),
                cacheContexts: CacheContexts::of(AuthenticatedCacheContext::class),
            ),
            render: fn (): string => $this->twig->load('html/insights.html.twig')->render(),
            navigationSection: NavigationSection::INSIGHTS,
            requiresActivities: false,
        );
    }
}
