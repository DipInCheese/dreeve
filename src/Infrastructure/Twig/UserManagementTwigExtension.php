<?php

declare(strict_types=1);

namespace App\Infrastructure\Twig;

use App\Domain\UserManagement\UserManagementGuard;
use Twig\Attribute\AsTwigFunction;

final readonly class UserManagementTwigExtension
{
    public function __construct(
        private UserManagementGuard $guard,
    ) {
    }

    #[AsTwigFunction('canManageUsers')]
    public function canManageUsers(): bool
    {
        return $this->guard->isAllowed();
    }
}
