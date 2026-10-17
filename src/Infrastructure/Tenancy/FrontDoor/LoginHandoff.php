<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy\FrontDoor;

/**
 * A one-time pass from the sign-in page on the bare address into one user's own app.
 * The front door checks the password, writes the pass into that user's directory and sends the
 * browser to the user's path; the user's app takes the pass, signs the user in and throws it away.
 * Only a hash of the pass is stored, and it expires after a minute.
 */
final readonly class LoginHandoff
{
    public const string FILE_NAME = '.login-handoff';
    private const int VALID_FOR_SECONDS = 60;

    private function __construct(
        private string $file,
    ) {
    }

    public static function inDirectory(string $dataDirectory): self
    {
        return new self(rtrim($dataDirectory, '/\\').'/'.self::FILE_NAME);
    }

    public function create(bool $rememberMe, \DateTimeImmutable $now): string
    {
        $token = bin2hex(random_bytes(32));
        $contents = json_encode([
            'hash' => hash('sha256', $token),
            'expiresAt' => $now->getTimestamp() + self::VALID_FOR_SECONDS,
            'rememberMe' => $rememberMe,
        ], JSON_THROW_ON_ERROR);

        $temporaryFile = $this->file.'.'.bin2hex(random_bytes(4));
        file_put_contents($temporaryFile, $contents);
        chmod($temporaryFile, 0o600);
        rename($temporaryFile, $this->file);

        return $token;
    }

    /**
     * Uses the pass up: it works once, also when it turns out to be wrong or expired.
     *
     * @return bool|null null when the pass is not valid, else whether to remember the user
     */
    public function consume(#[\SensitiveParameter] string $token, \DateTimeImmutable $now): ?bool
    {
        $claimedFile = $this->file.'.used.'.bin2hex(random_bytes(4));
        // Renaming first makes sure two requests can never both use the same pass.
        if (!is_file($this->file) || !@rename($this->file, $claimedFile)) {
            return null;
        }

        $contents = (string) file_get_contents($claimedFile);
        unlink($claimedFile);

        $data = json_decode($contents, true);
        if (!is_array($data) || !is_string($data['hash'] ?? null) || !is_int($data['expiresAt'] ?? null)) {
            return null;
        }
        if ($data['expiresAt'] < $now->getTimestamp() || !hash_equals($data['hash'], hash('sha256', $token))) {
            return null;
        }

        return true === ($data['rememberMe'] ?? false);
    }
}
