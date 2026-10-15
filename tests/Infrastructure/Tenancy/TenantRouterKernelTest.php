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
    private const array VARIABLES = ['DATABASE_DIRECTORY', 'APP_URL', 'STRAVA_CLIENT_ID', 'AI_API_KEY', 'DREEVE_USER'];

    /** @var array<string, FakeTenantKernel> */
    private array $createdKernels = [];
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

        // Kernels are booted once per user and reused afterwards.
        $this->assertSame(['alice', 'bob'], array_keys($this->createdKernels));
        $this->assertSame(2, $this->createdKernels['alice']->handledRequests);
        $this->assertSame(1, $this->createdKernels['bob']->handledRequests);
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

        $tenants = Tenants::discover(__DIR__.'/fixtures/valid');
        $this->routerKernel = new TenantRouterKernel(
            tenants: $tenants,
            tenantEnvironment: new TenantEnvironment($tenants),
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
}
