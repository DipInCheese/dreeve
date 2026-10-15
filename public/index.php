<?php

use App\Infrastructure\Tenancy\Registry\UserRegistry;
use App\Infrastructure\Tenancy\Tenant;
use App\Infrastructure\Tenancy\TenantEnvironment;
use App\Infrastructure\Tenancy\TenantRouterKernel;
use App\Infrastructure\Tenancy\Tenants;
use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    $environment = [...getenv(), ...$context];
    if (!Tenants::discover(dirname(__DIR__), $environment)->isMultiUser()) {
        return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
    }

    // Multi-user installation: every user is served by its own kernel, picked by hostname.
    $registry = new UserRegistry(Tenants::getUsersDirectory(dirname(__DIR__)));

    return new TenantRouterKernel(
        loadTenants: static fn (): Tenants => Tenants::discover(dirname(__DIR__), $environment)->withSecrets(),
        getRevision: $registry->getRevision(...),
        tenantEnvironment: new TenantEnvironment(),
        kernelFactory: static fn (Tenant $tenant): Kernel => new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG'], $tenant),
    );
};
