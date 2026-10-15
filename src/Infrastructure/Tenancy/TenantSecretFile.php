<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

/**
 * Small files holding a single secret, only readable by the owner (0600).
 * Writes are atomic: a reader never sees a half-written secret.
 */
final class TenantSecretFile
{
    public static function read(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException(sprintf('Could not read "%s"', $path));
        }

        $contents = trim($contents);

        return '' === $contents ? null : $contents;
    }

    public static function write(string $path, #[\SensitiveParameter] string $secret): void
    {
        $temporaryPath = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        $previousUmask = umask(0o077);
        try {
            if (false === file_put_contents($temporaryPath, $secret."\n", LOCK_EX)) {
                throw new \RuntimeException(sprintf('Could not write "%s"', $path));
            }
        } finally {
            umask($previousUmask);
        }

        chmod($temporaryPath, 0o600);
        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException(sprintf('Could not write "%s"', $path));
        }
    }

    /**
     * @param \Closure(): string $generate
     */
    public static function readOrCreate(string $path, \Closure $generate): string
    {
        if (null !== ($secret = self::read($path))) {
            return $secret;
        }

        self::write($path, $generate());

        // Read it back: when two processes generate a secret at the same time, both end up using the one that won.
        return self::read($path) ?? throw new \RuntimeException(sprintf('Could not read "%s"', $path));
    }
}
