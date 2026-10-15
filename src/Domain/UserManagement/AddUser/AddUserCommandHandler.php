<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\AddUser;

use App\Domain\UserManagement\InviteLinkFlash;
use App\Domain\UserManagement\UserManagementGuard;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\CQRS\Command\CouldNotProcessCommand;
use App\Infrastructure\Tenancy\Registry\UserRegistryException;
use App\Infrastructure\Tenancy\UserAdministration;
use App\Infrastructure\Time\Clock\Clock;

final readonly class AddUserCommandHandler implements CommandHandler
{
    public function __construct(
        private UserManagementGuard $guard,
        private UserAdministration $userAdministration,
        private InviteLinkFlash $inviteLinkFlash,
        private Clock $clock,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof AddUser);
        $this->guard->guard();

        try {
            $inviteUrl = $this->userAdministration->addUser($command->getName(), $this->clock->getCurrentDateTimeImmutable());
        } catch (UserRegistryException $e) {
            throw CouldNotProcessCommand::withReason($e->getMessage());
        }

        $this->inviteLinkFlash->add($command->getName(), $inviteUrl);
    }
}
