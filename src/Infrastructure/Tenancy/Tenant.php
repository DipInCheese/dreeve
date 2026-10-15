<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use App\Infrastructure\Tenancy\Registry\UserRegistry;
use Symfony\Component\Dotenv\Dotenv;

/**
 * A single user of a multi-user installation. Every user has its own directory
 * (users/<name>/) that holds the user's database, files, render cache, watch
 * directory, secrets and optionally a ".env" file with extra settings.
 */
final readonly class Tenant
{
    public const string ENV_FILE_NAME = '.env';
    public const string APP_SECRET_FILE_NAME = '.app-secret';
    public const string ADMIN_PASSWORD_HASH_FILE_NAME = '.admin-password-hash';

    /**
     * @param array<string, string> $environment
     */
    private function __construct(
        private string $name,
        private string $directory,
        private array $environment,
    ) {
    }

    /**
     * Reads the optional settings in the ".env" file in the user's directory.
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

    /**
     * @param array<string, string> $environment
     */
    public static function fromEnvironment(string $name, string $directory, array $environment): self
    {
        if (!UserRegistry::isValidName($name)) {
            throw InvalidTenant::invalidName($name);
        }

        foreach (TenantEnvironment::RESERVED_VARIABLES as $reserved) {
            if (array_key_exists($reserved, $environment)) {
                throw InvalidTenant::reservedVariable($name, $reserved);
            }
        }

        return new self(
            name: $name,
            directory: rtrim($directory, '/\\'),
            environment: $environment,
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
            ...$this->environment,
            // The admin login of a user is always the user's name, the password hash is read from the user's directory.
            'ADMIN_USERNAME' => $this->name,
            'ADMIN_PASSWORD_HASH' => '',
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
     * The path this user lives under, e.g. "/alice" for https://dreeve.example.com/alice.
     * Empty when the user has a (sub)domain of its own.
     */
    public function getBasePath(): string
    {
        $path = parse_url($this->environment['APP_URL'] ?? '', PHP_URL_PATH);

        return is_string($path) ? rtrim($path, '/') : '';
    }

    /**
     * Fills in APP_SECRET when the user does not set one: generated once and kept in the
     * user's directory, only readable by its owner. Also creates the user's directories.
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

        return new self(
            name: $this->name,
            directory: $this->directory,
            environment: $environment,
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
}
