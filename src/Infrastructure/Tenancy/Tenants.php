<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

/**
 * @implements \IteratorAggregate<string, Tenant>
 */
final readonly class Tenants implements \IteratorAggregate, \Countable
{
    public const string DIRECTORY_NAME = 'users';

    /**
     * @param array<string, Tenant> $tenants
     * @param array<string, string> $tenantNamesByHost
     */
    private function __construct(
        private array $tenants,
        private array $tenantNamesByHost,
    ) {
    }

    /**
     * Every sub directory of "users/" that contains a .env file is a user.
     * No such directories means a classic single-user installation.
     */
    public static function discover(string $projectDir): self
    {
        $envFiles = glob(rtrim($projectDir, '/\\').'/'.self::DIRECTORY_NAME.'/*/'.Tenant::ENV_FILE_NAME) ?: [];
        sort($envFiles);

        return self::fromArray(array_map(
            static fn (string $envFile): Tenant => Tenant::fromDirectory(dirname($envFile)),
            $envFiles,
        ));
    }

    /**
     * @param Tenant[] $tenants
     */
    public static function fromArray(array $tenants): self
    {
        $tenantsByName = [];
        $tenantNamesByHost = [];

        foreach ($tenants as $tenant) {
            if (!$host = $tenant->getHost()) {
                throw InvalidTenant::missingHost($tenant->getName());
            }
            if (isset($tenantNamesByHost[$host])) {
                throw InvalidTenant::duplicateHost($host, $tenantNamesByHost[$host], $tenant->getName());
            }

            $tenantsByName[$tenant->getName()] = $tenant;
            $tenantNamesByHost[$host] = $tenant->getName();
        }

        return new self($tenantsByName, $tenantNamesByHost);
    }

    public function isEmpty(): bool
    {
        return [] === $this->tenants;
    }

    public function findByName(string $name): Tenant
    {
        return $this->tenants[$name] ?? throw InvalidTenant::unknown($name, $this);
    }

    public function findByHost(string $host): ?Tenant
    {
        $name = $this->tenantNamesByHost[strtolower($host)] ?? null;

        return null === $name ? null : $this->tenants[$name];
    }

    /**
     * @return string[]
     */
    public function getNames(): array
    {
        return array_keys($this->tenants);
    }

    /**
     * Every variable name any user sets, so switching users can reset all of them.
     *
     * @return string[]
     */
    public function getAllEnvironmentVariableNames(): array
    {
        $names = [];
        foreach ($this->tenants as $tenant) {
            $names = [...$names, ...array_keys($tenant->getEnvironment())];
        }

        return array_values(array_unique($names));
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->tenants);
    }

    public function count(): int
    {
        return count($this->tenants);
    }
}
