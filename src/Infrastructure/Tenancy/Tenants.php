<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

/**
 * @implements \IteratorAggregate<string, Tenant>
 */
final readonly class Tenants implements \IteratorAggregate, \Countable
{
    public const string DIRECTORY_NAME = 'users';
    public const string USERS_VARIABLE = 'DREEVE_USERS';

    /**
     * Short aliases for the per-user variables people set most, e.g. ALICE_URL instead of ALICE_APP_URL.
     */
    private const array VARIABLE_ALIASES = [
        'URL' => 'APP_URL',
        'USERNAME' => 'ADMIN_USERNAME',
    ];
    private const string PASSWORD_VARIABLE = 'PASSWORD';

    /**
     * A user's variables are prefixed with the user's name. These names would capture
     * variables that belong to the whole installation (e.g. a user "strava" would turn
     * STRAVA_CLIENT_ID into a setting of that user), so they can not be used.
     */
    private const array RESERVED_NAMES = [
        'admin', 'ai', 'api', 'app', 'caddy', 'composer', 'daemon', 'database', 'demo', 'dev', 'docker',
        'dreeve', 'frankenphp', 'home', 'hostname', 'http', 'https', 'import', 'kernel', 'lock', 'path',
        'pgid', 'php', 'puid', 'server', 'strava', 'symfony', 'trusted', 'tunnel', 'tz', 'user', 'users',
    ];

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
     * Users come from two places, which can be combined:
     *  - DREEVE_USERS=alice,bob in the environment, with every user's settings prefixed
     *    with the user's name: ALICE_URL, ALICE_PASSWORD, ALICE_STRAVA_CLIENT_ID, ...
     *  - Every sub directory of "users/" that contains a .env file.
     * No users at all means a classic single-user installation.
     *
     * @param array<array-key, mixed> $environment
     */
    public static function discover(string $projectDir, array $environment = []): self
    {
        $usersDirectory = rtrim($projectDir, '/\\').'/'.self::DIRECTORY_NAME;
        $tenants = [];

        foreach (self::parseUserNames((string) ($environment[self::USERS_VARIABLE] ?? '')) as $name) {
            $directory = $usersDirectory.'/'.$name;
            [$userEnvironment, $password] = self::extractUserEnvironment($name, $environment);

            $tenants[$name] = Tenant::fromEnvironment(
                name: $name,
                directory: $directory,
                // Settings in the environment win over the ones in the user's optional .env file.
                environment: [...Tenant::readEnvFile($directory), ...$userEnvironment],
                adminPassword: $password,
            );
        }

        $envFiles = glob($usersDirectory.'/*/'.Tenant::ENV_FILE_NAME) ?: [];
        sort($envFiles);
        foreach ($envFiles as $envFile) {
            $name = basename(dirname($envFile));
            if (isset($tenants[$name])) {
                continue;
            }
            self::guardAgainstReservedName($name);
            $tenants[$name] = Tenant::fromDirectory(dirname($envFile));
        }

        return self::fromArray(array_values($tenants));
    }

    /**
     * @param Tenant[] $tenants
     */
    public static function fromArray(array $tenants): self
    {
        $tenantsByName = [];
        $tenantNamesByHost = [];

        foreach ($tenants as $tenant) {
            if (isset($tenantsByName[$tenant->getName()])) {
                throw InvalidTenant::duplicateName($tenant->getName());
            }
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

    /**
     * Generates and loads every user's secrets, see Tenant::withSecrets().
     */
    public function withSecrets(): self
    {
        return self::fromArray(array_map(
            static fn (Tenant $tenant): Tenant => $tenant->withSecrets(),
            array_values($this->tenants),
        ));
    }

    /**
     * Once the passwords are hashed, the app has no use for them anymore. Remove them from
     * the superglobals, so they can not end up in a request's server bag, a dump or a log.
     */
    public function removePasswordsFromSuperglobals(): void
    {
        foreach ($this->getNames() as $name) {
            $variable = self::getVariablePrefix($name).self::PASSWORD_VARIABLE;
            unset($_SERVER[$variable], $_ENV[$variable]);
        }
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

    public static function getVariablePrefix(string $name): string
    {
        return strtoupper(str_replace('-', '_', $name)).'_';
    }

    /**
     * @return string[]
     */
    private static function parseUserNames(string $users): array
    {
        $names = array_values(array_filter(
            array_map(trim(...), preg_split('/[\s,]+/', $users) ?: []),
            static fn (string $name): bool => '' !== $name,
        ));

        $prefixes = [];
        foreach ($names as $name) {
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $name)) {
                throw InvalidTenant::invalidName($name);
            }
            self::guardAgainstReservedName($name);

            $prefix = self::getVariablePrefix($name);
            if (isset($prefixes[$prefix])) {
                throw InvalidTenant::duplicateName($name);
            }
            $prefixes[$prefix] = $name;
        }

        // A user named "bob" would otherwise also pick up the variables of a user named "bob-smith".
        foreach ($prefixes as $prefix => $name) {
            foreach ($prefixes as $otherPrefix => $otherName) {
                if ($name !== $otherName && str_starts_with($otherPrefix, $prefix)) {
                    throw InvalidTenant::overlappingNames($name, $otherName);
                }
            }
        }

        return $names;
    }

    /**
     * @param array<array-key, mixed> $environment
     *
     * @return array{0: array<string, string>, 1: ?string}
     */
    private static function extractUserEnvironment(string $name, array $environment): array
    {
        $prefix = self::getVariablePrefix($name);
        $userEnvironment = [];
        $password = null;

        foreach ($environment as $variable => $value) {
            if (!is_string($variable) || !str_starts_with($variable, $prefix) || !is_scalar($value)) {
                continue;
            }

            $userVariable = substr($variable, strlen($prefix));
            if ('' === $userVariable) {
                continue;
            }

            if (self::PASSWORD_VARIABLE === $userVariable) {
                $password = (string) $value;
                continue;
            }

            $userEnvironment[self::VARIABLE_ALIASES[$userVariable] ?? $userVariable] = (string) $value;
        }

        return [$userEnvironment, '' === $password ? null : $password];
    }

    private static function guardAgainstReservedName(string $name): void
    {
        if (in_array(strtolower(explode('-', $name)[0]), self::RESERVED_NAMES, true)) {
            throw InvalidTenant::reservedName($name);
        }
    }
}
