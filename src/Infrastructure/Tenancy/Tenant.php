<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use Symfony\Component\Dotenv\Dotenv;

/**
 * A single user of a multi-user installation. Every user has its own directory
 * (users/<name>/) that holds the user's database, files, render cache and watch
 * directory, and optionally a ".env" file with the user's settings.
 */
final readonly class Tenant
{
    public const string ENV_FILE_NAME = '.env';
    public const string APP_SECRET_FILE_NAME = '.app-secret';
    public const string ADMIN_PASSWORD_HASH_FILE_NAME = '.admin-password-hash';
    public const int MIN_PASSWORD_LENGTH = 12;
    private const string NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    /**
     * @param array<string, string> $environment
     */
    private function __construct(
        private string $name,
        private string $directory,
        private array $environment,
        #[\SensitiveParameter]
        private ?string $adminPassword,
    ) {
    }

    /**
     * Reads the settings from the ".env" file in the user's directory, if there is one.
     *
     * @return array<string, string>
     */
    public static function readEnvFile(string $directory): array
    {
        $envFile = rtrim($directory, '/\\').'/'.self::ENV_FILE_NAME;
        if (!is_file($envFile)) {
            return [];
        }
        if (false === ($contents = file_get_contents($envFile))) {
            throw new \RuntimeException(sprintf('Could not read "%s"', $envFile));
        }

        return new Dotenv()->parse($contents, $envFile);
    }

    public static function fromDirectory(string $directory): self
    {
        $directory = rtrim($directory, '/\\');
        $name = basename($directory);

        if (!is_file($directory.'/'.self::ENV_FILE_NAME)) {
            throw InvalidTenant::missingEnvFile($name, $directory.'/'.self::ENV_FILE_NAME);
        }

        return self::fromEnvironment(
            name: $name,
            directory: $directory,
            environment: self::readEnvFile($directory),
        );
    }

    /**
     * @param array<string, string> $environment
     */
    public static function fromEnvironment(
        string $name,
        string $directory,
        array $environment,
        #[\SensitiveParameter]
        ?string $adminPassword = null,
    ): self {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw InvalidTenant::invalidName($name);
        }

        foreach (TenantEnvironment::RESERVED_VARIABLES as $reserved) {
            if (array_key_exists($reserved, $environment)) {
                throw InvalidTenant::reservedVariable($name, $reserved);
            }
        }

        if (null !== $adminPassword) {
            if (mb_strlen($adminPassword) < self::MIN_PASSWORD_LENGTH) {
                throw InvalidTenant::passwordTooShort($name, self::MIN_PASSWORD_LENGTH);
            }
            if ('' !== ($environment['ADMIN_PASSWORD_HASH'] ?? '')) {
                throw InvalidTenant::passwordAndPasswordHash($name);
            }
        }

        return new self(
            name: $name,
            directory: rtrim($directory, '/\\'),
            environment: $environment,
            adminPassword: $adminPassword,
        );
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The directory that replaces the project root for everything this user writes:
     * storage/database, storage/files, build and watch.
     */
    public function getDataDirectory(): string
    {
        return $this->directory;
    }

    /**
     * The user's own environment variables, completed with per-user defaults
     * so data can never end up in another user's directory.
     *
     * @return array<string, string>
     */
    public function getEnvironment(): array
    {
        return [
            'DATABASE_DIRECTORY' => $this->directory.'/storage/database',
            'ADMIN_USERNAME' => $this->name,
            ...$this->environment,
            TenantEnvironment::USER_VARIABLE => $this->name,
        ];
    }

    /**
     * The hostname this user is reached on, taken from the user's APP_URL.
     */
    public function getHost(): ?string
    {
        $appUrl = $this->environment['APP_URL'] ?? '';
        if ('' === $appUrl) {
            return null;
        }

        $host = parse_url($appUrl, PHP_URL_HOST);
        if (!is_string($host) || '' === $host) {
            return null;
        }

        return strtolower($host);
    }

    /**
     * Fills in the secrets a user does not have to configure:
     *  - APP_SECRET is generated once and kept in the user's directory.
     *  - A plain admin password is turned into ADMIN_PASSWORD_HASH. The hash is kept in the
     *    user's directory and only recalculated when the password changes: every worker and
     *    every restart must use the exact same hash, sessions are bound to it.
     *
     * The plain password never ends up in the environment the app sees.
     */
    public function withSecrets(): self
    {
        $this->ensureDirectoriesExist();
        $environment = $this->environment;

        if ('' === ($environment['APP_SECRET'] ?? '')) {
            $environment['APP_SECRET'] = TenantSecretFile::readOrCreate(
                $this->directory.'/'.self::APP_SECRET_FILE_NAME,
                static fn (): string => bin2hex(random_bytes(32)),
            );
        }

        if (null !== $this->adminPassword) {
            $environment['ADMIN_PASSWORD_HASH'] = $this->resolveAdminPasswordHash($this->adminPassword);
        }

        return new self(
            name: $this->name,
            directory: $this->directory,
            environment: $environment,
            adminPassword: null,
        );
    }

    public function ensureDirectoriesExist(): void
    {
        foreach (['storage/database', 'storage/files', 'build', 'watch'] as $subDirectory) {
            $path = $this->directory.'/'.$subDirectory;
            if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
                throw new \RuntimeException(sprintf('Could not create directory "%s"', $path));
            }
        }
    }

    private function resolveAdminPasswordHash(#[\SensitiveParameter] string $password): string
    {
        $hashFile = $this->directory.'/'.self::ADMIN_PASSWORD_HASH_FILE_NAME;

        $storedHash = TenantSecretFile::read($hashFile);
        if (null !== $storedHash && password_verify($password, $storedHash) && !password_needs_rehash($storedHash, PASSWORD_BCRYPT)) {
            return $storedHash;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        TenantSecretFile::write($hashFile, $hash);

        return $hash;
    }
}
