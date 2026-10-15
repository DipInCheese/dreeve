<?php

declare(strict_types=1);

namespace App\Domain\UserManagement;

use App\Infrastructure\CQRS\Command\CouldNotProcessCommand;
use App\Infrastructure\Tenancy\UserAdministration;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Only the owner of a multi-user installation can manage users.
 */
final readonly class UserManagementGuard
{
    public function __construct(
        private UserAdministration $userAdministration,
        #[Autowire('%app.user%')]
        private string $currentUser,
    ) {
    }

    public function isAllowed(): bool
    {
        return $this->userAdministration->isMultiUser() && $this->userAdministration->isOwner($this->currentUser);
    }

    public function guard(): void
    {
        if (!$this->isAllowed()) {
            throw CouldNotProcessCommand::withReason('Only the owner of this installation can manage users.');
        }
    }

    public function getCurrentUser(): string
    {
        return $this->currentUser;
    }
}
