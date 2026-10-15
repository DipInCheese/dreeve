<?php

namespace App\Tests\Infrastructure\Tenancy;

use App\Infrastructure\Tenancy\StravaConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StravaConnectionTest extends TestCase
{
    use ProvideTemporaryProjectDir;

    public function testApplicationOnlyChangesNothing(): void
    {
        $connection = StravaConnection::forApplication(' 12345 ', ' abcdef0123456789abcdef0123456789abcdef01 ');

        $this->assertSame('12345', $connection->getClientId());
        $this->assertSame('abcdef0123456789abcdef0123456789abcdef01', $connection->getClientSecret());
        $this->assertFalse($connection->isConnected());
        $this->assertSame([], $connection->toEnvironment());
    }

    public function testWriteAndRead(): void
    {
        $directory = $this->getUsersDir();
        $this->assertNull(StravaConnection::read($directory));

        StravaConnection::forApplication('12345', 'abcdef0123456789abcdef0123456789abcdef01')
            ->withAuthorization('the-refresh-token', 'Alice Example', new \DateTimeImmutable('2026-10-15 12:00:00'))
            ->write($directory);

        $this->assertFilePermissions(0o600, $directory.'/'.StravaConnection::FILE_NAME);

        $connection = StravaConnection::read($directory);
        $this->assertInstanceOf(StravaConnection::class, $connection);
        $this->assertTrue($connection->isConnected());
        $this->assertSame('Alice Example', $connection->getAthleteName());
        $this->assertSame('2026-10-15 12:00:00', $connection->getConnectedOn()?->format('Y-m-d H:i:s'));
        $this->assertSame(
            [
                'IMPORT_MODE' => 'stravaApi',
                'STRAVA_CLIENT_ID' => '12345',
                'STRAVA_CLIENT_SECRET' => 'abcdef0123456789abcdef0123456789abcdef01',
                'STRAVA_REFRESH_TOKEN' => 'the-refresh-token',
            ],
            $connection->toEnvironment()
        );
    }

    public function testImportRequestAndDelete(): void
    {
        $directory = $this->getUsersDir();
        StravaConnection::forApplication('12345', 'abcdef0123456789abcdef0123456789abcdef01')->write($directory);

        $this->assertFalse(StravaConnection::isImportRequested($directory));
        StravaConnection::requestImport($directory);
        $this->assertTrue(StravaConnection::isImportRequested($directory));

        StravaConnection::delete($directory);
        $this->assertNull(StravaConnection::read($directory));
        $this->assertFalse(StravaConnection::isImportRequested($directory));
    }

    public function testReadIgnoresInvalidFiles(): void
    {
        file_put_contents($this->getUsersDir().'/'.StravaConnection::FILE_NAME, '{"clientId": "not a number", "clientSecret": "x"}');
        $this->assertNull(StravaConnection::read($this->getUsersDir()));

        file_put_contents($this->getUsersDir().'/'.StravaConnection::FILE_NAME, '{broken');
        $this->assertNull(StravaConnection::read($this->getUsersDir()));
    }

    #[DataProvider('provideInvalidApplications')]
    public function testForApplicationItShouldThrow(string $clientId, string $clientSecret): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StravaConnection::forApplication($clientId, $clientSecret);
    }

    public static function provideInvalidApplications(): iterable
    {
        yield 'empty client id' => ['', 'abcdef0123456789abcdef0123456789abcdef01'];
        yield 'client id with letters' => ['12a45', 'abcdef0123456789abcdef0123456789abcdef01'];
        yield 'empty secret' => ['12345', ''];
        yield 'secret too short' => ['12345', 'abc'];
        yield 'secret with special characters' => ['12345', 'abcdef0123456789abcdef0123456789abcd"<x>'];
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->createProjectDir();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeProjectDir();
        parent::tearDown();
    }
}
