<?php

namespace App\Tests\Infrastructure\Security;

use App\Infrastructure\Security\AdminPasswordHash;
use App\Infrastructure\Security\AdminUserName;
use App\Infrastructure\Security\AdminUserProvider;
use App\Infrastructure\ValueObject\String\DataDir;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

class AdminUserProviderTest extends TestCase
{
    public function testLoadUserByIdentifier(): void
    {
        $provider = new AdminUserProvider(
            AdminUserName::fromString('admin'),
            AdminPasswordHash::fromString('hashed-password'),
        );

        $user = $provider->loadUserByIdentifier('admin');

        $this->assertInstanceOf(InMemoryUser::class, $user);
        $this->assertEquals('admin', $user->getUserIdentifier());
        $this->assertEquals('hashed-password', $user->getPassword());
        $this->assertEquals(['ROLE_ADMIN'], $user->getRoles());
    }

    public function testLoadUserByIdentifierItShouldThrowWhenPasswordHashIsEmpty(): void
    {
        $provider = new AdminUserProvider(
            AdminUserName::fromString('admin'),
            AdminPasswordHash::fromString(''),
        );

        $this->expectExceptionObject(new UserNotFoundException(''));

        $provider->loadUserByIdentifier('admin');
    }

    public function testLoadUserByIdentifierItShouldThrowWhenIdentifierDoesNotMatch(): void
    {
        $provider = new AdminUserProvider(
            AdminUserName::fromString('admin'),
            AdminPasswordHash::fromString('hashed-password'),
        );

        $this->expectExceptionObject(new UserNotFoundException(''));

        $provider->loadUserByIdentifier('someone-else');
    }

    public function testRefreshUserAndSupportsClass(): void
    {
        $provider = new AdminUserProvider(
            AdminUserName::fromString('admin'),
            AdminPasswordHash::fromString('hashed-password'),
        );

        $user = $provider->refreshUser(new InMemoryUser('admin', 'hashed-password', ['ROLE_ADMIN']));

        $this->assertInstanceOf(InMemoryUser::class, $user);
        $this->assertEquals('admin', $user->getUserIdentifier());
        $this->assertEquals('hashed-password', $user->getPassword());
        $this->assertEquals(['ROLE_ADMIN'], $user->getRoles());

        $this->assertTrue($provider->supportsClass(InMemoryUser::class));
        $this->assertFalse($provider->supportsClass(UserInterface::class));
    }

    public function testLoadUserByIdentifierForUserOfMultiUserInstallation(): void
    {
        $dataDir = sys_get_temp_dir().'/dreeve-admin-user-provider-'.bin2hex(random_bytes(5));
        mkdir($dataDir);
        $provider = new AdminUserProvider(
            AdminUserName::fromString('alice'),
            // The installation-wide hash is never used for a user of a multi-user installation.
            AdminPasswordHash::fromString('installation-hash'),
            DataDir::fromString($dataDir),
            'alice',
        );

        try {
            // No password picked yet: the admin panel stays locked.
            try {
                $provider->loadUserByIdentifier('alice');
                $this->fail('Expected UserNotFoundException');
            } catch (UserNotFoundException) {
            }

            file_put_contents($dataDir.'/.admin-password-hash', "users-hash\n");
            $this->assertEquals('users-hash', $provider->loadUserByIdentifier('alice')->getPassword());

            // A new password is picked up right away.
            file_put_contents($dataDir.'/.admin-password-hash', "new-hash\n");
            $this->assertEquals('new-hash', $provider->refreshUser(new InMemoryUser('alice', 'users-hash', ['ROLE_ADMIN']))->getPassword());
        } finally {
            @unlink($dataDir.'/.admin-password-hash');
            rmdir($dataDir);
        }
    }

    public function testRefreshUserItShouldThrowWhenUserIsNotSupported(): void
    {
        $provider = new AdminUserProvider(
            AdminUserName::fromString('admin'),
            AdminPasswordHash::fromString('hashed-password'),
        );

        $this->expectExceptionObject(new UnsupportedUserException(''));

        $provider->refreshUser($this->createStub(UserInterface::class));
    }
}
