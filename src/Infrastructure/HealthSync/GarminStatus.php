<?php

declare(strict_types=1);

namespace App\Infrastructure\HealthSync;

use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class GarminStatus
{
    private function __construct(
        private bool $connected,
        private bool $needsLogin,
        private bool $codePending,
        private bool $syncing,
        private ?string $displayName,
        private ?SerializableDateTime $since,
        private ?SerializableDateTime $lastSyncAt,
        private ?string $lastError,
        private int $activitiesSynced,
        private bool $backfilling,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            connected: true === ($data['connected'] ?? false),
            needsLogin: true === ($data['needsLogin'] ?? false),
            codePending: true === ($data['mfaPending'] ?? false),
            syncing: true === ($data['syncing'] ?? false),
            displayName: self::optionalString($data['displayName'] ?? null),
            since: self::optionalDate($data['since'] ?? null),
            lastSyncAt: self::optionalDate($data['lastSyncAt'] ?? null),
            lastError: self::optionalString($data['lastError'] ?? null),
            activitiesSynced: is_int($data['activitiesSynced'] ?? null) ? $data['activitiesSynced'] : 0,
            backfilling: true === ($data['backfilling'] ?? false),
        );
    }

    /**
     * The same shape health-sync answers with, so a status can be cached and read back.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'connected' => $this->connected,
            'needsLogin' => $this->needsLogin,
            'mfaPending' => $this->codePending,
            'syncing' => $this->syncing,
            'displayName' => $this->displayName,
            'since' => $this->since?->format('Y-m-d'),
            'lastSyncAt' => $this->lastSyncAt?->format(\DateTimeInterface::ATOM),
            'lastError' => $this->lastError,
            'activitiesSynced' => $this->activitiesSynced,
            'backfilling' => $this->backfilling,
        ];
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function needsLogin(): bool
    {
        return $this->needsLogin;
    }

    public function isCodePending(): bool
    {
        return $this->codePending;
    }

    public function isSyncing(): bool
    {
        return $this->syncing;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function getSince(): ?SerializableDateTime
    {
        return $this->since;
    }

    public function getLastSyncAt(): ?SerializableDateTime
    {
        return $this->lastSyncAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getActivitiesSynced(): int
    {
        return $this->activitiesSynced;
    }

    /**
     * Still fetching the history, in batches.
     */
    public function isBackfilling(): bool
    {
        return $this->backfilling;
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) ? $value : null;
    }

    private static function optionalDate(mixed $value): ?SerializableDateTime
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }

        try {
            return SerializableDateTime::fromString($value);
        } catch (\Exception) {
            return null;
        }
    }
}
