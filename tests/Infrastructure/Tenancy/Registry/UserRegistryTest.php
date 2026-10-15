<?php

namespace App\Tests\Infrastructure\Tenancy\Registry;

use App\Infrastructure\Tenancy\Registry\UserRegistry;
use App\Infrastructure\Tenancy\Registry\UserRegistryException;
use App\Tests\Infrastructure\Tenancy\ProvideTemporaryProjectDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserRegistryTest extends TestCase
{
    use ProvideTemporaryProjectDir;

    private UserRegistry $registry;
    private \DateTimeImmutable $now;

    public function testAddAndFind(): void
    {
        $this->assertFalse($this->registry->exists());
        $this->assertSame([], $this->registry->findAll());
        $this->assertNull($this->registry->findOwner());

        $this->registry->add('bob', false, $this->now);
        $this->registry->add('alice', true, $this->now);

        $this->assertTrue($this->registry->exists());
        $this->assertSame(['alice', 'bob'], array_keys($this->registry->findAll()));
        $this->assertSame('alice', $this->registry->findOwner()?->getName());
        $this->assertTrue($this->registry->get('alice')->isOwner());
        $this->assertFalse($this->registry->get('bob')->isOwner());
        $this->assertFalse($this->registry->get('bob')->isDisabled());
        $this->assertFalse($this->registry->hasPassword('bob'));
        $this->assertNull($this->registry->find('carol'));
    }

    public function testRegistryFileIsPrivateAndHoldsNoTokens(): void
    {
        $token = $this->registry->add('alice', true, $this->now);

        $path = $this->getUsersDir().'/'.UserRegistry::FILE_NAME;
        $this->assertFilePermissions(0o600, $path);
        $this->assertStringNotContainsString($token, (string) file_get_contents($path));
    }

    public function testAddItShouldThrowWhenUserExists(): void
    {
        $this->registry->add('alice', true, $this->now);

        $this->expectExceptionObject(UserRegistryException::alreadyExists('alice'));
        $this->registry->add('alice', false, $this->now);
    }

    #[DataProvider('provideInvalidNames')]
    public function testAddItShouldThrowOnInvalidName(string $name): void
    {
        $this->expectExceptionObject(UserRegistryException::invalidName($name));

        $this->registry->add($name, false, $this->now);
    }

    public static function provideInvalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Alice'];
        yield 'starts with dash' => ['-alice'];
        yield 'ends with dash' => ['alice-'];
        yield 'dot' => ['al.ice'];
        yield 'path traversal' => ['../alice'];
        yield 'too long' => [str_repeat('a', 64)];
    }

    public function testAddItShouldThrowOnReservedName(): void
    {
        $this->expectExceptionObject(UserRegistryException::reservedName('admin'));

        $this->registry->add('admin', true, $this->now);
    }

    public function testAcceptInvite(): void
    {
        $token = $this->registry->add('alice', true, $this->now);

        $this->assertTrue($this->registry->isValidInvite('alice', $token, $this->now));
        $this->assertFalse($this->registry->isValidInvite('alice', 'wrong-token', $this->now));
        $this->assertFalse($this->registry->isValidInvite('bob', $token, $this->now));

        $this->registry->acceptInvite('alice', $token, 'a-long-enough-password', $this->now);

        $this->assertTrue($this->registry->hasPassword('alice'));
        $hashFile = $this->getUsersDir().'/alice/.admin-password-hash';
        $this->assertTrue(password_verify('a-long-enough-password', trim((string) file_get_contents($hashFile))));
        $this->assertFilePermissions(0o600, $hashFile);

        // An invite works once.
        $this->assertFalse($this->registry->isValidInvite('alice', $token, $this->now));
        $this->expectExceptionObject(UserRegistryException::invalidInvite());
        $this->registry->acceptInvite('alice', $token, 'another-long-password', $this->now);
    }

    public function testAcceptInviteItShouldThrowWhenExpired(): void
    {
        $token = $this->registry->add('alice', true, $this->now);
        $later = $this->now->modify('+7 days +1 second');

        $this->assertFalse($this->registry->isValidInvite('alice', $token, $later));
        $this->expectExceptionObject(UserRegistryException::invalidInvite());
        $this->registry->acceptInvite('alice', $token, 'a-long-enough-password', $later);
    }

    public function testAcceptInviteItShouldThrowForAnotherUsersToken(): void
    {
        $this->registry->add('alice', true, $this->now);
        $bobsToken = $this->registry->add('bob', false, $this->now);

        $this->expectExceptionObject(UserRegistryException::invalidInvite());
        $this->registry->acceptInvite('alice', $bobsToken, 'a-long-enough-password', $this->now);
    }

    public function testAcceptInviteItShouldThrowWhenDisabled(): void
    {
        $this->registry->add('alice', true, $this->now);
        $token = $this->registry->add('bob', false, $this->now);
        $this->registry->setDisabled('bob', true);

        $this->assertFalse($this->registry->isValidInvite('bob', $token, $this->now));
        $this->expectExceptionObject(UserRegistryException::invalidInvite());
        $this->registry->acceptInvite('bob', $token, 'a-long-enough-password', $this->now);
    }

    public function testAcceptInviteItShouldThrowOnShortPassword(): void
    {
        $token = $this->registry->add('alice', true, $this->now);

        $this->expectExceptionObject(UserRegistryException::passwordTooShort(UserRegistry::MIN_PASSWORD_LENGTH));
        $this->registry->acceptInvite('alice', $token, 'too-short', $this->now);
    }

    public function testCreateInviteReplacesEarlierInviteAndResetsPassword(): void
    {
        $firstToken = $this->registry->add('alice', true, $this->now);
        $this->registry->acceptInvite('alice', $firstToken, 'a-long-enough-password', $this->now);
        $oldHash = (string) file_get_contents($this->getUsersDir().'/alice/.admin-password-hash');

        $secondToken = $this->registry->createInvite('alice', $this->now);
        $thirdToken = $this->registry->createInvite('alice', $this->now);

        // The current password keeps working until the new invite is accepted.
        $this->assertSame($oldHash, (string) file_get_contents($this->getUsersDir().'/alice/.admin-password-hash'));
        // Only the latest invite works.
        $this->assertFalse($this->registry->isValidInvite('alice', $secondToken, $this->now));
        $this->assertTrue($this->registry->isValidInvite('alice', $thirdToken, $this->now));

        $this->registry->acceptInvite('alice', $thirdToken, 'a-new-long-password', $this->now);
        $newHash = trim((string) file_get_contents($this->getUsersDir().'/alice/.admin-password-hash'));
        $this->assertTrue(password_verify('a-new-long-password', $newHash));
        $this->assertFalse(password_verify('a-long-enough-password', $newHash));
    }

    public function testSetDisabled(): void
    {
        $this->registry->add('alice', true, $this->now);
        $this->registry->add('bob', false, $this->now);

        $this->registry->setDisabled('bob', true);
        $this->assertTrue($this->registry->get('bob')->isDisabled());

        $this->registry->setDisabled('bob', false);
        $this->assertFalse($this->registry->get('bob')->isDisabled());
    }

    public function testOwnerCanNotBeDisabledOrRemoved(): void
    {
        $this->registry->add('alice', true, $this->now);

        try {
            $this->registry->setDisabled('alice', true);
            $this->fail('The owner must not be disabled');
        } catch (UserRegistryException $e) {
            $this->assertEquals(UserRegistryException::ownerCanNotBeChanged('alice'), $e);
        }

        $this->expectExceptionObject(UserRegistryException::ownerCanNotBeChanged('alice'));
        $this->registry->remove('alice', $this->now);
    }

    public function testRemoveMovesDataAway(): void
    {
        $this->registry->add('alice', true, $this->now);
        $token = $this->registry->add('bob', false, $this->now);
        $this->registry->acceptInvite('bob', $token, 'a-long-enough-password', $this->now);
        file_put_contents($this->getUsersDir().'/bob/activity.fit', 'data');

        $this->registry->remove('bob', $this->now);

        $this->assertNull($this->registry->find('bob'));
        $this->assertDirectoryDoesNotExist($this->getUsersDir().'/bob');
        $this->assertFileExists($this->getUsersDir().'/.removed/bob-20261015-120000/activity.fit');

        // A new user with the same name starts fresh, without the old password.
        $this->registry->add('bob', false, $this->now);
        $this->assertFalse($this->registry->hasPassword('bob'));
    }

    public function testAddRemovesLeftOverPasswordOfEarlierUser(): void
    {
        $this->registry->add('alice', true, $this->now);
        mkdir($this->getUsersDir().'/bob');
        file_put_contents($this->getUsersDir().'/bob/.admin-password-hash', password_hash('old-password', PASSWORD_BCRYPT));

        $this->registry->add('bob', false, $this->now);

        $this->assertFalse($this->registry->hasPassword('bob'));
    }

    public function testGetRevisionChangesWithEveryChange(): void
    {
        $this->assertSame('', $this->registry->getRevision());

        $this->registry->add('alice', true, $this->now);
        $afterAdd = $this->registry->getRevision();
        $this->assertNotSame('', $afterAdd);
        $this->assertSame($afterAdd, $this->registry->getRevision());

        $this->registry->add('bob', false, $this->now);
        $this->registry->setDisabled('bob', true);
        $this->assertNotSame($afterAdd, $this->registry->getRevision());
    }

    public function testCorruptRegistry(): void
    {
        file_put_contents($this->getUsersDir().'/'.UserRegistry::FILE_NAME, '{not json');

        $this->expectExceptionObject(UserRegistryException::corrupt($this->getUsersDir().'/'.UserRegistry::FILE_NAME));
        $this->registry->findAll();
    }

    public function testGuardPassword(): void
    {
        UserRegistry::guardPassword(str_repeat('a', UserRegistry::MIN_PASSWORD_LENGTH));
        UserRegistry::guardPassword(str_repeat('a', UserRegistry::MAX_PASSWORD_LENGTH));

        try {
            UserRegistry::guardPassword(str_repeat('a', UserRegistry::MIN_PASSWORD_LENGTH - 1));
            $this->fail('Expected a too short password to be rejected');
        } catch (UserRegistryException) {
        }

        $this->expectException(UserRegistryException::class);
        UserRegistry::guardPassword(str_repeat('a', UserRegistry::MAX_PASSWORD_LENGTH + 1));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->createProjectDir();
        $this->registry = new UserRegistry($this->getUsersDir());
        $this->now = new \DateTimeImmutable('2026-10-15 12:00:00');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeProjectDir();

        parent::tearDown();
    }
}
