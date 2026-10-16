<?php

declare(strict_types=1);

namespace App\Domain\Settings;

/**
 * The security settings that can be changed in the app. Every page requires a login,
 * that is not a setting. The remaining security options are environment variables.
 */
final readonly class SecuritySettings
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function fromArray(?array $data): self
    {
        return new self();
    }
}
