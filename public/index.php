<?php

use App\Infrastructure\Tenancy\Tenant;
use App\Infrastructure\Tenancy\TenantEnvironment;
use App\Infrastructure\Tenancy\TenantRouterKernel;
use App\Infrastructure\Tenancy\Tenants;
use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    $tenants = Tenants::discover(dirname(__DIR__), [...getenv(), ...$context]);
    if ($tenants->isEmpty()) {
        return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
    }

    // Multi-user installation: every user is served by its own kernel, picked by hostname.
    $tenants = $tenants->withSecrets();
    $tenants->removePasswordsFromSuperglobals();

    return new TenantRouterKernel(
        tenants: $tenants,
        tenantEnvironment: new TenantEnvironment($tenants),
        kernelFactory: static fn (Tenant $tenant): Kernel => new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG'], $tenant),
    );
};
