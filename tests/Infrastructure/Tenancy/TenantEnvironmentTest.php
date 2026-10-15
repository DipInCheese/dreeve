<?php

namespace App\Tests\Infrastructure\Tenancy;

use App\Infrastructure\Tenancy\TenantEnvironment;
use App\Infrastructure\Tenancy\Tenants;
use PHPUnit\Framework\TestCase;

class TenantEnvironmentTest extends TestCase
{
    private const array VARIABLES = ['DATABASE_DIRECTORY', 'ADMIN_USERNAME', 'APP_URL', 'STRAVA_CLIENT_ID', 'AI_API_KEY', 'DREEVE_USER'];

    /** @var array<string, mixed> */
    private array $originalEnv;
    /** @var array<string, mixed> */
    private array $originalServer;

    public function testActivate(): void
    {
        // Installation-wide values, e.g. from the root .env file.
        $_ENV['STRAVA_CLIENT_ID'] = $_SERVER['STRAVA_CLIENT_ID'] = 'installation-client';
        unset($_ENV['AI_API_KEY'], $_SERVER['AI_API_KEY']);

        $tenants = Tenants::discover(__DIR__.'/fixtures/valid');
        $tenantEnvironment = new TenantEnvironment($tenants);

        $tenantEnvironment->activate($tenants->findByName('alice'));
        $this->assertSame('alice', $_ENV['DREEVE_USER']);
        $this->assertSame('alice', $_SERVER['DREEVE_USER']);
        $this->assertSame('alice-client', $_ENV['STRAVA_CLIENT_ID']);
        $this->assertSame('alice-client', $_SERVER['STRAVA_CLIENT_ID']);
        $this->assertSame('alice-ai-key', $_SERVER['AI_API_KEY']);
        $this->assertSame(__DIR__.'/fixtures/valid/users/alice/storage/database', $_SERVER['DATABASE_DIRECTORY']);

        $tenantEnvironment->activate($tenants->findByName('bob'));
        $this->assertSame('bob', $_SERVER['DREEVE_USER']);
        $this->assertSame('bob-client', $_SERVER['STRAVA_CLIENT_ID']);
        $this->assertSame('/custom/bob/database', $_SERVER['DATABASE_DIRECTORY']);
        // Only alice sets an AI key, it must not leak into bob's requests.
        $this->assertArrayNotHasKey('AI_API_KEY', $_ENV);
        $this->assertArrayNotHasKey('AI_API_KEY', $_SERVER);

        $tenantEnvironment->activate($tenants->findByName('alice'));
        $this->assertSame('alice-ai-key', $_SERVER['AI_API_KEY']);
    }

    public function testActivateRestoresInstallationWideValues(): void
    {
        $_ENV['AI_API_KEY'] = $_SERVER['AI_API_KEY'] = 'installation-ai-key';

        $tenants = Tenants::discover(__DIR__.'/fixtures/valid');
        $tenantEnvironment = new TenantEnvironment($tenants);

        $tenantEnvironment->activate($tenants->findByName('alice'));
        $this->assertSame('alice-ai-key', $_SERVER['AI_API_KEY']);

        // Bob does not set an AI key, he falls back to the installation-wide one.
        $tenantEnvironment->activate($tenants->findByName('bob'));
        $this->assertSame('installation-ai-key', $_ENV['AI_API_KEY']);
        $this->assertSame('installation-ai-key', $_SERVER['AI_API_KEY']);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnv = array_intersect_key($_ENV, array_flip(self::VARIABLES));
        $this->originalServer = array_intersect_key($_SERVER, array_flip(self::VARIABLES));
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $variable) {
            unset($_ENV[$variable], $_SERVER[$variable]);
        }
        $_ENV = [...$_ENV, ...$this->originalEnv];
        $_SERVER = [...$_SERVER, ...$this->originalServer];

        parent::tearDown();
    }
}
