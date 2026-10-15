<?php

declare(strict_types=1);

namespace App\Controller\Admin\Strava;

use App\Domain\Activity\ActivityIdRepository;
use App\Infrastructure\Exception\CorruptedData;
use App\Infrastructure\Http\HtmlResponse;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Tenancy\StravaConnection;
use App\Infrastructure\Tenancy\UserAdministration;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\String\DataDir;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
 * Lets a user of a multi-user installation connect their own Strava account from the admin
 * panel: they enter the Client ID and Secret of their own Strava API application, authorize it
 * on Strava, and the import mode switches to Strava. No settings file has to be edited.
 */
#[AsController]
final readonly class StravaConnectionRequestHandler
{
    public const string CSRF_TOKEN_ID = 'strava-connection';
    public const string REQUIRED_SCOPE = 'activity:read_all';
    private const string STATE_SESSION_KEY = 'strava_connection_state';
    private const string STRAVA_BASE_URL = 'https://www.strava.com';

    public function __construct(
        private Environment $twig,
        private UserAdministration $userAdministration,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private ActivityIdRepository $activityIdRepository,
        private Client $client,
        private Clock $clock,
        private DataDir $dataDir,
        #[Autowire('%app.user%')]
        private string $currentUser,
    ) {
    }

    #[Route(path: '/admin/settings/strava', name: 'admin_strava_connection', methods: ['GET'], priority: 10)]
    public function handle(Request $request): Response
    {
        $this->guardMultiUser();
        $connection = StravaConnection::read((string) $this->dataDir);

        return $this->noStore(new HtmlResponse($this->twig->render('html/admin/page/settings/strava-connection.html.twig', [
            'connection' => $connection,
            'isImportRunning' => StravaConnection::isImportRequested((string) $this->dataDir),
            'numberOfActivities' => $this->activityIdRepository->count(),
            'callbackDomain' => $request->getHost(),
            'websiteUrl' => $this->userAdministration->getUserUrl($this->currentUser),
            'csrfTokenId' => self::CSRF_TOKEN_ID,
        ])));
    }

    #[Route(path: '/admin/settings/strava', name: 'admin_strava_connect', methods: ['POST'], priority: 10)]
    public function connect(Request $request): Response
    {
        $this->guardMultiUser();
        if (!$this->hasValidCsrfToken($request)) {
            return $this->redirectBecauseOfInvalidCsrfToken($request);
        }

        $clientId = trim((string) $request->request->get('clientId', ''));
        $clientSecret = trim((string) $request->request->get('clientSecret', ''));
        $existingConnection = StravaConnection::read((string) $this->dataDir);
        if ('' === $clientSecret && $existingConnection?->getClientId() === $clientId) {
            // Reconnecting with the same application: the secret is never shown again, keep the stored one.
            $clientSecret = $existingConnection->getClientSecret();
        }

        if (!StravaConnection::isValidClientId($clientId) || !StravaConnection::isValidClientSecret($clientSecret)) {
            return $this->redirectWithMessage($request, 'error', $this->translator->trans('Fill in the Client ID and Client Secret of your Strava API application, exactly as Strava shows them.', [], 'admin'));
        }

        // Until the new authorization succeeds, the application is stored without a refresh token.
        StravaConnection::forApplication($clientId, $clientSecret)->write((string) $this->dataDir);

        $state = bin2hex(random_bytes(32));
        $request->getSession()->set(self::STATE_SESSION_KEY, $state);

        return new RedirectResponse(self::STRAVA_BASE_URL.'/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->urlGenerator->generate('admin_strava_callback', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'approval_prompt' => 'force',
            'scope' => self::REQUIRED_SCOPE,
            'state' => $state,
        ]));
    }

    #[Route(path: '/admin/settings/strava/callback', name: 'admin_strava_callback', methods: ['GET'], priority: 10)]
    public function callback(Request $request): Response
    {
        $this->guardMultiUser();

        // The state proves this callback belongs to the authorization this user started here. It works once.
        $expectedState = (string) $request->getSession()->get(self::STATE_SESSION_KEY, '');
        $request->getSession()->remove(self::STATE_SESSION_KEY);
        if ('' === $expectedState || !hash_equals($expectedState, (string) $request->query->get('state', ''))) {
            return $this->redirectWithMessage($request, 'error', $this->translator->trans('The connection with Strava could not be verified. Please try again.', [], 'admin'));
        }

        if ($request->query->has('error')) {
            return $this->redirectWithMessage($request, 'warning', $this->translator->trans('You did not give Dreeve access to Strava. Nothing was changed.', [], 'admin'));
        }

        $scopes = explode(',', (string) $request->query->get('scope', ''));
        if (!in_array(self::REQUIRED_SCOPE, $scopes, true)) {
            return $this->redirectWithMessage($request, 'error', $this->translator->trans('Leave "View data about your private activities" checked on Strava, Dreeve needs it to import your activities.', [], 'admin'));
        }

        $connection = StravaConnection::read((string) $this->dataDir);
        $code = (string) $request->query->get('code', '');
        if (!$connection instanceof StravaConnection || '' === $code) {
            return $this->redirectWithMessage($request, 'error', $this->translator->trans('The connection with Strava could not be verified. Please try again.', [], 'admin'));
        }

        try {
            $tokenResponse = Json::decode($this->client->post(self::STRAVA_BASE_URL.'/oauth/token', [
                RequestOptions::FORM_PARAMS => [
                    'client_id' => $connection->getClientId(),
                    'client_secret' => $connection->getClientSecret(),
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                ],
            ])->getBody()->getContents());
        } catch (GuzzleException|CorruptedData) {
            return $this->redirectWithMessage($request, 'error', $this->translator->trans('Strava did not accept the connection. Check the Client ID and Client Secret and try again.', [], 'admin'));
        }

        $refreshToken = is_array($tokenResponse) ? (string) ($tokenResponse['refresh_token'] ?? '') : '';
        if ('' === $refreshToken) {
            return $this->redirectWithMessage($request, 'error', $this->translator->trans('Strava did not accept the connection. Check the Client ID and Client Secret and try again.', [], 'admin'));
        }
        $athlete = is_array($tokenResponse['athlete'] ?? null) ? $tokenResponse['athlete'] : [];
        $athleteName = trim(sprintf('%s %s', $athlete['firstname'] ?? '', $athlete['lastname'] ?? ''));

        $connection
            ->withAuthorization($refreshToken, $athleteName, $this->clock->getCurrentDateTimeImmutable())
            ->write((string) $this->dataDir);
        StravaConnection::requestImport((string) $this->dataDir);

        return $this->redirectWithMessage($request, 'success', $this->translator->trans('Strava is connected. Your activities are being imported, this can take a while.', [], 'admin'));
    }

    #[Route(path: '/admin/settings/strava/disconnect', name: 'admin_strava_disconnect', methods: ['POST'], priority: 10)]
    public function disconnect(Request $request): Response
    {
        $this->guardMultiUser();
        if (!$this->hasValidCsrfToken($request)) {
            return $this->redirectBecauseOfInvalidCsrfToken($request);
        }

        $connection = StravaConnection::read((string) $this->dataDir);
        if ($connection?->isConnected()) {
            // Best effort: also revoke Dreeve's access on Strava's side.
            try {
                $tokenResponse = Json::decode($this->client->post(self::STRAVA_BASE_URL.'/oauth/token', [
                    RequestOptions::FORM_PARAMS => [
                        'client_id' => $connection->getClientId(),
                        'client_secret' => $connection->getClientSecret(),
                        'refresh_token' => $connection->getRefreshToken(),
                        'grant_type' => 'refresh_token',
                    ],
                ])->getBody()->getContents());
                if (is_array($tokenResponse) && isset($tokenResponse['access_token'])) {
                    $this->client->post(self::STRAVA_BASE_URL.'/oauth/deauthorize', [
                        RequestOptions::FORM_PARAMS => ['access_token' => (string) $tokenResponse['access_token']],
                    ]);
                }
            } catch (GuzzleException|CorruptedData) {
            }
        }
        StravaConnection::delete((string) $this->dataDir);

        return $this->redirectWithMessage($request, 'success', $this->translator->trans('Strava is disconnected. Your imported activities are kept.', [], 'admin'));
    }

    private function guardMultiUser(): void
    {
        // Single-user installations configure Strava in their .env file.
        if (!$this->userAdministration->isMultiUser() || '' === $this->currentUser) {
            throw new NotFoundHttpException();
        }
    }

    private function hasValidCsrfToken(Request $request): bool
    {
        return $this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->request->get('_csrf_token', '')));
    }

    private function redirectBecauseOfInvalidCsrfToken(Request $request): Response
    {
        // Nothing is changed. Usually an old form, e.g. a page that was open for a long time.
        return $this->redirectWithMessage($request, 'error', $this->translator->trans('Your form expired, please try again.', [], 'admin'));
    }

    private function redirectWithMessage(Request $request, string $type, string $message): Response
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }

        return $this->noStore(new RedirectResponse($this->urlGenerator->generate('admin_strava_connection')));
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
