<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\ChangeUserStatus;

use App\Domain\UserManagement\UserManagementGuard;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\CQRS\Command\CouldNotProcessCommand;
use App\Infrastructure\Tenancy\Registry\UserRegistryException;
use App\Infrastructure\Tenancy\UserAdministration;

final readonly class ChangeUserStatusCommandHandler implements CommandHandler
{
    public function __construct(
        private UserManagementGuard $guard,
        private UserAdministration $userAdministration,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof ChangeUserStatus);
        $this->guard->guard();

        try {
            $this->userAdministration->setDisabled($command->getName(), $command->isDisabled());
        } catch (UserRegistryException $e) {
            throw CouldNotProcessCommand::withReason($e->getMessage());
        }
    }
}
