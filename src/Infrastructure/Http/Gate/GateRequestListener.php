<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Gate;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class GateRequestListener implements EventSubscriberInterface
{
    /**
     * @param iterable<Gate> $gates
     */
    public function __construct(
        #[AutowireIterator('app.http.gate')]
        private iterable $gates,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($this->isAlwaysOpen($request->getPathInfo())) {
            return;
        }

        foreach ($this->gates as $gate) {
            $decision = $gate->handle($request);
            if (!$decision->hasBeenApplied()) {
                continue;
            }

            if ($response = $decision->getResponse()) {
                $event->setResponse($response);
            }

            return;
        }
    }

    private function isAlwaysOpen(string $path): bool
    {
        return 1 === preg_match('#^/(_(profiler|wdt)|css|images|js|files)/#', $path)
            || '/api/v1' === $path || str_starts_with($path, '/api/v1/')
            // Invited users of a multi-user installation must be able to pick a password before anything is set up,
            // and the owner must be able to manage users before setting up their own dashboard. Both stay
            // protected: the invite page by its token, the users pages by the firewall and the owner check.
            || str_starts_with($path, '/invite/')
            || '/admin/users' === $path || str_starts_with($path, '/admin/users/');
    }

    /**
     * @codeCoverageIgnore
     */
    public static function getSubscribedEvents(): array
    {
        // Priority 12: after the RouterListener, before the firewall (priority 8)
        // so a gate can preempt the login redirect.
        return [KernelEvents::REQUEST => [['onKernelRequest', 12]]];
    }
}
