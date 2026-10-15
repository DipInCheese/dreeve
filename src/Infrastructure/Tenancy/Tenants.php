<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use App\Infrastructure\Tenancy\Registry\UserRegistry;

/**
 * The active users of a multi-user installation.
 *
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
        private bool $isMultiUser,
        private array $tenants,
        private array $tenantNamesByHost,
    ) {
    }

    public static function getUsersDirectory(string $projectDir): string
    {
        return rtrim($projectDir, '/\\').'/'.self::DIRECTORY_NAME;
    }

    /**
     * Setting DREEVE_USER_URL turns the installation into a multi-user installation. Its users
     * come from the user registry (users/.users.json), disabled users are left out.
     *
     * @param array<array-key, mixed> $environment
     */
    public static function discover(string $projectDir, array $environment): self
    {
        $usersDirectory = self::getUsersDirectory($projectDir);
        $registry = new UserRegistry($usersDirectory);
        $userUrl = trim((string) ($environment[UserUrlTemplate::VARIABLE] ?? ''));

        if ('' === $userUrl) {
            if ([] !== $registry->findAll()) {
                throw InvalidTenant::missingUserUrl();
            }

            return self::singleUser();
        }

        $userUrlTemplate = UserUrlTemplate::fromString($userUrl);
        $tenants = [];
        foreach ($registry->findAll() as $user) {
            if ($user->isDisabled()) {
                continue;
            }

            $directory = $usersDirectory.'/'.$user->getName();
            $tenants[] = Tenant::fromEnvironment(
                name: $user->getName(),
                directory: $directory,
                environment: [
                    ...Tenant::readEnvFile($directory),
                    // The address always follows DREEVE_USER_URL, routing depends on it.
                    'APP_URL' => $userUrlTemplate->forUser($user->getName()),
                ],
            );
        }

        return self::fromArray($tenants);
    }

    public static function singleUser(): self
    {
        return new self(false, [], []);
    }

    /**
     * @param Tenant[] $tenants
     */
    public static function fromArray(array $tenants): self
    {
        $tenantsByName = [];
        $tenantNamesByHost = [];

        foreach ($tenants as $tenant) {
            $host = (string) $tenant->getHost();
            if (isset($tenantNamesByHost[$host])) {
                throw InvalidTenant::duplicateHost($host, $tenantNamesByHost[$host], $tenant->getName());
            }

            $tenantsByName[$tenant->getName()] = $tenant;
            $tenantNamesByHost[$host] = $tenant->getName();
        }

        return new self(true, $tenantsByName, $tenantNamesByHost);
    }

    /**
     * Generates and loads every user's secrets, see Tenant::withSecrets().
     */
    public function withSecrets(): self
    {
        if (!$this->isMultiUser) {
            return $this;
        }

        return self::fromArray(array_map(
            static fn (Tenant $tenant): Tenant => $tenant->withSecrets(),
            array_values($this->tenants),
        ));
    }

    public function isMultiUser(): bool
    {
        return $this->isMultiUser;
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

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->tenants);
    }

    public function count(): int
    {
        return count($this->tenants);
    }
}
