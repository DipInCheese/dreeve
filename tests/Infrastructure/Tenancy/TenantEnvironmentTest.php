<?php

namespace App\Tests\Infrastructure\Tenancy;

use App\Infrastructure\Tenancy\Tenant;
use App\Infrastructure\Tenancy\TenantEnvironment;
use PHPUnit\Framework\TestCase;

class TenantEnvironmentTest extends TestCase
{
    private const array VARIABLES = ['DATABASE_DIRECTORY', 'ADMIN_USERNAME', 'ADMIN_PASSWORD_HASH', 'APP_URL', 'STRAVA_CLIENT_ID', 'AI_API_KEY', 'DREEVE_USER'];

    /** @var array<string, mixed> */
    private array $originalEnv;
    /** @var array<string, mixed> */
    private array $originalServer;

    public function testActivate(): void
    {
        // Installation-wide values, e.g. from the root .env file.
        $_ENV['STRAVA_CLIENT_ID'] = $_SERVER['STRAVA_CLIENT_ID'] = 'installation-client';
        $_ENV['AI_API_KEY'] = $_SERVER['AI_API_KEY'] = 'installation-ai-key';

        $alice = Tenant::fromEnvironment('alice', '/users/alice', ['APP_URL' => 'https://alice.example.com', 'STRAVA_CLIENT_ID' => 'alice-client', 'AI_API_KEY' => 'alice-ai-key']);
        $bob = Tenant::fromEnvironment('bob', '/users/bob', ['APP_URL' => 'https://bob.example.com', 'STRAVA_CLIENT_ID' => 'bob-client']);
        $tenantEnvironment = new TenantEnvironment();

        $tenantEnvironment->activate($alice);
        $this->assertSame('alice', $_ENV['DREEVE_USER']);
        $this->assertSame('alice', $_SERVER['DREEVE_USER']);
        $this->assertSame('alice-client', $_SERVER['STRAVA_CLIENT_ID']);
        $this->assertSame('alice-ai-key', $_SERVER['AI_API_KEY']);
        $this->assertSame('alice', $_SERVER['ADMIN_USERNAME']);
        $this->assertSame('/users/alice/storage/database', $_SERVER['DATABASE_DIRECTORY']);

        $tenantEnvironment->activate($bob);
        $this->assertSame('bob', $_SERVER['DREEVE_USER']);
        $this->assertSame('bob-client', $_ENV['STRAVA_CLIENT_ID']);
        // Alice's AI key does not leak into Bob's requests: Bob gets the installation-wide one.
        $this->assertSame('installation-ai-key', $_ENV['AI_API_KEY']);
        $this->assertSame('installation-ai-key', $_SERVER['AI_API_KEY']);
    }

    public function testActivateRemovesVariablesWithoutInstallationWideValue(): void
    {
        unset($_ENV['AI_API_KEY'], $_SERVER['AI_API_KEY']);
        $tenantEnvironment = new TenantEnvironment();

        $tenantEnvironment->activate(Tenant::fromEnvironment('alice', '/users/alice', ['AI_API_KEY' => 'alice-ai-key']));
        $this->assertSame('alice-ai-key', $_SERVER['AI_API_KEY']);

        $tenantEnvironment->activate(Tenant::fromEnvironment('bob', '/users/bob', []));
        $this->assertArrayNotHasKey('AI_API_KEY', $_ENV);
        $this->assertArrayNotHasKey('AI_API_KEY', $_SERVER);
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
