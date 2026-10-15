<?php

namespace App\Tests\Controller\Admin\Strava;

use App\Controller\Admin\Strava\StravaConnectionRequestHandler;
use App\Domain\Activity\ActivityIdRepository;
use App\Infrastructure\Tenancy\Registry\UserRegistry;
use App\Infrastructure\Tenancy\StravaConnection;
use App\Infrastructure\Tenancy\UserAdministration;
use App\Infrastructure\Tenancy\UserUrlTemplate;
use App\Infrastructure\ValueObject\String\DataDir;
use App\Tests\ContainerTestCase;
use App\Tests\Infrastructure\Tenancy\ProvideTemporaryProjectDir;
use App\Tests\Infrastructure\Tenancy\SpyUserProvisioner;
use App\Tests\Infrastructure\Time\Clock\PausedClock;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class StravaConnectionRequestHandlerTest extends ContainerTestCase
{
    use ProvideTemporaryProjectDir;

    private const string CLIENT_SECRET = 'abcdef0123456789abcdef0123456789abcdef01';

    private MockHandler $stravaApi;
    /** @var array<int, array<string, mixed>> */
    private array $stravaRequests = [];
    private Session $session;
    private bool $csrfTokenIsValid = true;

    public function testHandleShowsWhatToFillInOnStrava(): void
    {
        $content = (string) $this->handler()->handle($this->request('GET', '/admin/settings/strava'))->getContent();

        $this->assertStringContainsString('https://www.strava.com/settings/api', $content);
        $this->assertStringContainsString('https://dreeve.example.com/alice', $content);
        $this->assertStringContainsString('dreeve.example.com', $content);
        $this->assertStringContainsString('name="clientId"', $content);
    }

    public function testConnectRedirectsToStrava(): void
    {
        $response = $this->handler()->connect($this->request('POST', '/admin/settings/strava', ['clientId' => '12345', 'clientSecret' => self::CLIENT_SECRET]));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringStartsWith('https://www.strava.com/oauth/authorize?', $response->getTargetUrl());
        parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('12345', $query['client_id']);
        $this->assertSame('activity:read_all', $query['scope']);
        $this->assertSame('code', $query['response_type']);
        $this->assertStringEndsWith('/admin/settings/strava/callback', (string) $query['redirect_uri']);
        $this->assertSame(64, strlen((string) $query['state']));
        $this->assertSame($query['state'], $this->session->get('strava_connection_state'));

        // The application is stored, but nothing is connected yet.
        $connection = StravaConnection::read($this->getUserDir());
        $this->assertSame('12345', $connection?->getClientId());
        $this->assertFalse($connection?->isConnected());
    }

    public function testConnectRejectsInvalidApplications(): void
    {
        $response = $this->handler()->connect($this->request('POST', '/admin/settings/strava', ['clientId' => 'abc', 'clientSecret' => 'short']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringEndsWith('/admin/settings/strava', $response->getTargetUrl());
        $this->assertNotEmpty($this->session->getFlashBag()->get('error'));
        $this->assertNull(StravaConnection::read($this->getUserDir()));
    }

    public function testConnectRequiresACsrfToken(): void
    {
        $this->csrfTokenIsValid = false;

        $response = $this->handler()->connect($this->request('POST', '/admin/settings/strava', ['clientId' => '12345', 'clientSecret' => self::CLIENT_SECRET]));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringEndsWith('/admin/settings/strava', $response->getTargetUrl());
        $this->assertNotEmpty($this->session->getFlashBag()->get('error'));
        $this->assertNull(StravaConnection::read($this->getUserDir()));
        $this->assertFalse($this->session->has('strava_connection_state'));
    }

    public function testDisconnectRequiresACsrfToken(): void
    {
        StravaConnection::forApplication('12345', self::CLIENT_SECRET)
            ->withAuthorization('the-refresh-token', 'Alice', new \DateTimeImmutable())
            ->write($this->getUserDir());
        $this->csrfTokenIsValid = false;

        $this->handler()->disconnect($this->request('POST', '/admin/settings/strava/disconnect'));

        $this->assertTrue(StravaConnection::read($this->getUserDir())?->isConnected());
        $this->assertEmpty($this->stravaRequests);
    }

    public function testCallbackConnectsStrava(): void
    {
        StravaConnection::forApplication('12345', self::CLIENT_SECRET)->write($this->getUserDir());
        $this->session->set('strava_connection_state', 'the-state');
        $this->stravaApi->append(new GuzzleResponse(200, [], (string) json_encode([
            'refresh_token' => 'the-refresh-token',
            'athlete' => ['firstname' => 'Alice', 'lastname' => 'Example'],
        ])));

        $response = $this->handler()->callback($this->request('GET', '/admin/settings/strava/callback', query: [
            'state' => 'the-state', 'code' => 'the-code', 'scope' => 'read,activity:read_all',
        ]));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $connection = StravaConnection::read($this->getUserDir());
        $this->assertTrue($connection?->isConnected());
        $this->assertSame('Alice Example', $connection?->getAthleteName());
        $this->assertTrue(StravaConnection::isImportRequested($this->getUserDir()));
        $this->assertNotEmpty($this->session->getFlashBag()->get('success'));
        // The state works once.
        $this->assertFalse($this->session->has('strava_connection_state'));

        parse_str((string) $this->stravaRequests[0]['request']->getBody(), $body);
        $this->assertSame(['client_id' => '12345', 'client_secret' => self::CLIENT_SECRET, 'code' => 'the-code', 'grant_type' => 'authorization_code'], $body);
    }

    public function testCallbackRejectsAnUnknownState(): void
    {
        StravaConnection::forApplication('12345', self::CLIENT_SECRET)->write($this->getUserDir());
        $this->session->set('strava_connection_state', 'the-state');

        $this->handler()->callback($this->request('GET', '/admin/settings/strava/callback', query: [
            'state' => 'forged-state', 'code' => 'the-code', 'scope' => 'read,activity:read_all',
        ]));

        $this->assertFalse(StravaConnection::read($this->getUserDir())?->isConnected());
        $this->assertEmpty($this->stravaRequests, 'Strava must not be called');
        $this->assertNotEmpty($this->session->getFlashBag()->get('error'));
    }

    public function testCallbackWithoutStartedConnection(): void
    {
        $this->handler()->callback($this->request('GET', '/admin/settings/strava/callback', query: ['state' => '', 'code' => 'the-code']));

        $this->assertNull(StravaConnection::read($this->getUserDir()));
        $this->assertEmpty($this->stravaRequests);
    }

    public function testCallbackRequiresTheActivityScope(): void
    {
        StravaConnection::forApplication('12345', self::CLIENT_SECRET)->write($this->getUserDir());
        $this->session->set('strava_connection_state', 'the-state');

        $this->handler()->callback($this->request('GET', '/admin/settings/strava/callback', query: [
            'state' => 'the-state', 'code' => 'the-code', 'scope' => 'read',
        ]));

        $this->assertFalse(StravaConnection::read($this->getUserDir())?->isConnected());
        $this->assertEmpty($this->stravaRequests);
    }

    public function testCallbackWhenAccessWasDenied(): void
    {
        StravaConnection::forApplication('12345', self::CLIENT_SECRET)->write($this->getUserDir());
        $this->session->set('strava_connection_state', 'the-state');

        $this->handler()->callback($this->request('GET', '/admin/settings/strava/callback', query: ['state' => 'the-state', 'error' => 'access_denied']));

        $this->assertFalse(StravaConnection::read($this->getUserDir())?->isConnected());
        $this->assertNotEmpty($this->session->getFlashBag()->get('warning'));
    }

    public function testCallbackWhenStravaRejectsTheCode(): void
    {
        StravaConnection::forApplication('12345', self::CLIENT_SECRET)->write($this->getUserDir());
        $this->session->set('strava_connection_state', 'the-state');
        $this->stravaApi->append(new GuzzleResponse(400, [], '{"message":"Bad Request"}'));

        $this->handler()->callback($this->request('GET', '/admin/settings/strava/callback', query: [
            'state' => 'the-state', 'code' => 'the-code', 'scope' => 'read,activity:read_all',
        ]));

        $this->assertFalse(StravaConnection::read($this->getUserDir())?->isConnected());
        $this->assertNotEmpty($this->session->getFlashBag()->get('error'));
    }

    public function testDisconnect(): void
    {
        StravaConnection::forApplication('12345', self::CLIENT_SECRET)
            ->withAuthorization('the-refresh-token', 'Alice', new \DateTimeImmutable())
            ->write($this->getUserDir());
        $this->stravaApi->append(
            new GuzzleResponse(200, [], '{"access_token":"the-access-token"}'),
            new GuzzleResponse(200, [], '{}'),
        );

        $this->handler()->disconnect($this->request('POST', '/admin/settings/strava/disconnect'));

        $this->assertNull(StravaConnection::read($this->getUserDir()));
        $this->assertSame('https://www.strava.com/oauth/deauthorize', (string) $this->stravaRequests[1]['request']->getUri());
        parse_str((string) $this->stravaRequests[1]['request']->getBody(), $body);
        $this->assertSame(['access_token' => 'the-access-token'], $body);
    }

    public function testDisconnectWhenStravaIsUnreachable(): void
    {
        StravaConnection::forApplication('12345', self::CLIENT_SECRET)
            ->withAuthorization('the-refresh-token', 'Alice', new \DateTimeImmutable())
            ->write($this->getUserDir());
        $this->stravaApi->append(new GuzzleResponse(500));

        $this->handler()->disconnect($this->request('POST', '/admin/settings/strava/disconnect'));

        // Disconnecting in Dreeve always works.
        $this->assertNull(StravaConnection::read($this->getUserDir()));
    }

    public function testNotAvailableOnSingleUserInstallations(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->handler(isMultiUser: false)->handle($this->request('GET', '/admin/settings/strava'));
    }

    private function handler(bool $isMultiUser = true): StravaConnectionRequestHandler
    {
        $test = $this;
        $csrfTokenManager = new readonly class($test) implements CsrfTokenManagerInterface {
            public function __construct(private StravaConnectionRequestHandlerTest $test)
            {
            }

            public function getToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'token');
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'token');
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                return $this->test->isCsrfTokenValid();
            }
        };

        $handlerStack = HandlerStack::create($this->stravaApi);
        $handlerStack->push(Middleware::history($this->stravaRequests));

        return new StravaConnectionRequestHandler(
            twig: $this->getContainer()->get(Environment::class),
            userAdministration: new UserAdministration(
                registry: new UserRegistry($this->getUsersDir()),
                userUrlTemplate: $isMultiUser ? UserUrlTemplate::fromString('https://dreeve.example.com/{user}') : null,
                provisioner: new SpyUserProvisioner(),
            ),
            csrfTokenManager: $csrfTokenManager,
            urlGenerator: $this->getContainer()->get(UrlGeneratorInterface::class),
            translator: $this->getContainer()->get(TranslatorInterface::class),
            activityIdRepository: $this->getContainer()->get(ActivityIdRepository::class),
            client: new Client(['handler' => $handlerStack]),
            clock: PausedClock::fromString('2026-10-15 12:00:00'),
            dataDir: DataDir::fromString($this->getUserDir()),
            currentUser: 'alice',
        );
    }

    public function isCsrfTokenValid(): bool
    {
        return $this->csrfTokenIsValid;
    }

    /**
     * @param array<string, string> $parameters
     * @param array<string, string> $query
     */
    private function request(string $method, string $uri, array $parameters = [], array $query = []): Request
    {
        $request = Request::create('https://dreeve.example.com'.$uri.($query ? '?'.http_build_query($query) : ''), $method, $parameters + ['_csrf_token' => 'token']);
        $request->setSession($this->session);
        $this->getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function getUserDir(): string
    {
        return $this->getUsersDir().'/alice';
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->createProjectDir();
        mkdir($this->getUserDir());
        $this->stravaApi = new MockHandler();
        $this->session = new Session(new MockArraySessionStorage());
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeProjectDir();

        parent::tearDown();
    }
}
