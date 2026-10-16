<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use Symfony\Component\HttpFoundation\RedirectResponse;
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
    /** @var array<string, string> */
    private array $kernelFingerprints = [];
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

        $path = (string) parse_url($request->getRequestUri(), PHP_URL_PATH);
        if (!($tenant = $this->getTenants()->findForRequest($request->getHost(), $path)) instanceof Tenant) {
            return new Response(
                content: 'Not found',
                status: Response::HTTP_NOT_FOUND,
                headers: ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store'],
            );
        }

        if ('' !== ($basePath = $tenant->getBasePath())) {
            if ($path === $basePath) {
                $query = $request->getQueryString();

                return new RedirectResponse($basePath.'/'.(null === $query ? '' : '?'.$query), Response::HTTP_MOVED_PERMANENTLY);
            }

            // The user lives under a path, e.g. /alice: make Symfony treat it as the base of the app,
            // so routing ignores it and every URL Symfony generates starts with it.
            $request->server->set('SCRIPT_NAME', $basePath.'/index.php');
            $request->server->set('PHP_SELF', $basePath.'/index.php');
            if ('index.php' !== basename((string) $request->server->get('SCRIPT_FILENAME', ''))) {
                $request->server->set('SCRIPT_FILENAME', 'index.php');
            }
        }

        $this->tenantEnvironment->activate($tenant);
        if (!isset($this->kernels[$tenant->getName()])) {
            $this->kernels[$tenant->getName()] = ($this->kernelFactory)($tenant);
            $this->kernelFingerprints[$tenant->getName()] = $tenant->getFingerprint();
        }
        $kernel = $this->kernels[$tenant->getName()];
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

        // Shut down the kernels of users that were disabled or removed, or whose settings changed
        // (e.g. their .env file was edited): the next request boots a kernel with the new settings.
        foreach (array_keys($this->kernels) as $name) {
            $tenant = in_array($name, $this->tenants->getNames(), true) ? $this->tenants->findByName($name) : null;
            if ($tenant instanceof Tenant && $tenant->getFingerprint() === ($this->kernelFingerprints[$name] ?? null)) {
                continue;
            }

            if (method_exists($this->kernels[$name], 'shutdown')) {
                $this->kernels[$name]->shutdown();
            }
            unset($this->kernels[$name], $this->kernelFingerprints[$name]);
        }

        return $this->tenants;
    }
}
