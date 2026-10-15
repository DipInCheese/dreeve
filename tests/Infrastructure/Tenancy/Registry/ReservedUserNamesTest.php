<?php

namespace App\Tests\Infrastructure\Tenancy\Registry;

use App\Infrastructure\Tenancy\Registry\UserRegistry;
use App\Tests\ContainerTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * With an address per path (https://dreeve.example.com/{user}), a user name must never be the
 * first part of a URL of Dreeve itself. Fails when a new page is added without reserving its name.
 */
class ReservedUserNamesTest extends ContainerTestCase
{
    public function testEveryFirstPathSegmentOfARouteIsReserved(): void
    {
        /** @var RouterInterface $router */
        $router = $this->getContainer()->get('router');

        $missing = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            $firstSegment = explode('/', ltrim($route->getPath(), '/'))[0];
            if ('' === $firstSegment || str_starts_with($firstSegment, '{') || str_starts_with($firstSegment, '_')
                || !UserRegistry::isValidName($firstSegment)) {
                // The root, a placeholder, debug tools, or a name a user can not have anyway (e.g. "manifest.json").
                continue;
            }
            if (!UserRegistry::isReservedName($firstSegment)) {
                $missing[$firstSegment] = $name;
            }
        }

        $this->assertSame([], $missing, 'Add these to UserRegistry::RESERVED_NAMES');
    }

    public function testEveryPublicDirectoryIsReserved(): void
    {
        foreach (glob(dirname(__DIR__, 4).'/public/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $this->assertTrue(UserRegistry::isReservedName(basename($directory)), basename($directory));
        }
    }
}
