<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\HealthSync;

use App\Infrastructure\HealthSync\GarminHistory;
use App\Infrastructure\HealthSync\GarminLogin;
use App\Infrastructure\HealthSync\GarminStatus;
use App\Infrastructure\HealthSync\HealthSync;
use App\Infrastructure\HealthSync\HealthSyncFailed;

final class SpyHealthSync implements HealthSync
{
    public bool $available = true;
    /** @var array<string, mixed> */
    public array $status = [];
    public GarminLogin $login = GarminLogin::CONNECTED;
    public ?HealthSyncFailed $failure = null;
    /** @var list<array{string, array<string, string>}> */
    public array $calls = [];

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function status(): GarminStatus
    {
        $this->failIfAsked();

        return GarminStatus::fromArray($this->status);
    }

    public function connect(string $email, #[\SensitiveParameter] string $password, GarminHistory $history): GarminLogin
    {
        $this->calls[] = ['connect', ['email' => $email, 'password' => $password, 'history' => $history->value]];
        $this->failIfAsked();

        return $this->login;
    }

    public function confirmCode(string $code): void
    {
        $this->calls[] = ['confirmCode', ['code' => $code]];
        $this->failIfAsked();
    }

    public function syncNow(): void
    {
        $this->calls[] = ['syncNow', []];
        $this->failIfAsked();
    }

    public function disconnect(): void
    {
        $this->calls[] = ['disconnect', []];
        $this->failIfAsked();
    }

    private function failIfAsked(): void
    {
        if ($this->failure instanceof HealthSyncFailed) {
            throw $this->failure;
        }
    }
}
