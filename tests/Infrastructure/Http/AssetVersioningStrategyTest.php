<?php

namespace App\Tests\Infrastructure\Http;

use App\Application\AppVersion;
use App\Infrastructure\Config\DevMode;
use App\Infrastructure\Http\AssetVersioningStrategy;
use App\Infrastructure\ValueObject\String\KernelProjectDir;
use App\Tests\Infrastructure\ValueObject\Identifier\FakeUuidFactory;
use PHPUnit\Framework\TestCase;

class AssetVersioningStrategyTest extends TestCase
{
    public function testApplyVersion(): void
    {
        $strategy = new AssetVersioningStrategy(DevMode::fromString('0'), new FakeUuidFactory());
        $version = AppVersion::getSemanticVersion();

        $this->assertEquals(
            '/test/file?'.$version,
            $strategy->applyVersion('/test/file')
        );

        $this->assertEquals(
            'test/file?'.$version,
            $strategy->applyVersion('test/file')
        );
    }

    public function testItShouldBeStableAcrossInstances(): void
    {
        $this->assertEquals(
            new AssetVersioningStrategy(DevMode::fromString('0'), new FakeUuidFactory())->applyVersion('/test/file'),
            new AssetVersioningStrategy(DevMode::fromString('0'), new FakeUuidFactory())->applyVersion('/test/file')
        );
    }

    public function testItShouldHandOutAFreshVersionInDevMode(): void
    {
        $this->assertEquals(
            '/test/file?'.new FakeUuidFactory()->random(),
            new AssetVersioningStrategy(DevMode::fromString('1'), new FakeUuidFactory())->applyVersion('/test/file')
        );
    }

    public function testTheVersionChangesWithTheBuiltFiles(): void
    {
        $projectDir = sys_get_temp_dir().'/asset-version-'.uniqid();
        mkdir($projectDir.'/public/css/dist', 0o777, true);
        file_put_contents($projectDir.'/public/css/dist/tailwind.min.css', 'a{}');
        $before = new AssetVersioningStrategy(DevMode::fromString('0'), new FakeUuidFactory(), KernelProjectDir::fromString($projectDir))->getVersion('');

        file_put_contents($projectDir.'/public/css/dist/tailwind.min.css', 'b{}');
        $after = new AssetVersioningStrategy(DevMode::fromString('0'), new FakeUuidFactory(), KernelProjectDir::fromString($projectDir))->getVersion('');

        $this->assertStringStartsWith(AppVersion::getSemanticVersion().'-', $before);
        $this->assertNotSame($before, $after);

        // A script loaded on demand, like the chat's, counts too.
        mkdir($projectDir.'/public/js/dist', 0o777, true);
        file_put_contents($projectDir.'/public/js/dist/chat.min.js', 'x');
        $withChat = new AssetVersioningStrategy(DevMode::fromString('0'), new FakeUuidFactory(), KernelProjectDir::fromString($projectDir))->getVersion('');
        $this->assertNotSame($after, $withChat);

        unlink($projectDir.'/public/js/dist/chat.min.js');
        unlink($projectDir.'/public/css/dist/tailwind.min.css');
    }
}
