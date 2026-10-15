<?php

declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\Http\HtmlResponse;
use App\Infrastructure\Tenancy\Registry\UserRegistry;
use App\Infrastructure\Tenancy\Registry\UserRegistryException;
use App\Infrastructure\Tenancy\UserAdministration;
use App\Infrastructure\Time\Clock\Clock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Lets an invited user of a multi-user installation pick a password.
 */
#[AsController]
final readonly class InviteRequestHandler
{
    public const string CSRF_TOKEN_ID = 'accept-invite';

    public function __construct(
        private Environment $twig,
        private UserAdministration $userAdministration,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private Clock $clock,
        #[Autowire('%app.user%')]
        private string $currentUser,
    ) {
    }

    #[Route(path: '/invite/{token}', name: 'accept_invite', requirements: ['token' => '[A-Za-z0-9_-]{20,100}'], methods: ['GET', 'POST'], priority: 2)]
    public function handle(Request $request, string $token): Response
    {
        if (!$this->userAdministration->isMultiUser() || '' === $this->currentUser) {
            throw new NotFoundHttpException();
        }

        $now = $this->clock->getCurrentDateTimeImmutable();
        if (!$this->userAdministration->isValidInvite($this->currentUser, $token, $now)) {
            return $this->render(['isValid' => false], Response::HTTP_NOT_FOUND);
        }

        $error = null;
        if ($request->isMethod('POST')) {
            $password = (string) $request->request->get('password', '');
            $passwordRepeat = (string) $request->request->get('password_repeat', '');
            $csrfToken = new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->request->get('_csrf_token', ''));

            try {
                if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
                    $error = $this->translator->trans('Invalid CSRF token.', [], 'admin');
                } elseif (!hash_equals($password, $passwordRepeat)) {
                    $error = $this->translator->trans('The passwords do not match.', [], 'admin');
                } else {
                    UserRegistry::guardPassword($password);
                    $this->userAdministration->acceptInvite($this->currentUser, $token, $password, $now);

                    return new RedirectResponse($this->urlGenerator->generate('admin_login'));
                }
            } catch (UserRegistryException) {
                $error = $this->translator->trans('Use at least {minLength} characters.', ['{minLength}' => UserRegistry::MIN_PASSWORD_LENGTH], 'admin');
            }
        }

        return $this->render(['isValid' => true, 'error' => $error], null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(array $context, int $status): HtmlResponse
    {
        $response = new HtmlResponse($this->twig->render('html/invite.html.twig', [
            ...$context,
            'userName' => $this->currentUser,
            'minPasswordLength' => UserRegistry::MIN_PASSWORD_LENGTH,
            'csrfTokenId' => self::CSRF_TOKEN_ID,
        ]));
        $response->setStatusCode($status);
        // The invite token is part of the URL: never cache this page and never send the URL to other sites.
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
