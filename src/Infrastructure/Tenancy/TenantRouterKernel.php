<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * Front kernel of a multi-user installation. It picks the user by the hostname of the
 * request and hands the request to that user's own kernel. Every user's kernel has its
 * own container, database, files and caches, so users never see each other's data.
 *
 * Users are added, disabled and removed while the app runs: the users are reloaded
 * whenever the user registry changes.
 */
final class TenantRouterKernel implements HttpKernelInterface, TerminableInterface
{
    private ?Tenants $tenants = null;
    private ?string $loadedRevision = null;
    /** @var array<string, HttpKernelInterface&TerminableInterface> */
    private array $kernels = [];
    private (HttpKernelInterface&TerminableInterface)|null $kernelOfLastRequest = null;

    /**
     * @param \Closure(): Tenants                                         $loadTenants
     * @param \Closure(): string                                          $getRevision
     * @param \Closure(Tenant): (HttpKernelInterface&TerminableInterface) $kernelFactory
     */
    public function __construct(
        private readonly \Closure $loadTenants,
        private readonly \Closure $getRevision,
        private readonly TenantEnvironment $tenantEnvironment,
        private readonly \Closure $kernelFactory,
    ) {
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        $this->kernelOfLastRequest = null;

        if (!($tenant = $this->getTenants()->findByHost($request->getHost())) instanceof Tenant) {
            return new Response(
                content: 'Not found',
                status: Response::HTTP_NOT_FOUND,
                headers: ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store'],
            );
        }

        $this->tenantEnvironment->activate($tenant);
        $kernel = $this->kernels[$tenant->getName()] ??= ($this->kernelFactory)($tenant);
        $this->kernelOfLastRequest = $kernel;

        return $kernel->handle($request, $type, $catch);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->kernelOfLastRequest?->terminate($request, $response);
    }

    private function getTenants(): Tenants
    {
        $revision = ($this->getRevision)();
        if ($this->tenants instanceof Tenants && $revision === $this->loadedRevision) {
            return $this->tenants;
        }

        $this->tenants = ($this->loadTenants)();
        $this->loadedRevision = $revision;

        // Shut down the kernels of users that were disabled or removed.
        foreach (array_diff(array_keys($this->kernels), $this->tenants->getNames()) as $name) {
            if (method_exists($this->kernels[$name], 'shutdown')) {
                $this->kernels[$name]->shutdown();
            }
            unset($this->kernels[$name]);
        }

        return $this->tenants;
    }
}
