<?php

declare(strict_types=1);

namespace App\Domain\UserManagement;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * Invite links are shown to the owner exactly once, right after they are created.
 * Only a hash of the token is stored, the link itself only lives in this one-time flash message.
 */
final readonly class InviteLinkFlash
{
    public const string TYPE = 'invite-link';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function add(string $name, #[\SensitiveParameter] string $inviteUrl): void
    {
        $session = $this->requestStack->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add(self::TYPE, ['name' => $name, 'url' => $inviteUrl]);
        }
    }
}
