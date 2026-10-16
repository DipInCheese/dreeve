<?php

declare(strict_types=1);

namespace App\Infrastructure\HealthSync;

/**
 * The health-sync service: connects Garmin accounts and fetches their data into the user's folder.
 */
interface HealthSync
{
    public function isAvailable(): bool;

    /**
     * @throws HealthSyncFailed
     */
    public function status(): GarminStatus;

    /**
     * Logs in on Garmin. The password is passed on once and never stored.
     *
     * @throws HealthSyncFailed
     */
    public function connect(string $email, #[\SensitiveParameter] string $password, GarminHistory $history): GarminLogin;

    /**
     * @throws HealthSyncFailed
     */
    public function confirmCode(string $code): void;

    /**
     * @throws HealthSyncFailed
     */
    public function syncNow(): void;

    /**
     * @throws HealthSyncFailed
     */
    public function disconnect(): void;
}
