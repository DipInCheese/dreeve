<?php

namespace App\Tests\Infrastructure\Security;

use App\Infrastructure\Security\AppAccessMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;
use Symfony\Component\Security\Http\AccessMap;

class AppAccessMapTest extends TestCase
{
    public function testItLetsTheDecoratedMapDecideFirst(): void
    {
        $accessMap = new AccessMap();
        $accessMap->add(new PathRequestMatcher('^/admin'), ['ROLE_ADMIN']);

        $this->assertEquals([['ROLE_ADMIN'], null], new AppAccessMap($accessMap)->getPatterns(Request::create('/admin/settings')));
    }

    public function testItLeavesApiPathsToTheApiFirewall(): void
    {
        $accessMap = new AccessMap();
        $accessMap->add(new PathRequestMatcher('^/api/v1'), ['ROLE_API']);

        $this->assertEquals([['ROLE_API'], null], new AppAccessMap($accessMap)->getPatterns(Request::create('/api/v1/status')));
    }

    #[DataProvider('provideProtectedPaths')]
    public function testItAlwaysRequiresALogin(string $path): void
    {
        $this->assertEquals([['ROLE_ADMIN'], null], new AppAccessMap(new AccessMap())->getPatterns(Request::create($path)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideProtectedPaths(): iterable
    {
        yield 'dashboard' => ['/'];
        yield 'page' => ['/activities'];
        yield 'internal api' => ['/api/internal/dashboard/widget/dashboardWidget-introText'];
        yield 'gpx download' => ['/activities/activity-1/route.gpx'];
        yield 'original file download' => ['/activities/activity-1/original-file'];
        yield 'images' => ['/files/gear/bike.png'];
        yield 'badge' => ['/badge/zwift.svg'];
        yield 'ai chat' => ['/chat'];
        yield 'former strava oauth page' => ['/strava-oauth'];
        yield 'not an invite' => ['/invitee'];
        yield 'prefix of a public directory' => ['/assets-overview'];
    }

    #[DataProvider('providePublicPaths')]
    public function testItKeepsPublicPathsOpen(string $path): void
    {
        $this->assertEquals([null, null], new AppAccessMap(new AccessMap())->getPatterns(Request::create($path)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePublicPaths(): iterable
    {
        yield 'invite' => ['/invite/some-token'];
        yield 'manifest' => ['/manifest.json'];
        yield 'assets' => ['/assets/placeholder.webp'];
        yield 'css' => ['/css/dist/tailwind.min.css'];
        yield 'js' => ['/js/app.js'];
        yield 'libraries' => ['/libraries/leaflet/leaflet.js'];
    }
}
