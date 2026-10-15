<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

/**
 * Puts a user's environment variables in place of the installation-wide ones.
 *
 * In worker mode one PHP thread serves requests for several users, so before a
 * request is handled every variable any user sets is first reset to its
 * installation-wide value (or removed). Otherwise a variable only one user sets,
 * like an API key, would leak into requests of the other users.
 */
final readonly class TenantEnvironment
{
    public const string USER_VARIABLE = 'DREEVE_USER';

    /**
     * Settings that apply to the whole installation (process) and can not differ per user.
     */
    public const array RESERVED_VARIABLES = [
        self::USER_VARIABLE,
        'APP_ENV',
        'APP_DEBUG',
        'TRUSTED_PROXIES',
        'TZ',
        'PUID',
        'PGID',
    ];

    /** @var string[] */
    private array $variableNames;
    /** @var array<string, mixed> */
    private array $baselineEnv;
    /** @var array<string, mixed> */
    private array $baselineServer;

    public function __construct(
        Tenants $tenants,
    ) {
        $this->variableNames = $tenants->getAllEnvironmentVariableNames();

        $baselineEnv = [];
        $baselineServer = [];
        foreach ($this->variableNames as $name) {
            if (array_key_exists($name, $_ENV)) {
                $baselineEnv[$name] = $_ENV[$name];
            }
            if (array_key_exists($name, $_SERVER)) {
                $baselineServer[$name] = $_SERVER[$name];
            }
        }
        $this->baselineEnv = $baselineEnv;
        $this->baselineServer = $baselineServer;
    }

    public function activate(Tenant $tenant): void
    {
        foreach ($this->variableNames as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            if (array_key_exists($name, $this->baselineEnv)) {
                $_ENV[$name] = $this->baselineEnv[$name];
            }
            if (array_key_exists($name, $this->baselineServer)) {
                $_SERVER[$name] = $this->baselineServer[$name];
            }
        }

        foreach ($tenant->getEnvironment() as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }
}
