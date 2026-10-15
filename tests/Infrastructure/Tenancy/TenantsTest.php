<?php

namespace App\Tests\Infrastructure\Tenancy;

use App\Infrastructure\Tenancy\InvalidTenant;
use App\Infrastructure\Tenancy\Tenant;
use App\Infrastructure\Tenancy\Tenants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TenantsTest extends TestCase
{
    public function testDiscover(): void
    {
        $tenants = Tenants::discover(__DIR__.'/fixtures/valid');

        $this->assertFalse($tenants->isEmpty());
        $this->assertCount(2, $tenants);
        $this->assertSame(['alice', 'bob'], $tenants->getNames());

        $alice = $tenants->findByName('alice');
        $this->assertSame('alice', $alice->getName());
        $this->assertSame('alice.example.com', $alice->getHost());
        $this->assertSame(__DIR__.'/fixtures/valid/users/alice', $alice->getDataDirectory());
        $this->assertSame(
            [
                'DATABASE_DIRECTORY' => __DIR__.'/fixtures/valid/users/alice/storage/database',
                'ADMIN_USERNAME' => 'alice',
                'APP_URL' => 'https://Alice.example.com',
                'STRAVA_CLIENT_ID' => 'alice-client',
                'AI_API_KEY' => 'alice-ai-key',
                'DREEVE_USER' => 'alice',
            ],
            $alice->getEnvironment()
        );

        // A database directory set by the user wins over the default one.
        $this->assertSame('/custom/bob/database', $tenants->findByName('bob')->getEnvironment()['DATABASE_DIRECTORY']);
    }

    public function testDiscoverWithoutUsersDirectory(): void
    {
        $tenants = Tenants::discover(__DIR__.'/fixtures/does-not-exist');

        $this->assertTrue($tenants->isEmpty());
        $this->assertCount(0, $tenants);
    }

    public function testDiscoverWithDuplicateHosts(): void
    {
        $this->expectExceptionObject(InvalidTenant::duplicateHost('same.example.com', 'alice', 'bob'));

        Tenants::discover(__DIR__.'/fixtures/duplicate-host');
    }

    public function testFindByHost(): void
    {
        $tenants = Tenants::discover(__DIR__.'/fixtures/valid');

        $this->assertSame('alice', $tenants->findByHost('alice.example.com')?->getName());
        $this->assertSame('alice', $tenants->findByHost('ALICE.example.com')?->getName());
        $this->assertSame('bob', $tenants->findByHost('bob.example.com')?->getName());
        $this->assertNull($tenants->findByHost('example.com'));
        $this->assertNull($tenants->findByHost('localhost'));
    }

    public function testFindByNameWhenUnknown(): void
    {
        $tenants = Tenants::discover(__DIR__.'/fixtures/valid');

        $this->expectExceptionObject(InvalidTenant::unknown('carol', $tenants));

        $tenants->findByName('carol');
    }

    public function testGetAllEnvironmentVariableNames(): void
    {
        $this->assertSame(
            ['DATABASE_DIRECTORY', 'ADMIN_USERNAME', 'APP_URL', 'STRAVA_CLIENT_ID', 'AI_API_KEY', 'DREEVE_USER'],
            Tenants::discover(__DIR__.'/fixtures/valid')->getAllEnvironmentVariableNames()
        );
    }

    public function testItShouldThrowWhenHostIsMissing(): void
    {
        $this->expectExceptionObject(InvalidTenant::missingHost('alice'));

        Tenants::fromArray([
            Tenant::fromEnvironment('alice', '/users/alice', ['STRAVA_CLIENT_ID' => 'alice-client']),
        ]);
    }

    #[DataProvider('provideInvalidNames')]
    public function testItShouldThrowOnInvalidName(string $name): void
    {
        $this->expectExceptionObject(InvalidTenant::invalidName($name));

        Tenant::fromEnvironment($name, '/users/'.$name, ['APP_URL' => 'https://example.com']);
    }

    public static function provideInvalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Alice'];
        yield 'starts with dash' => ['-alice'];
        yield 'contains dot' => ['al.ice'];
        yield 'contains slash' => ['al/ice'];
    }

    #[DataProvider('provideReservedVariables')]
    public function testItShouldThrowOnReservedVariable(string $variable): void
    {
        $this->expectExceptionObject(InvalidTenant::reservedVariable('alice', $variable));

        Tenant::fromEnvironment('alice', '/users/alice', ['APP_URL' => 'https://example.com', $variable => 'value']);
    }

    public static function provideReservedVariables(): iterable
    {
        yield 'user' => ['DREEVE_USER'];
        yield 'app env' => ['APP_ENV'];
        yield 'trusted proxies' => ['TRUSTED_PROXIES'];
    }

    public function testItShouldThrowWhenEnvFileIsMissing(): void
    {
        $directory = __DIR__.'/fixtures/valid/users/no-env-file';
        $this->expectExceptionObject(InvalidTenant::missingEnvFile('no-env-file', $directory.'/.env'));

        Tenant::fromDirectory($directory);
    }

    public function testEnsureDirectoriesExist(): void
    {
        $directory = sys_get_temp_dir().'/dreeve-tenant-test-'.bin2hex(random_bytes(5));
        $tenant = Tenant::fromEnvironment('alice', $directory, ['APP_URL' => 'https://example.com']);

        $tenant->ensureDirectoriesExist();
        // Running it twice must not fail.
        $tenant->ensureDirectoriesExist();

        foreach (['storage/database', 'storage/files', 'build', 'watch'] as $subDirectory) {
            $this->assertDirectoryExists($directory.'/'.$subDirectory);
            rmdir($directory.'/'.$subDirectory);
        }
        rmdir($directory.'/storage');
        rmdir($directory);
    }
}
