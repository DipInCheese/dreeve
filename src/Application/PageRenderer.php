<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Navigation\NavigationSection;
use App\Application\OpenGraph\OpenGraph;
use App\Domain\Activity\ActivityIdRepository;
use App\Infrastructure\Cache\Cacheability;
use App\Infrastructure\Cache\CacheableRenderer;
use App\Infrastructure\Http\HtmlResponse;
use App\Infrastructure\Http\PrivateNoStoreHtmlResponse;
use Twig\Environment;

final readonly class PageRenderer
{
    public function __construct(
        private CacheableRenderer $cacheableRenderer,
        private AppShell $appShell,
        private ActivityIdRepository $activityIdRepository,
        private Environment $twig,
    ) {
    }

    /**
     * @param \Closure(): ?string $render
     */
    public function render(
        Cacheability $cacheability,
        \Closure $render,
        ?NavigationSection $navigationSection,
        ?OpenGraph $openGraph = null,
    ): HtmlResponse {
        if (0 === $this->activityIdRepository->count()) {
            // Every page is built from activities: until there are any, show how to add them instead.
            return new PrivateNoStoreHtmlResponse($this->appShell->render(
                content: $this->twig->render('html/empty-state/no-activities.html.twig'),
                navigationSection: $navigationSection,
                openGraph: null,
            ));
        }

        $renderedContent = $this->cacheableRenderer->render(
            cacheability: $cacheability,
            render: $render,
        );

        $html = $this->appShell->render(
            content: $renderedContent->getContent() ?? '',
            navigationSection: $navigationSection,
            openGraph: $openGraph,
        );

        if ($cacheability->getCacheContexts()->isEmpty()) {
            return new HtmlResponse($html, headers: $renderedContent->getCacheHeaders());
        }

        return new PrivateNoStoreHtmlResponse($html, headers: $renderedContent->getCacheHeaders());
    }
}
