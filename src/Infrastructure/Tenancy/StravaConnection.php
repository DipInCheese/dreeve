<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

/**
 * The Strava connection a user of a multi-user installation set up in the admin panel:
 * the user's own Strava API application and, once authorized, the refresh token.
 *
 * Stored in the user's directory, only readable by its owner. Once connected, it switches
 * the user to the Strava import mode, without anyone having to edit a settings file.
 */
final readonly class StravaConnection
{
    public const string FILE_NAME = '.strava.json';
    public const string IMPORT_REQUESTED_FILE_NAME = '.strava-import-requested';
    private const string CLIENT_ID_PATTERN = '/^[0-9]{1,12}$/';
    private const string CLIENT_SECRET_PATTERN = '/^[A-Za-z0-9]{20,128}$/';

    private function __construct(
        private string $clientId,
        #[\SensitiveParameter]
        private string $clientSecret,
        #[\SensitiveParameter]
        private ?string $refreshToken,
        private ?string $athleteName,
        private ?\DateTimeImmutable $connectedOn,
    ) {
    }

    public static function forApplication(string $clientId, #[\SensitiveParameter] string $clientSecret): self
    {
        $clientId = trim($clientId);
        $clientSecret = trim($clientSecret);
        if (!self::isValidClientId($clientId) || !self::isValidClientSecret($clientSecret)) {
            throw new \InvalidArgumentException('Invalid Strava Client ID or Client Secret');
        }

        return new self($clientId, $clientSecret, null, null, null);
    }

    public static function isValidClientId(string $clientId): bool
    {
        return 1 === preg_match(self::CLIENT_ID_PATTERN, trim($clientId));
    }

    public static function isValidClientSecret(string $clientSecret): bool
    {
        return 1 === preg_match(self::CLIENT_SECRET_PATTERN, trim($clientSecret));
    }

    public static function read(string $userDirectory): ?self
    {
        $contents = TenantSecretFile::read(rtrim($userDirectory, '/\\').'/'.self::FILE_NAME);
        if (null === $contents) {
            return null;
        }

        try {
            $data = json_decode($contents, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data) || !self::isValidClientId((string) ($data['clientId'] ?? '')) || !self::isValidClientSecret((string) ($data['clientSecret'] ?? ''))) {
            return null;
        }

        return new self(
            clientId: (string) $data['clientId'],
            clientSecret: (string) $data['clientSecret'],
            refreshToken: isset($data['refreshToken']) && '' !== $data['refreshToken'] ? (string) $data['refreshToken'] : null,
            athleteName: isset($data['athleteName']) ? (string) $data['athleteName'] : null,
            connectedOn: isset($data['connectedOn']) ? new \DateTimeImmutable((string) $data['connectedOn']) : null,
        );
    }

    public function write(string $userDirectory): void
    {
        TenantSecretFile::write(rtrim($userDirectory, '/\\').'/'.self::FILE_NAME, json_encode([
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret,
            'refreshToken' => $this->refreshToken,
            'athleteName' => $this->athleteName,
            'connectedOn' => $this->connectedOn?->format(\DateTimeInterface::ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function delete(string $userDirectory): void
    {
        foreach ([self::FILE_NAME, self::IMPORT_REQUESTED_FILE_NAME] as $fileName) {
            $path = rtrim($userDirectory, '/\\').'/'.$fileName;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Asks the daemon to start an import right away, instead of waiting for its schedule.
     */
    public static function requestImport(string $userDirectory): void
    {
        touch(rtrim($userDirectory, '/\\').'/'.self::IMPORT_REQUESTED_FILE_NAME);
    }

    public static function isImportRequested(string $userDirectory): bool
    {
        return is_file(rtrim($userDirectory, '/\\').'/'.self::IMPORT_REQUESTED_FILE_NAME);
    }

    public function withAuthorization(#[\SensitiveParameter] string $refreshToken, string $athleteName, \DateTimeImmutable $connectedOn): self
    {
        return new self($this->clientId, $this->clientSecret, $refreshToken, $athleteName, $connectedOn);
    }

    public function isConnected(): bool
    {
        return null !== $this->refreshToken;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getClientSecret(): string
    {
        return $this->clientSecret;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function getAthleteName(): ?string
    {
        return $this->athleteName;
    }

    public function getConnectedOn(): ?\DateTimeImmutable
    {
        return $this->connectedOn;
    }

    /**
     * The settings the app needs to import from Strava. Nothing changes until the user authorized the app.
     *
     * @return array<string, string>
     */
    public function toEnvironment(): array
    {
        if (null === $this->refreshToken) {
            return [];
        }

        return [
            'IMPORT_MODE' => 'stravaApi',
            'STRAVA_CLIENT_ID' => $this->clientId,
            'STRAVA_CLIENT_SECRET' => $this->clientSecret,
            'STRAVA_REFRESH_TOKEN' => $this->refreshToken,
        ];
    }
}
