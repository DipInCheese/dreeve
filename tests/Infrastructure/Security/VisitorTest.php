<?php

namespace App\Tests\Infrastructure\Security;

use App\Infrastructure\Config\DemoMode;
use App\Infrastructure\Security\Visitor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\InMemoryUser;

class VisitorTest extends TestCase
{
    #[DataProvider('provideVisitors')]
    public function testItKnowsWhatTheVisitorMayDo(
        bool $loggedIn,
        bool $demoModeIsEnabled,
        bool $expectedIsAuthenticated,
        bool $expectedIsTrusted,
        bool $expectedCanDownloadOriginalFiles,
    ): void {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($loggedIn ? new InMemoryUser('admin', null, ['ROLE_ADMIN']) : null);

        $visitor = new Visitor($security, DemoMode::fromString($demoModeIsEnabled ? '1' : '0'));

        $this->assertSame($expectedIsAuthenticated, $visitor->isAuthenticated());
        $this->assertSame($expectedIsTrusted, $visitor->isTrusted());
        $this->assertSame($expectedCanDownloadOriginalFiles, $visitor->canDownloadOriginalFiles());
    }

    public static function provideVisitors(): \Generator
    {
        yield 'anonymous' => [false, false, false, true, false];
        yield 'anonymous, demo mode' => [false, true, false, false, false];
        yield 'logged in' => [true, false, true, true, true];
        yield 'logged in, demo mode' => [true, true, true, true, true];
    }
}
