<?php

namespace App\Tests\Infrastructure\Tenancy;

use App\Infrastructure\Tenancy\Registry\UserRegistry;
use App\Infrastructure\Tenancy\Registry\UserRegistryException;
use App\Infrastructure\Tenancy\UserAdministration;
use App\Infrastructure\Tenancy\UserUrlTemplate;
use PHPUnit\Framework\TestCase;

class UserAdministrationTest extends TestCase
{
    use ProvideTemporaryProjectDir;

    private UserAdministration $userAdministration;
    private SpyUserProvisioner $provisioner;
    private \DateTimeImmutable $now;

    public function testAddUser(): void
    {
        $inviteUrl = $this->userAdministration->addUser('bob', $this->now);

        $this->assertMatchesRegularExpression('#^https://bob\.example\.com/invite/[A-Za-z0-9_-]{43}$#', $inviteUrl);
        $this->assertSame(['bob'], $this->provisioner->provisionedUsers);
        $this->assertTrue($this->userAdministration->isValidInvite('bob', basename($inviteUrl), $this->now));
        $this->assertFalse($this->userAdministration->isOwner('bob'));
        $this->assertSame('https://bob.example.com', $this->userAdministration->getUserUrl('bob'));
    }

    public function testBootstrapOwner(): void
    {
        // First start: the owner is created.
        $inviteUrl = $this->userAdministration->bootstrapOwner('alice', $this->now);
        $this->assertStringStartsWith('https://alice.example.com/invite/', (string) $inviteUrl);
        $this->assertTrue($this->userAdministration->isOwner('alice'));

        // Every start until the owner picked a password: a fresh link, the previous one stops working.
        $secondInviteUrl = $this->userAdministration->bootstrapOwner('alice', $this->now);
        $this->assertNotNull($secondInviteUrl);
        $this->assertFalse($this->userAdministration->isValidInvite('alice', basename((string) $inviteUrl), $this->now));

        // Once the owner has a password, nothing happens anymore.
        $this->userAdministration->acceptInvite('alice', basename((string) $secondInviteUrl), 'a-long-enough-password', $this->now);
        $this->assertNull($this->userAdministration->bootstrapOwner('alice', $this->now));
        // Changing DREEVE_OWNER later does not create another owner.
        $this->assertNull($this->userAdministration->bootstrapOwner('mallory', $this->now));
        $this->assertFalse($this->userAdministration->isOwner('mallory'));
        $this->assertSame(['alice'], $this->provisioner->provisionedUsers);
    }

    public function testBootstrapOwnerItShouldThrowWithoutOwnerName(): void
    {
        $this->expectException(UserRegistryException::class);

        $this->userAdministration->bootstrapOwner('', $this->now);
    }

    public function testBootstrapOwnerDoesNothingWhenUsersExistWithoutOwner(): void
    {
        new UserRegistry($this->getUsersDir())->add('bob', false, $this->now);

        $this->assertNull($this->userAdministration->bootstrapOwner('alice', $this->now));
    }

    public function testIsOwner(): void
    {
        $this->userAdministration->bootstrapOwner('alice', $this->now);

        $this->assertTrue($this->userAdministration->isOwner('alice'));
        $this->assertFalse($this->userAdministration->isOwner(''));
        $this->assertFalse($this->userAdministration->isOwner('unknown'));
    }

    public function testSingleUserInstallation(): void
    {
        $userAdministration = new UserAdministration(new UserRegistry($this->getUsersDir()), null, $this->provisioner);

        $this->assertFalse($userAdministration->isMultiUser());
        $this->expectException(UserRegistryException::class);
        $userAdministration->addUser('bob', $this->now);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->createProjectDir();
        $this->now = new \DateTimeImmutable('2026-10-15 12:00:00');
        $this->userAdministration = new UserAdministration(
            registry: new UserRegistry($this->getUsersDir()),
            userUrlTemplate: UserUrlTemplate::fromString('https://{user}.example.com'),
            provisioner: $this->provisioner = new SpyUserProvisioner(),
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeProjectDir();

        parent::tearDown();
    }
}
