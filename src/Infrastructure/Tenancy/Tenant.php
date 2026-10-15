<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use Symfony\Component\Dotenv\Dotenv;

/**
 * A single user of a multi-user installation. Every user lives in its own directory
 * (users/<name>/) that holds a ".env" file with the user's settings and secrets, next
 * to the user's database, files, render cache and watch directory.
 */
final readonly class Tenant
{
    public const string ENV_FILE_NAME = '.env';
    private const string NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    /**
     * @param array<string, string> $environment
     */
    private function __construct(
        private string $name,
        private string $directory,
        private array $environment,
    ) {
    }

    public static function fromDirectory(string $directory): self
    {
        $directory = rtrim($directory, '/\\');
        $name = basename($directory);

        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw InvalidTenant::invalidName($name);
        }

        $envFile = $directory.'/'.self::ENV_FILE_NAME;
        if (!is_file($envFile) || false === ($contents = file_get_contents($envFile))) {
            throw InvalidTenant::missingEnvFile($name, $envFile);
        }

        return self::fromEnvironment(
            name: $name,
            directory: $directory,
            environment: new Dotenv()->parse($contents, $envFile),
        );
    }

    /**
     * @param array<string, string> $environment
     */
    public static function fromEnvironment(string $name, string $directory, array $environment): self
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
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
            TenantEnvironment::USER_VARIABLE => $this->name,
        ];
    }

    /**
     * The hostname this user is reached on, taken from the APP_URL in the user's .env.
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
