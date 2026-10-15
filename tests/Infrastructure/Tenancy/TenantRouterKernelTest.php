<?php

namespace App\Tests\Infrastructure\Tenancy;

use App\Infrastructure\Tenancy\Tenant;
use App\Infrastructure\Tenancy\TenantEnvironment;
use App\Infrastructure\Tenancy\TenantRouterKernel;
use App\Infrastructure\Tenancy\Tenants;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

class TenantRouterKernelTest extends TestCase
{
    private const array VARIABLES = ['DATABASE_DIRECTORY', 'ADMIN_USERNAME', 'ADMIN_PASSWORD_HASH', 'APP_URL', 'STRAVA_CLIENT_ID', 'DREEVE_USER'];

    /** @var array<string, FakeTenantKernel> */
    private array $createdKernels = [];
    /** @var Tenant[] */
    private array $tenants;
    private string $revision = 'r1';
    private int $loadCount = 0;
    private TenantRouterKernel $routerKernel;
    /** @var array<string, mixed> */
    private array $originalEnv;
    /** @var array<string, mixed> */
    private array $originalServer;

    public function testHandleRoutesByHost(): void
    {
        $response = $this->routerKernel->handle(Request::create('https://alice.example.com/dashboard'));
        $this->assertSame('alice:/dashboard', $response->getContent());

        $response = $this->routerKernel->handle(Request::create('https://BOB.example.com/activities'));
        $this->assertSame('bob:/activities', $response->getContent());

        $response = $this->routerKernel->handle(Request::create('https://alice.example.com/gear'));
        $this->assertSame('alice:/gear', $response->getContent());

        // Kernels are booted once per user and reused afterwards, users are loaded once.
        $this->assertSame(['alice', 'bob'], array_keys($this->createdKernels));
        $this->assertSame(2, $this->createdKernels['alice']->handledRequests);
        $this->assertSame(1, $this->createdKernels['bob']->handledRequests);
        $this->assertSame(1, $this->loadCount);
    }

    public function testHandleActivatesTheEnvironmentOfTheUser(): void
    {
        $this->routerKernel->handle(Request::create('https://alice.example.com/'));
        $this->assertSame('alice-client', $this->createdKernels['alice']->stravaClientIdDuringRequest);

        $this->routerKernel->handle(Request::create('https://bob.example.com/'));
        $this->assertSame('bob-client', $this->createdKernels['bob']->stravaClientIdDuringRequest);
    }

    public function testHandleUnknownHost(): void
    {
        $response = $this->routerKernel->handle(Request::create('https://unknown.example.com/'));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertSame('Not found', $response->getContent());
        $this->assertEmpty($this->createdKernels);
        $this->assertArrayNotHasKey('DREEVE_USER', $_SERVER);
    }

    public function testUsersAreReloadedWhenTheRegistryChanges(): void
    {
        $this->routerKernel->handle(Request::create('https://alice.example.com/'));
        $this->routerKernel->handle(Request::create('https://bob.example.com/'));

        // Bob is removed and Carol is added.
        $this->tenants = [
            $this->tenants[0],
            Tenant::fromEnvironment('carol', '/users/carol', ['APP_URL' => 'https://carol.example.com']),
        ];
        $this->revision = 'r2';

        $this->assertSame('carol:/', $this->routerKernel->handle(Request::create('https://carol.example.com/'))->getContent());
        $this->assertSame(Response::HTTP_NOT_FOUND, $this->routerKernel->handle(Request::create('https://bob.example.com/'))->getStatusCode());
        $this->assertSame(2, $this->loadCount);
        $this->assertTrue($this->createdKernels['bob']->isShutDown);
        $this->assertFalse($this->createdKernels['alice']->isShutDown);
    }

    public function testTerminate(): void
    {
        $request = Request::create('https://bob.example.com/');
        $response = $this->routerKernel->handle($request);
        $this->routerKernel->terminate($request, $response);

        $this->assertSame(1, $this->createdKernels['bob']->terminatedRequests);

        // Nothing to terminate for a request on an unknown host.
        $request = Request::create('https://unknown.example.com/');
        $response = $this->routerKernel->handle($request);
        $this->routerKernel->terminate($request, $response);

        $this->assertSame(1, $this->createdKernels['bob']->terminatedRequests);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnv = array_intersect_key($_ENV, array_flip(self::VARIABLES));
        $this->originalServer = array_intersect_key($_SERVER, array_flip(self::VARIABLES));
        foreach (self::VARIABLES as $variable) {
            unset($_ENV[$variable], $_SERVER[$variable]);
        }

        $this->tenants = [
            Tenant::fromEnvironment('alice', '/users/alice', ['APP_URL' => 'https://alice.example.com', 'STRAVA_CLIENT_ID' => 'alice-client']),
            Tenant::fromEnvironment('bob', '/users/bob', ['APP_URL' => 'https://bob.example.com', 'STRAVA_CLIENT_ID' => 'bob-client']),
        ];
        $this->routerKernel = new TenantRouterKernel(
            loadTenants: function (): Tenants {
                ++$this->loadCount;

                return Tenants::fromArray($this->tenants);
            },
            getRevision: fn (): string => $this->revision,
            tenantEnvironment: new TenantEnvironment(),
            kernelFactory: fn (Tenant $tenant): FakeTenantKernel => $this->createdKernels[$tenant->getName()] = new FakeTenantKernel($tenant->getName()),
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $variable) {
            unset($_ENV[$variable], $_SERVER[$variable]);
        }
        $_ENV = [...$_ENV, ...$this->originalEnv];
        $_SERVER = [...$_SERVER, ...$this->originalServer];

        parent::tearDown();
    }
}

final class FakeTenantKernel implements HttpKernelInterface, TerminableInterface
{
    public int $handledRequests = 0;
    public int $terminatedRequests = 0;
    public bool $isShutDown = false;
    public ?string $stravaClientIdDuringRequest = null;

    public function __construct(
        private readonly string $name,
    ) {
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        ++$this->handledRequests;
        $this->stravaClientIdDuringRequest = $_SERVER['STRAVA_CLIENT_ID'] ?? null;

        return new Response($this->name.':'.$request->getPathInfo());
    }

    public function terminate(Request $request, Response $response): void
    {
        ++$this->terminatedRequests;
    }

    public function shutdown(): void
    {
        $this->isShutDown = true;
    }
}
