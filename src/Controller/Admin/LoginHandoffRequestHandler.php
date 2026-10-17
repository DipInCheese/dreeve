<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Infrastructure\Security\AdminUserProvider;
use App\Infrastructure\Tenancy\FrontDoor\LoginHandoff;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\String\DataDir;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

/**
 * Signs the user in with the one-time pass the sign-in page on the bare address handed out.
 */
#[AsController]
final readonly class LoginHandoffRequestHandler
{
    public function __construct(
        private Security $security,
        private AdminUserProvider $userProvider,
        private DataDir $dataDir,
        private Clock $clock,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire('%app.user%')]
        private string $tenantName = '',
    ) {
    }

    #[Route(path: '/admin/login/handoff', name: 'admin_login_handoff', methods: ['GET'], priority: 20)]
    public function handle(Request $request): Response
    {
        if ('' === $this->tenantName) {
            throw new NotFoundHttpException();
        }

        $rememberMe = LoginHandoff::inDirectory((string) $this->dataDir)->consume(
            (string) $request->query->get('token', ''),
            $this->clock->getCurrentDateTimeImmutable(),
        );
        if (null === $rememberMe) {
            // Expired or already used: back to the sign-in page.
            return $this->noStore(new RedirectResponse('/'));
        }

        $badges = [];
        if ($rememberMe) {
            $badges[] = new RememberMeBadge()->enable();
        }
        $this->security->login($this->userProvider->loadUserByIdentifier($this->tenantName), 'form_login', 'main', $badges);

        return $this->noStore(new RedirectResponse($this->urlGenerator->generate('home')));
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
