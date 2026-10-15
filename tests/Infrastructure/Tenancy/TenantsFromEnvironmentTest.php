<?php

namespace App\Tests\Infrastructure\Tenancy;

use App\Infrastructure\Tenancy\InvalidTenant;
use App\Infrastructure\Tenancy\Tenant;
use App\Infrastructure\Tenancy\Tenants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TenantsFromEnvironmentTest extends TestCase
{
    private string $projectDir;

    public function testDiscoverFromEnvironment(): void
    {
        $tenants = Tenants::discover($this->projectDir, [
            'DREEVE_USERS' => 'alice, bob-smith',
            'ALICE_URL' => 'https://alice.example.com',
            'ALICE_PASSWORD' => 'alice-long-password',
            'ALICE_STRAVA_CLIENT_ID' => 'alice-client',
            'BOB_SMITH_URL' => 'https://bob.example.com',
            'BOB_SMITH_USERNAME' => 'bob',
            'BOB_SMITH_ADMIN_PASSWORD_HASH' => '$2y$04$existing-hash',
            // Installation-wide settings are not picked up as user settings.
            'TZ' => 'Europe/Brussels',
            'STRAVA_CLIENT_ID' => 'installation-client',
            'argv' => ['bin/console'],
        ]);

        $this->assertSame(['alice', 'bob-smith'], $tenants->getNames());

        $alice = $tenants->findByName('alice');
        $this->assertSame('alice.example.com', $alice->getHost());
        $this->assertSame($this->projectDir.'/users/alice', $alice->getDataDirectory());
        $this->assertSame(
            [
                'DATABASE_DIRECTORY' => $this->projectDir.'/users/alice/storage/database',
                'ADMIN_USERNAME' => 'alice',
                'APP_URL' => 'https://alice.example.com',
                'STRAVA_CLIENT_ID' => 'alice-client',
                'DREEVE_USER' => 'alice',
            ],
            $alice->getEnvironment()
        );

        $bob = $tenants->findByName('bob-smith');
        $this->assertSame('bob', $bob->getEnvironment()['ADMIN_USERNAME']);
        $this->assertSame('$2y$04$existing-hash', $bob->getEnvironment()['ADMIN_PASSWORD_HASH']);
    }

    public function testEnvironmentWinsOverUserEnvFile(): void
    {
        mkdir($this->projectDir.'/users/alice', 0o777, true);
        file_put_contents($this->projectDir.'/users/alice/.env', "APP_URL=https://old.example.com\nAI_API_KEY=from-env-file\n");

        $alice = Tenants::discover($this->projectDir, [
            'DREEVE_USERS' => 'alice',
            'ALICE_URL' => 'https://alice.example.com',
        ])->findByName('alice');

        $this->assertSame('https://alice.example.com', $alice->getEnvironment()['APP_URL']);
        $this->assertSame('from-env-file', $alice->getEnvironment()['AI_API_KEY']);
    }

    public function testCombinesEnvironmentAndDirectoryUsers(): void
    {
        mkdir($this->projectDir.'/users/carol', 0o777, true);
        file_put_contents($this->projectDir.'/users/carol/.env', "APP_URL=https://carol.example.com\n");

        $tenants = Tenants::discover($this->projectDir, [
            'DREEVE_USERS' => 'alice',
            'ALICE_URL' => 'https://alice.example.com',
        ]);

        $this->assertSame(['alice', 'carol'], $tenants->getNames());
    }

    public function testWithSecrets(): void
    {
        $tenants = Tenants::discover($this->projectDir, [
            'DREEVE_USERS' => 'alice',
            'ALICE_URL' => 'https://alice.example.com',
            'ALICE_PASSWORD' => 'alice-long-password',
        ]);

        $alice = $tenants->withSecrets()->findByName('alice');
        $environment = $alice->getEnvironment();

        // The app secret is generated and stored, only readable by the owner.
        $secretFile = $this->projectDir.'/users/alice/'.Tenant::APP_SECRET_FILE_NAME;
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $environment['APP_SECRET']);
        $this->assertSame($environment['APP_SECRET'], trim((string) file_get_contents($secretFile)));
        $this->assertFilePermissions(0o600, $secretFile);

        // The password is hashed, the plain password is nowhere in the environment.
        $hashFile = $this->projectDir.'/users/alice/'.Tenant::ADMIN_PASSWORD_HASH_FILE_NAME;
        $this->assertTrue(password_verify('alice-long-password', $environment['ADMIN_PASSWORD_HASH']));
        $this->assertSame($environment['ADMIN_PASSWORD_HASH'], trim((string) file_get_contents($hashFile)));
        $this->assertFilePermissions(0o600, $hashFile);
        $this->assertNotContains('alice-long-password', $environment);
        $this->assertArrayNotHasKey('PASSWORD', $environment);

        // The data directories are created.
        $this->assertDirectoryExists($this->projectDir.'/users/alice/storage/database');

        // A restart (or another worker) reuses the same secret and the same hash, sessions depend on it.
        $restarted = $tenants->withSecrets()->findByName('alice')->getEnvironment();
        $this->assertSame($environment['APP_SECRET'], $restarted['APP_SECRET']);
        $this->assertSame($environment['ADMIN_PASSWORD_HASH'], $restarted['ADMIN_PASSWORD_HASH']);
    }

    public function testWithSecretsRehashesWhenPasswordChanges(): void
    {
        $environment = [
            'DREEVE_USERS' => 'alice',
            'ALICE_URL' => 'https://alice.example.com',
            'ALICE_PASSWORD' => 'alice-long-password',
        ];
        $oldHash = Tenants::discover($this->projectDir, $environment)->withSecrets()->findByName('alice')->getEnvironment()['ADMIN_PASSWORD_HASH'];

        $environment['ALICE_PASSWORD'] = 'alice-new-long-password';
        $newHash = Tenants::discover($this->projectDir, $environment)->withSecrets()->findByName('alice')->getEnvironment()['ADMIN_PASSWORD_HASH'];

        $this->assertNotSame($oldHash, $newHash);
        $this->assertFalse(password_verify('alice-long-password', $newHash));
        $this->assertTrue(password_verify('alice-new-long-password', $newHash));
    }

    public function testWithSecretsKeepsConfiguredAppSecret(): void
    {
        $alice = Tenants::discover($this->projectDir, [
            'DREEVE_USERS' => 'alice',
            'ALICE_URL' => 'https://alice.example.com',
            'ALICE_APP_SECRET' => 'configured-secret',
        ])->withSecrets()->findByName('alice');

        $this->assertSame('configured-secret', $alice->getEnvironment()['APP_SECRET']);
        $this->assertFileDoesNotExist($this->projectDir.'/users/alice/'.Tenant::APP_SECRET_FILE_NAME);
        $this->assertArrayNotHasKey('ADMIN_PASSWORD_HASH', $alice->getEnvironment());
    }

    public function testRemovePasswordsFromSuperglobals(): void
    {
        $_SERVER['ALICE_PASSWORD'] = $_ENV['ALICE_PASSWORD'] = 'alice-long-password';
        $_SERVER['ALICE_URL'] = 'https://alice.example.com';

        Tenants::discover($this->projectDir, [...$_SERVER, 'DREEVE_USERS' => 'alice'])->removePasswordsFromSuperglobals();

        $this->assertArrayNotHasKey('ALICE_PASSWORD', $_SERVER);
        $this->assertArrayNotHasKey('ALICE_PASSWORD', $_ENV);
        $this->assertSame('https://alice.example.com', $_SERVER['ALICE_URL']);
        unset($_SERVER['ALICE_URL']);
    }

    /**
     * @param array<string, string> $environment
     */
    #[DataProvider('provideInvalidConfigurations')]
    public function testInvalidConfiguration(array $environment, InvalidTenant $expectedException): void
    {
        $this->expectExceptionObject($expectedException);

        Tenants::discover($this->projectDir, $environment);
    }

    public static function provideInvalidConfigurations(): iterable
    {
        yield 'missing url' => [
            ['DREEVE_USERS' => 'alice'],
            InvalidTenant::missingHost('alice'),
        ];
        yield 'invalid name' => [
            ['DREEVE_USERS' => 'Alice'],
            InvalidTenant::invalidName('Alice'),
        ];
        yield 'reserved name' => [
            ['DREEVE_USERS' => 'strava'],
            InvalidTenant::reservedName('strava'),
        ];
        yield 'reserved name with suffix' => [
            ['DREEVE_USERS' => 'app-user'],
            InvalidTenant::reservedName('app-user'),
        ];
        yield 'duplicate name' => [
            ['DREEVE_USERS' => 'alice,alice'],
            InvalidTenant::duplicateName('alice'),
        ];
        yield 'overlapping names' => [
            ['DREEVE_USERS' => 'bob,bob-smith'],
            InvalidTenant::overlappingNames('bob', 'bob-smith'),
        ];
        yield 'password too short' => [
            ['DREEVE_USERS' => 'alice', 'ALICE_URL' => 'https://alice.example.com', 'ALICE_PASSWORD' => 'short'],
            InvalidTenant::passwordTooShort('alice', Tenant::MIN_PASSWORD_LENGTH),
        ];
        yield 'password and password hash' => [
            ['DREEVE_USERS' => 'alice', 'ALICE_URL' => 'https://alice.example.com', 'ALICE_PASSWORD' => 'alice-long-password', 'ALICE_ADMIN_PASSWORD_HASH' => '$2y$04$hash'],
            InvalidTenant::passwordAndPasswordHash('alice'),
        ];
        yield 'reserved variable' => [
            ['DREEVE_USERS' => 'alice', 'ALICE_URL' => 'https://alice.example.com', 'ALICE_APP_ENV' => 'dev'],
            InvalidTenant::reservedVariable('alice', 'APP_ENV'),
        ];
        yield 'duplicate host' => [
            ['DREEVE_USERS' => 'alice,bob', 'ALICE_URL' => 'https://same.example.com', 'BOB_URL' => 'https://same.example.com'],
            InvalidTenant::duplicateHost('same.example.com', 'alice', 'bob'),
        ];
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = sys_get_temp_dir().'/dreeve-tenants-test-'.bin2hex(random_bytes(5));
        mkdir($this->projectDir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);

        parent::tearDown();
    }

    private function assertFilePermissions(int $expected, string $path): void
    {
        clearstatcache();
        $this->assertSame($expected, fileperms($path) & 0o777, sprintf('Unexpected permissions on "%s"', $path));
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
