<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

/**
 * Puts a user's environment variables in place of the installation-wide ones.
 *
 * In worker mode one PHP thread serves requests for several users. Before a user's
 * variables are applied, every variable the previously active user set is reset to its
 * installation-wide value (or removed), so nothing leaks from one user to the next.
 */
final class TenantEnvironment
{
    public const string USER_VARIABLE = 'DREEVE_USER';

    /**
     * Settings that apply to the whole installation (process) and can not differ per user.
     */
    public const array RESERVED_VARIABLES = [
        self::USER_VARIABLE,
        UserUrlTemplate::VARIABLE,
        'APP_ENV',
        'APP_DEBUG',
        'TRUSTED_PROXIES',
        'TZ',
        'PUID',
        'PGID',
    ];

    /** @var array<string, mixed> */
    private readonly array $baselineEnv;
    /** @var array<string, mixed> */
    private readonly array $baselineServer;
    /** @var string[] */
    private array $appliedVariableNames = [];

    /**
     * Must be created before any user is activated: it remembers the installation-wide values.
     */
    public function __construct()
    {
        $this->baselineEnv = $_ENV;
        $this->baselineServer = $_SERVER;
    }

    public function activate(Tenant $tenant): void
    {
        $environment = $tenant->getEnvironment();

        foreach (array_unique([...$this->appliedVariableNames, ...array_keys($environment)]) as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            if (array_key_exists($name, $this->baselineEnv)) {
                $_ENV[$name] = $this->baselineEnv[$name];
            }
            if (array_key_exists($name, $this->baselineServer)) {
                $_SERVER[$name] = $this->baselineServer[$name];
            }
        }

        foreach ($environment as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $this->appliedVariableNames = array_keys($environment);
    }
}
