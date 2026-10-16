<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Infrastructure\Config\DemoMode;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class Visitor
{
    public function __construct(
        private Security $security,
        private DemoMode $demoMode,
    ) {
    }

    public function isAuthenticated(): bool
    {
        return $this->security->getUser() instanceof UserInterface;
    }

    public function isTrusted(): bool
    {
        return !$this->demoMode->isEnabled() || $this->isAuthenticated();
    }

    public function canDownloadOriginalFiles(): bool
    {
        // Every page requires a login, there are no anonymous visitors anymore.
        return $this->isAuthenticated();
    }
}
