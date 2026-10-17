<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Infrastructure\Tenancy\FrontDoor\FrontDoor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Users that live under a path (https://dreeve.example.com/alice) sign in on the bare address,
 * so signing out takes them back there, and the bare address no longer sends them to their pages.
 */
#[AsEventListener(event: LogoutEvent::class, priority: -100)]
final readonly class BackToTheFrontDoorOnLogout
{
    public function __construct(
        #[Autowire('%app.cookie_path%')]
        private string $cookiePath = '/',
    ) {
    }

    public function __invoke(LogoutEvent $event): void
    {
        if ('/' === $this->cookiePath) {
            return;
        }

        $response = new RedirectResponse('/');
        $response->headers->clearCookie(FrontDoor::USER_COOKIE, '/');
        $event->setResponse($response);
    }
}
