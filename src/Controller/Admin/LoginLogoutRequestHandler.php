<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Infrastructure\Http\HtmlResponse;
use App\Infrastructure\Tenancy\FrontDoor\FrontDoor;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;

#[AsController]
final readonly class LoginLogoutRequestHandler
{
    public function __construct(
        private Environment $twig,
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire('%app.cookie_path%')]
        private string $cookiePath = '/',
    ) {
    }

    #[Route(path: '/admin/login', name: 'admin_login', methods: ['GET', 'POST'], priority: 10)]
    public function login(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->security->getUser() instanceof UserInterface) {
            return new RedirectResponse($this->urlGenerator->generate('home'));
        }

        // Users that live under a path sign in on the bare address. Forgetting who signed in there
        // makes it show the sign-in page instead of sending the browser straight back here.
        if ('/' !== $this->cookiePath && $request->isMethod('GET')) {
            $response = new RedirectResponse('/');
            $response->headers->clearCookie(FrontDoor::USER_COOKIE, '/');

            return $response;
        }

        return new HtmlResponse($this->twig->render('html/admin/login.html.twig', [
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]));
    }

    #[Route(path: '/admin/logout', name: 'admin_logout', methods: ['POST'], priority: 10)]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the logout key on the firewall.');
    }
}
