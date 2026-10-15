<?php

namespace App\Tests;

use App\Infrastructure\Tenancy\Tenant;
use App\Kernel;
use PHPUnit\Framework\TestCase;

class KernelTest extends TestCase
{
    public function testWithoutTenant(): void
    {
        $kernel = new Kernel('prod', false);

        $this->assertNull($kernel->getTenant());
        $this->assertSame($kernel->getProjectDir().'/var/cache/prod', $kernel->getCacheDir());
        $this->assertSame('App_KernelProdContainer', $this->callProtected($kernel, 'getContainerClass'));

        $parameters = $this->callProtected($kernel, 'getKernelParameters');
        $this->assertSame($kernel->getProjectDir(), $parameters['app.data_dir']);
        $this->assertSame('', $parameters['app.user']);
        $this->assertSame('/', $parameters['app.cookie_path']);
        $this->assertSame('PHPSESSID', $parameters['app.session_name']);
        $this->assertSame('REMEMBERME', $parameters['app.remember_me_name']);
    }

    public function testWithTenantWithAnAddressPerPath(): void
    {
        $tenant = Tenant::fromEnvironment('first-user', '/var/www/users/first-user', ['APP_URL' => 'https://dreeve.example.com/first-user']);
        $parameters = $this->callProtected(new Kernel('prod', false, $tenant), 'getKernelParameters');

        $this->assertSame('/first-user', $parameters['app.cookie_path']);
        $this->assertSame('dreeve_session_first-user', $parameters['app.session_name']);
        $this->assertSame('dreeve_remember_me_first-user', $parameters['app.remember_me_name']);
    }

    public function testWithTenant(): void
    {
        $tenant = Tenant::fromEnvironment('first-user', '/var/www/users/first-user', ['APP_URL' => 'https://first.example.com']);
        $kernel = new Kernel('prod', false, $tenant);

        $this->assertSame($tenant, $kernel->getTenant());
        $this->assertSame($kernel->getProjectDir().'/var/cache/users/first-user/prod', $kernel->getCacheDir());
        $this->assertSame('App_KernelProdContainer_User_first_user', $this->callProtected($kernel, 'getContainerClass'));

        $parameters = $this->callProtected($kernel, 'getKernelParameters');
        $this->assertSame('/var/www/users/first-user', $parameters['app.data_dir']);
        $this->assertSame('first-user', $parameters['app.user']);
        $this->assertSame('/', $parameters['app.cookie_path']);
        $this->assertSame('dreeve_session_first-user', $parameters['app.session_name']);
    }

    private function callProtected(Kernel $kernel, string $method): mixed
    {
        return new \ReflectionMethod($kernel, $method)->invoke($kernel);
    }
}
