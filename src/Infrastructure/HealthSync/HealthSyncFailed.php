<?php

declare(strict_types=1);

namespace App\Infrastructure\HealthSync;

final class HealthSyncFailed extends \RuntimeException
{
    public function __construct(
        private readonly string $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function unreachable(?\Throwable $previous = null): self
    {
        return new self('unreachable', 'The Garmin sync service can not be reached.', $previous);
    }

    /**
     * The machine readable reason given by health-sync, e.g. "login_failed" or "mfa_expired".
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
