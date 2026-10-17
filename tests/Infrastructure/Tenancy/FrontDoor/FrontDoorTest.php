<?php

namespace App\Tests\Infrastructure\Tenancy\FrontDoor;

use App\Infrastructure\Tenancy\FrontDoor\FrontDoor;
use App\Infrastructure\Tenancy\FrontDoor\LoginHandoff;
use App\Infrastructure\Tenancy\Tenant;
use App\Infrastructure\Tenancy\Tenants;
use App\Infrastructure\Tenancy\TenantSecretFile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;

class FrontDoorTest extends TestCase
{
    private const string PASSWORD = 'correct horse battery';

    private string $usersDirectory;
    private Tenants $tenants;
    private FrontDoor $frontDoor;
    private \DateTimeImmutable $now;

    public function testTheBareAddressShowsTheSignInPage(): void
    {
        $response = $this->get('/');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringContainsString('data-front-door', (string) $response->getContent());
        $this->assertStringContainsString('action="/"', (string) $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNotNull($this->csrfCookie($response));
    }

    public function testASignInHandsTheBrowserOverToTheUsersOwnPath(): void
    {
        $response = $this->signIn('Alice', self::PASSWORD, rememberMe: true);

        $this->assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('/alice/admin/login/handoff?token=', $location);

        $token = substr($location, strlen('/alice/admin/login/handoff?token='));
        $this->assertTrue(LoginHandoff::inDirectory($this->usersDirectory.'/alice')->consume($token, $this->now));
        $this->assertNull(LoginHandoff::inDirectory($this->usersDirectory.'/alice')->consume($token, $this->now), 'A pass works only once');

        $userCookie = $this->cookie($response, FrontDoor::USER_COOKIE);
        $this->assertSame('alice', $userCookie?->getValue());
        $this->assertSame('/', $userCookie?->getPath());
    }

    public function testTheBareAddressTakesASignedInUserStraightToTheirPages(): void
    {
        $request = Request::create('https://dreeve.example.com/');
        $request->cookies->set(FrontDoor::USER_COOKIE, 'bob');

        $response = $this->frontDoor->handle($request, $this->tenants);

        $this->assertSame('/bob/', $response?->headers->get('Location'));
    }

    public function testAWrongPasswordOrUnknownUserGetsTheSameAnswer(): void
    {
        foreach ([['alice', 'wrong password!'], ['nobody', self::PASSWORD]] as [$username, $password]) {
            $response = $this->signIn($username, $password);

            $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
            $this->assertStringContainsString('Wrong user name or password.', (string) $response->getContent());
        }
        $this->assertFileDoesNotExist($this->usersDirectory.'/alice/'.LoginHandoff::FILE_NAME);
    }

    public function testASignInWithoutTheFormsTokenIsRefused(): void
    {
        $request = Request::create('https://dreeve.example.com/', 'POST', ['_username' => 'alice', '_password' => self::PASSWORD]);

        $response = $this->frontDoor->handle($request, $this->tenants);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response?->getStatusCode());
        $this->assertFileDoesNotExist($this->usersDirectory.'/alice/'.LoginHandoff::FILE_NAME);
    }

    public function testItStopsAfterFiveFailedAttempts(): void
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->assertSame(Response::HTTP_UNAUTHORIZED, $this->signIn('alice', 'wrong password '.$attempt)->getStatusCode());
        }

        $response = $this->signIn('alice', self::PASSWORD);

        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        $this->assertFileDoesNotExist($this->usersDirectory.'/alice/'.LoginHandoff::FILE_NAME);

        $this->now = $this->now->modify('+16 minutes');
        $this->assertSame(Response::HTTP_SEE_OTHER, $this->signIn('alice', self::PASSWORD)->getStatusCode());
    }

    public function testAnExpiredPassDoesNotWork(): void
    {
        $token = LoginHandoff::inDirectory($this->usersDirectory.'/alice')->create(false, $this->now);

        $this->assertNull(LoginHandoff::inDirectory($this->usersDirectory.'/alice')->consume($token, $this->now->modify('+2 minutes')));
    }

    public function testAnUnknownPathShowsAFriendlyPage(): void
    {
        $response = $this->get('/some-old-link');

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertStringContainsString('data-front-door-not-found', (string) $response->getContent());
    }

    public function testItLeavesOtherHostsAlone(): void
    {
        $this->assertNull($this->frontDoor->handle(Request::create('https://elsewhere.example.com/'), $this->tenants));
    }

    private function get(string $path): Response
    {
        return $this->frontDoor->handle(Request::create('https://dreeve.example.com'.$path), $this->tenants) ?? throw new \LogicException('No response');
    }

    private function signIn(string $username, string $password, bool $rememberMe = false): Response
    {
        $csrfToken = (string) $this->csrfCookie($this->get('/'))?->getValue();
        $request = Request::create('https://dreeve.example.com/', 'POST', [
            '_username' => $username,
            '_password' => $password,
            '_csrf_token' => $csrfToken,
            ...($rememberMe ? ['_remember_me' => 'on'] : []),
        ]);
        $request->cookies->set('dreeve_front_door', $csrfToken);

        return $this->frontDoor->handle($request, $this->tenants) ?? throw new \LogicException('No response');
    }

    private function csrfCookie(Response $response): ?Cookie
    {
        return $this->cookie($response, 'dreeve_front_door');
    }

    private function cookie(Response $response, string $name): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->usersDirectory = sys_get_temp_dir().'/front-door-'.bin2hex(random_bytes(4));
        $hash = new NativePasswordHasher(cost: 4)->hash(self::PASSWORD);
        $tenants = [];
        foreach (['alice', 'bob'] as $name) {
            mkdir($this->usersDirectory.'/'.$name, 0o777, true);
            TenantSecretFile::write($this->usersDirectory.'/'.$name.'/'.Tenant::ADMIN_PASSWORD_HASH_FILE_NAME, $hash);
            $tenants[] = Tenant::fromEnvironment($name, $this->usersDirectory.'/'.$name, ['APP_URL' => 'https://dreeve.example.com/'.$name]);
        }
        $this->tenants = Tenants::fromArray($tenants);
        $this->now = new \DateTimeImmutable('2026-10-17 20:00:00');
        $this->frontDoor = new FrontDoor(
            projectDir: dirname(__DIR__, 4),
            usersDirectory: $this->usersDirectory,
            now: fn (): \DateTimeImmutable => $this->now,
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->usersDirectory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->usersDirectory);

        parent::tearDown();
    }
}
