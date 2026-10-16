<?php

declare(strict_types=1);

namespace App\Controller\Admin\Garmin;

use App\Infrastructure\HealthSync\GarminHistory;
use App\Infrastructure\HealthSync\GarminLogin;
use App\Infrastructure\HealthSync\GarminStatus;
use App\Infrastructure\HealthSync\HealthSync;
use App\Infrastructure\HealthSync\HealthSyncFailed;
use App\Infrastructure\Http\HtmlResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Connects the user's Garmin account. The email and password go to health-sync once, which
 * logs in on Garmin and keeps only the resulting session. Nothing is stored here.
 */
#[AsController]
final readonly class GarminConnectionRequestHandler
{
    public const string CSRF_TOKEN_ID = 'garmin-connection';

    public function __construct(
        private HealthSync $healthSync,
        private Environment $twig,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/admin/garmin', name: 'admin_garmin', methods: ['GET'], priority: 10)]
    public function handle(): Response
    {
        $this->guardAvailable();

        $status = null;
        $unreachable = false;
        try {
            $status = $this->healthSync->status();
        } catch (HealthSyncFailed) {
            $unreachable = true;
        }

        return $this->noStore(new HtmlResponse($this->twig->render('html/admin/page/garmin/garmin-connection.html.twig', [
            'status' => $status ?? GarminStatus::fromArray([]),
            'unreachable' => $unreachable,
            'histories' => GarminHistory::cases(),
            'csrfTokenId' => self::CSRF_TOKEN_ID,
        ])));
    }

    #[Route(path: '/admin/garmin/connect', name: 'admin_garmin_connect', methods: ['POST'], priority: 10)]
    public function connect(Request $request): Response
    {
        $this->guardAvailable();
        if (!$this->hasValidCsrfToken($request)) {
            return $this->redirectWithMessage($request, 'error', $this->translator->trans('Your form expired, please try again.', [], 'admin'));
        }

        $email = trim((string) $request->request->get('email', ''));
        $password = (string) $request->request->get('password', '');
        $history = GarminHistory::tryFrom((string) $request->request->get('history', '')) ?? GarminHistory::THREE_MONTHS;
        if ('' === $email || '' === $password) {
            return $this->redirectWithMessage($request, 'error', $this->translator->trans('Fill in the email and password of your Garmin account.', [], 'admin'));
        }

        try {
            $login = $this->healthSync->connect($email, $password, $history);
        } catch (HealthSyncFailed $failure) {
            return $this->redirectWithMessage($request, 'error', $this->explain($failure));
        }

        if (GarminLogin::CODE_REQUIRED === $login) {
            return $this->redirectToPage();
        }

        return $this->redirectWithMessage($request, 'success', $this->translator->trans('Garmin is connected. Your activities are on their way, the first ones show up within minutes.', [], 'admin'));
    }

    #[Route(path: '/admin/garmin/code', name: 'admin_garmin_code', methods: ['POST'], priority: 10)]
    public function confirmCode(Request $request): Response
    {
        $this->guardAvailable();
        if (!$this->hasValidCsrfToken($request)) {
            return $this->redirectWithMessage($request, 'error', $this->translator->trans('Your form expired, please try again.', [], 'admin'));
        }

        try {
            $this->healthSync->confirmCode(trim((string) $request->request->get('code', '')));
        } catch (HealthSyncFailed $failure) {
            return $this->redirectWithMessage($request, 'error', $this->explain($failure));
        }

        return $this->redirectWithMessage($request, 'success', $this->translator->trans('Garmin is connected. Your activities are on their way, the first ones show up within minutes.', [], 'admin'));
    }

    #[Route(path: '/admin/garmin/sync', name: 'admin_garmin_sync', methods: ['POST'], priority: 10)]
    public function syncNow(Request $request): Response
    {
        $this->guardAvailable();
        if (!$this->hasValidCsrfToken($request)) {
            return $this->redirectWithMessage($request, 'error', $this->translator->trans('Your form expired, please try again.', [], 'admin'));
        }

        try {
            $this->healthSync->syncNow();
        } catch (HealthSyncFailed $failure) {
            return $this->redirectWithMessage($request, 'error', $this->explain($failure));
        }

        return $this->redirectWithMessage($request, 'success', $this->translator->trans('Syncing with Garmin. New activities show up as soon as they are fetched.', [], 'admin'));
    }

    #[Route(path: '/admin/garmin/disconnect', name: 'admin_garmin_disconnect', methods: ['POST'], priority: 10)]
    public function disconnect(Request $request): Response
    {
        $this->guardAvailable();
        if (!$this->hasValidCsrfToken($request)) {
            return $this->redirectWithMessage($request, 'error', $this->translator->trans('Your form expired, please try again.', [], 'admin'));
        }

        try {
            $this->healthSync->disconnect();
        } catch (HealthSyncFailed $failure) {
            return $this->redirectWithMessage($request, 'error', $this->explain($failure));
        }

        return $this->redirectWithMessage($request, 'success', $this->translator->trans('Garmin is disconnected. Your synced activities are kept.', [], 'admin'));
    }

    private function explain(HealthSyncFailed $failure): string
    {
        return match ($failure->getReason()) {
            'login_failed' => $this->translator->trans('Garmin did not accept this email and password.', [], 'admin'),
            'mfa_failed' => $this->translator->trans('Garmin did not accept this code. Check it and try again.', [], 'admin'),
            'mfa_expired' => $this->translator->trans('The code took too long. Log in again to get a new one.', [], 'admin'),
            'too_many_attempts' => $this->translator->trans('Too many attempts. Wait 15 minutes, so Garmin does not lock your account.', [], 'admin'),
            'garmin_unavailable' => $this->translator->trans('Garmin can not be reached right now. Try again in a few minutes.', [], 'admin'),
            'not_connected' => $this->translator->trans('Garmin is not connected.', [], 'admin'),
            'bad_request' => $failure->getMessage(),
            default => $this->translator->trans('The Garmin sync service can not be reached. Check that the health-sync container is running.', [], 'admin'),
        };
    }

    private function guardAvailable(): void
    {
        if (!$this->healthSync->isAvailable()) {
            throw new NotFoundHttpException('Garmin is not available on this installation');
        }
    }

    private function hasValidCsrfToken(Request $request): bool
    {
        return $this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->request->get('_csrf_token', '')));
    }

    private function redirectWithMessage(Request $request, string $type, string $message): Response
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }

        return $this->redirectToPage();
    }

    private function redirectToPage(): Response
    {
        return $this->noStore(new RedirectResponse($this->urlGenerator->generate('admin_garmin')));
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
