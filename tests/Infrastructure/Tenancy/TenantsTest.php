<?php

namespace App\Tests\Infrastructure\Tenancy;

use App\Infrastructure\Tenancy\InvalidTenant;
use App\Infrastructure\Tenancy\Registry\UserRegistry;
use App\Infrastructure\Tenancy\Tenant;
use App\Infrastructure\Tenancy\Tenants;
use PHPUnit\Framework\TestCase;

class TenantsTest extends TestCase
{
    use ProvideTemporaryProjectDir;

    private UserRegistry $registry;

    public function testDiscoverSingleUserInstallation(): void
    {
        $tenants = Tenants::discover($this->projectDir, []);

        $this->assertFalse($tenants->isMultiUser());
        $this->assertTrue($tenants->isEmpty());
        $this->assertSame($tenants, $tenants->withSecrets());
    }

    public function testDiscoverItShouldThrowWhenUsersExistWithoutUserUrl(): void
    {
        $this->registry->add('alice', true, new \DateTimeImmutable());

        $this->expectExceptionObject(InvalidTenant::missingUserUrl());
        Tenants::discover($this->projectDir, []);
    }

    public function testDiscoverMultiUserInstallationWithoutUsers(): void
    {
        $tenants = Tenants::discover($this->projectDir, ['DREEVE_USER_URL' => 'https://{user}.example.com']);

        $this->assertTrue($tenants->isMultiUser());
        $this->assertTrue($tenants->isEmpty());
    }

    public function testDiscover(): void
    {
        $now = new \DateTimeImmutable();
        $this->registry->add('alice', true, $now);
        $this->registry->add('bob', false, $now);
        $this->registry->add('carol', false, $now);
        $this->registry->setDisabled('carol', true);
        mkdir($this->getUsersDir().'/bob', 0o777, true);
        file_put_contents($this->getUsersDir().'/bob/.env', "STRAVA_CLIENT_ID=bob-client\nAPP_URL=https://ignored.example.com\nADMIN_USERNAME=ignored\n");

        $tenants = Tenants::discover($this->projectDir, ['DREEVE_USER_URL' => 'https://{user}.example.com/']);

        // Disabled users are left out.
        $this->assertSame(['alice', 'bob'], $tenants->getNames());
        $this->assertCount(2, $tenants);

        $alice = $tenants->findByName('alice');
        $this->assertSame($this->getUsersDir().'/alice', $alice->getDataDirectory());
        $this->assertSame('alice.example.com', $alice->getHost());
        $this->assertSame(
            [
                'DATABASE_DIRECTORY' => $this->getUsersDir().'/alice/storage/database',
                'APP_URL' => 'https://alice.example.com',
                'ADMIN_USERNAME' => 'alice',
                'ADMIN_PASSWORD_HASH' => '',
                'DREEVE_USER' => 'alice',
            ],
            $alice->getEnvironment()
        );

        // Settings from the user's .env file are used, but the address and login always follow the user.
        $bob = $tenants->findByName('bob')->getEnvironment();
        $this->assertSame('bob-client', $bob['STRAVA_CLIENT_ID']);
        $this->assertSame('https://bob.example.com', $bob['APP_URL']);
        $this->assertSame('bob', $bob['ADMIN_USERNAME']);

        $this->assertSame('bob', $tenants->findForRequest('BOB.example.com', '/')?->getName());
        $this->assertNull($tenants->findForRequest('carol.example.com', '/'));
        $this->assertNull($tenants->findForRequest('example.com', '/bob'));
    }

    public function testDiscoverWithAnAddressPerPath(): void
    {
        $now = new \DateTimeImmutable();
        $this->registry->add('alice', true, $now);
        $this->registry->add('bob', false, $now);

        $tenants = Tenants::discover($this->projectDir, ['DREEVE_USER_URL' => 'https://dreeve.example.com/{user}']);

        $alice = $tenants->findByName('alice');
        $this->assertSame('https://dreeve.example.com/alice', $alice->getEnvironment()['APP_URL']);
        $this->assertSame('dreeve.example.com', $alice->getHost());
        $this->assertSame('/alice', $alice->getBasePath());

        $this->assertSame('alice', $tenants->findForRequest('dreeve.example.com', '/alice')?->getName());
        $this->assertSame('alice', $tenants->findForRequest('DREEVE.example.com', '/alice/admin/login')?->getName());
        $this->assertSame('bob', $tenants->findForRequest('dreeve.example.com', '/bob/')?->getName());
        $this->assertNull($tenants->findForRequest('dreeve.example.com', '/'));
        $this->assertNull($tenants->findForRequest('dreeve.example.com', ''));
        $this->assertNull($tenants->findForRequest('dreeve.example.com', '/carol/admin'));
        $this->assertNull($tenants->findForRequest('dreeve.example.com', '/alicex'));
        $this->assertNull($tenants->findForRequest('dreeve.example.com', '/Alice'));
        $this->assertNull($tenants->findForRequest('other.example.com', '/alice'));
    }

    public function testGetSettingsRevision(): void
    {
        $revisions = [Tenants::getSettingsRevision($this->projectDir)];

        $this->registry->add('alice', true, new \DateTimeImmutable());
        $revisions[] = Tenants::getSettingsRevision($this->projectDir);
        $this->assertSame(end($revisions), Tenants::getSettingsRevision($this->projectDir), 'Stable while nothing changes');

        mkdir($this->getUsersDir().'/alice', 0o777, true);
        file_put_contents($this->getUsersDir().'/alice/.env', "AI_API_KEY=key\n");
        $revisions[] = Tenants::getSettingsRevision($this->projectDir);

        file_put_contents($this->getUsersDir().'/alice/.env', 'AI_API_KEY=another-key
');
        $revisions[] = Tenants::getSettingsRevision($this->projectDir);

        $this->assertCount(4, array_unique($revisions));
    }

    public function testFindByNameWhenUnknown(): void
    {
        $tenants = Tenants::discover($this->projectDir, ['DREEVE_USER_URL' => 'https://{user}.example.com']);

        $this->expectExceptionObject(InvalidTenant::unknown('carol', $tenants));
        $tenants->findByName('carol');
    }

    public function testDiscoverItShouldThrowOnReservedVariableInUsersEnvFile(): void
    {
        $this->registry->add('alice', true, new \DateTimeImmutable());
        mkdir($this->getUsersDir().'/alice', 0o777, true);
        file_put_contents($this->getUsersDir().'/alice/.env', "APP_ENV=dev\n");

        $this->expectExceptionObject(InvalidTenant::reservedVariable('alice', 'APP_ENV'));
        Tenants::discover($this->projectDir, ['DREEVE_USER_URL' => 'https://{user}.example.com']);
    }

    public function testWithSecrets(): void
    {
        $this->registry->add('alice', true, new \DateTimeImmutable());

        $alice = Tenants::discover($this->projectDir, ['DREEVE_USER_URL' => 'https://{user}.example.com'])
            ->withSecrets()
            ->findByName('alice');

        $secretFile = $this->getUsersDir().'/alice/'.Tenant::APP_SECRET_FILE_NAME;
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $alice->getEnvironment()['APP_SECRET']);
        $this->assertSame($alice->getEnvironment()['APP_SECRET'], trim((string) file_get_contents($secretFile)));
        $this->assertFilePermissions(0o600, $secretFile);
        $this->assertDirectoryExists($this->getUsersDir().'/alice/storage/database');

        // Every worker and every restart uses the same secret.
        $again = Tenants::discover($this->projectDir, ['DREEVE_USER_URL' => 'https://{user}.example.com'])->withSecrets()->findByName('alice');
        $this->assertSame($alice->getEnvironment()['APP_SECRET'], $again->getEnvironment()['APP_SECRET']);
    }

    public function testWithSecretsKeepsConfiguredAppSecret(): void
    {
        $this->registry->add('alice', true, new \DateTimeImmutable());
        mkdir($this->getUsersDir().'/alice', 0o777, true);
        file_put_contents($this->getUsersDir().'/alice/.env', "APP_SECRET=configured-secret\n");

        $alice = Tenants::discover($this->projectDir, ['DREEVE_USER_URL' => 'https://{user}.example.com'])->withSecrets()->findByName('alice');

        $this->assertSame('configured-secret', $alice->getEnvironment()['APP_SECRET']);
        $this->assertFileDoesNotExist($this->getUsersDir().'/alice/'.Tenant::APP_SECRET_FILE_NAME);
    }

    public function testFromArrayItShouldThrowOnDuplicateHost(): void
    {
        $this->expectExceptionObject(InvalidTenant::duplicateAddress('same.example.com', 'alice', 'bob'));

        Tenants::fromArray([
            Tenant::fromEnvironment('alice', '/users/alice', ['APP_URL' => 'https://same.example.com']),
            Tenant::fromEnvironment('bob', '/users/bob', ['APP_URL' => 'https://SAME.example.com']),
        ]);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->createProjectDir();
        $this->registry = new UserRegistry($this->getUsersDir());
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeProjectDir();

        parent::tearDown();
    }

    public function testFingerprintChangesWithSettings(): void
    {
        $tenant = Tenant::fromEnvironment('alice', '/users/alice', ['APP_URL' => 'https://dreeve.example.com/alice']);

        $this->assertSame($tenant->getFingerprint(), Tenant::fromEnvironment('alice', '/users/alice', ['APP_URL' => 'https://dreeve.example.com/alice'])->getFingerprint());
        $this->assertNotSame($tenant->getFingerprint(), Tenant::fromEnvironment('alice', '/users/alice', ['APP_URL' => 'https://dreeve.example.com/alice', 'AI_API_KEY' => 'a-key'])->getFingerprint());
    }
}
