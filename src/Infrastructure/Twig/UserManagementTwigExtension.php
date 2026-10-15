<?php

declare(strict_types=1);

namespace App\Infrastructure\Twig;

use App\Domain\UserManagement\UserManagementGuard;
use App\Infrastructure\Tenancy\UserAdministration;
use Twig\Attribute\AsTwigFunction;

final readonly class UserManagementTwigExtension
{
    public function __construct(
        private UserManagementGuard $guard,
        private UserAdministration $userAdministration,
    ) {
    }

    /**
     * Users of a multi-user installation connect Strava from their admin panel.
     */
    #[AsTwigFunction('canConnectStrava')]
    public function canConnectStrava(): bool
    {
        return $this->userAdministration->isMultiUser() && '' !== $this->guard->getCurrentUser();
    }

    #[AsTwigFunction('canManageUsers')]
    public function canManageUsers(): bool
    {
        return $this->guard->isAllowed();
    }
}
