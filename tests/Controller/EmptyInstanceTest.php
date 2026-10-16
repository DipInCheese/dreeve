<?php

namespace App\Tests\Controller;

use App\Tests\Controller\Admin\AdminWebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * A fresh installation has no activities yet. Every page must still work: pages built
 * from activities show how to add them, instead of an error or a redirect elsewhere.
 */
class EmptyInstanceTest extends AdminWebTestCase
{
    public function testEveryPageWorksWithoutActivities(): void
    {
        $paths = $this->pathsWithoutParameters();
        $this->assertGreaterThan(30, count($paths), 'The crawl misses most pages.');

        $failures = [];
        foreach ($paths as $routeName => $path) {
            $this->client->request('GET', $path);
            $statusCode = $this->client->getResponse()->getStatusCode();
            if ($statusCode >= 500) {
                $failures[] = sprintf('%s (%s) answered %d', $routeName, $path, $statusCode);
            }
        }

        $this->assertSame([], $failures);
    }

    public function testPagesBuiltFromActivitiesExplainHowToAddThem(): void
    {
        $crawler = $this->client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('No activities yet', $crawler->filter('main')->text());
        $this->assertCount(1, $crawler->filter('main a[href="/admin/upload"]'));
        // The navigation stays available, nothing forces the visitor elsewhere.
        $this->assertCount(1, $crawler->filter('aside#drawer-navigation'));
    }

    /**
     * @return array<string, string>
     */
    private function pathsWithoutParameters(): array
    {
        /** @var RouterInterface $router */
        $router = $this->getContainer()->get(RouterInterface::class);

        $paths = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            $methods = $route->getMethods();
            if ([] !== $methods && !in_array('GET', $methods, true)) {
                continue;
            }
            if (str_starts_with($name, '_') || in_array($name, ['admin_logout'], true)) {
                continue;
            }
            $missingDefaults = array_diff($route->compile()->getPathVariables(), array_keys($route->getDefaults()));
            if ([] !== $missingDefaults) {
                continue;
            }
            $paths[$name] = $router->generate($name);
        }

        return $paths;
    }

    #[\Override]
    protected function shouldSeedActivity(): bool
    {
        return false;
    }
}
