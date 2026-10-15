<?php

namespace App\Tests\Domain\UserManagement;

use App\Domain\UserManagement\AddUser\AddUser;
use App\Domain\UserManagement\AddUser\AddUserCommandHandler;
use App\Domain\UserManagement\ChangeUserStatus\ChangeUserStatus;
use App\Domain\UserManagement\ChangeUserStatus\ChangeUserStatusCommandHandler;
use App\Domain\UserManagement\InviteLinkFlash;
use App\Domain\UserManagement\InviteUser\InviteUser;
use App\Domain\UserManagement\InviteUser\InviteUserCommandHandler;
use App\Domain\UserManagement\RemoveUser\RemoveUser;
use App\Domain\UserManagement\RemoveUser\RemoveUserCommandHandler;
use App\Domain\UserManagement\UserManagementGuard;
use App\Infrastructure\CQRS\Command\CouldNotProcessCommand;
use App\Infrastructure\CQRS\Command\Deserialize\CouldNotDeserializeCommand;
use App\Infrastructure\Tenancy\Registry\UserRegistry;
use App\Infrastructure\Tenancy\UserAdministration;
use App\Infrastructure\Tenancy\UserUrlTemplate;
use App\Tests\Infrastructure\Tenancy\ProvideTemporaryProjectDir;
use App\Tests\Infrastructure\Tenancy\SpyUserProvisioner;
use App\Tests\Infrastructure\Time\Clock\PausedClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class UserManagementCommandsTest extends TestCase
{
    use ProvideTemporaryProjectDir;

    private UserAdministration $userAdministration;
    private UserRegistry $registry;
    private Session $session;
    private RequestStack $requestStack;
    private PausedClock $clock;

    public function testAddUser(): void
    {
        $this->handlers('alice')['add']->handle(AddUser::fromPayload(['name' => ' Bob ']));

        $this->assertNotNull($this->registry->find('bob'));
        $inviteLinks = $this->session->getFlashBag()->get(InviteLinkFlash::TYPE);
        $this->assertCount(1, $inviteLinks);
        $this->assertSame('bob', $inviteLinks[0]['name']);
        $this->assertStringStartsWith('https://bob.example.com/invite/', $inviteLinks[0]['url']);
    }

    public function testAddUserWhenUserExists(): void
    {
        $this->expectExceptionObject(CouldNotProcessCommand::withReason('User "alice" already exists'));

        $this->handlers('alice')['add']->handle(AddUser::fromPayload(['name' => 'alice']));
    }

    public function testInviteUser(): void
    {
        $this->registry->add('bob', false, $this->clock->getCurrentDateTimeImmutable());

        $this->handlers('alice')['invite']->handle(InviteUser::fromPayload(['name' => 'bob']));

        $inviteLinks = $this->session->getFlashBag()->get(InviteLinkFlash::TYPE);
        $this->assertTrue($this->registry->isValidInvite('bob', basename($inviteLinks[0]['url']), $this->clock->getCurrentDateTimeImmutable()));
    }

    public function testChangeUserStatus(): void
    {
        $this->registry->add('bob', false, $this->clock->getCurrentDateTimeImmutable());

        $this->handlers('alice')['status']->handle(ChangeUserStatus::fromPayload(['name' => 'bob', 'status' => 'disabled']));
        $this->assertTrue($this->registry->get('bob')->isDisabled());

        $this->handlers('alice')['status']->handle(ChangeUserStatus::fromPayload(['name' => 'bob', 'status' => 'enabled']));
        $this->assertFalse($this->registry->get('bob')->isDisabled());
    }

    public function testOwnerCanNotDisableThemselves(): void
    {
        $this->expectExceptionObject(CouldNotProcessCommand::withReason('User "alice" is the owner of this installation and can not be disabled or removed'));

        $this->handlers('alice')['status']->handle(ChangeUserStatus::fromPayload(['name' => 'alice', 'status' => 'disabled']));
    }

    public function testRemoveUser(): void
    {
        $this->registry->add('bob', false, $this->clock->getCurrentDateTimeImmutable());

        $this->handlers('alice')['remove']->handle(RemoveUser::fromPayload(['name' => 'bob']));

        $this->assertNull($this->registry->find('bob'));
    }

    #[DataProvider('provideCommands')]
    public function testOnlyTheOwnerCanManageUsers(string $handler, \Closure $command): void
    {
        $this->registry->add('bob', false, $this->clock->getCurrentDateTimeImmutable());

        foreach (['bob', ''] as $currentUser) {
            try {
                $this->handlers($currentUser)[$handler]->handle($command());
                $this->fail(sprintf('User "%s" must not be able to manage users', $currentUser));
            } catch (CouldNotProcessCommand $e) {
                $this->assertSame('Only the owner of this installation can manage users.', $e->getMessage());
            }
        }

        $this->assertSame(['alice', 'bob'], array_keys($this->registry->findAll()));
        $this->assertFalse($this->registry->get('bob')->isDisabled());
    }

    public static function provideCommands(): iterable
    {
        yield 'add' => ['add', static fn (): AddUser => AddUser::fromPayload(['name' => 'mallory'])];
        yield 'invite' => ['invite', static fn (): InviteUser => InviteUser::fromPayload(['name' => 'alice'])];
        yield 'status' => ['status', static fn (): ChangeUserStatus => ChangeUserStatus::fromPayload(['name' => 'bob', 'status' => 'disabled'])];
        yield 'remove' => ['remove', static fn (): RemoveUser => RemoveUser::fromPayload(['name' => 'bob'])];
    }

    #[DataProvider('provideInvalidPayloads')]
    public function testInvalidPayload(\Closure $command, string $expectedMessage): void
    {
        $this->expectExceptionObject(CouldNotDeserializeCommand::invalidPayload($expectedMessage));

        $command();
    }

    public static function provideInvalidPayloads(): iterable
    {
        yield 'missing name' => [static fn (): AddUser => AddUser::fromPayload([]), 'A "name" is required.'];
        yield 'invalid name' => [static fn (): AddUser => AddUser::fromPayload(['name' => '../alice']), 'Use 1 to 63 lowercase letters, digits and dashes for the name, starting and ending with a letter or digit.'];
        yield 'reserved name' => [static fn (): AddUser => AddUser::fromPayload(['name' => 'gear']), '"gear" can not be used as user name, Dreeve uses it for its own pages. Pick another name.'];
        yield 'invalid status' => [static fn (): ChangeUserStatus => ChangeUserStatus::fromPayload(['name' => 'bob', 'status' => 'deleted']), 'A "status" of "disabled" or "enabled" is required.'];
    }

    /**
     * @return array{add: AddUserCommandHandler, invite: InviteUserCommandHandler, status: ChangeUserStatusCommandHandler, remove: RemoveUserCommandHandler}
     */
    private function handlers(string $currentUser): array
    {
        $guard = new UserManagementGuard($this->userAdministration, $currentUser);
        $inviteLinkFlash = new InviteLinkFlash($this->requestStack);

        return [
            'add' => new AddUserCommandHandler($guard, $this->userAdministration, $inviteLinkFlash, $this->clock),
            'invite' => new InviteUserCommandHandler($guard, $this->userAdministration, $inviteLinkFlash, $this->clock),
            'status' => new ChangeUserStatusCommandHandler($guard, $this->userAdministration),
            'remove' => new RemoveUserCommandHandler($guard, $this->userAdministration, $this->clock),
        ];
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->createProjectDir();
        $this->clock = PausedClock::fromString('2026-10-15 12:00:00');
        $this->registry = new UserRegistry($this->getUsersDir());
        $this->registry->add('alice', true, $this->clock->getCurrentDateTimeImmutable());
        $this->userAdministration = new UserAdministration(
            registry: $this->registry,
            userUrlTemplate: UserUrlTemplate::fromString('https://{user}.example.com'),
            provisioner: new SpyUserProvisioner(),
        );

        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);
        $this->requestStack = new RequestStack();
        $this->requestStack->push($request);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeProjectDir();

        parent::tearDown();
    }
}
