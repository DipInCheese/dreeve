<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\ChangeUserStatus;

use App\Domain\UserManagement\UserName;
use App\Infrastructure\CQRS\Command\Deserialize\CouldNotDeserializeCommand;
use App\Infrastructure\CQRS\Command\Deserialize\DeserializableCommand;
use App\Infrastructure\CQRS\Command\Deserialize\ProvidesCommandName;
use App\Infrastructure\CQRS\Command\DomainCommand;

final readonly class ChangeUserStatus extends DomainCommand implements DeserializableCommand
{
    use ProvidesCommandName;

    private function __construct(
        private string $name,
        private bool $isDisabled,
    ) {
    }

    public static function fromPayload(array $payload): self
    {
        $status = $payload['status'] ?? null;
        if (!in_array($status, ['disabled', 'enabled'], true)) {
            throw CouldNotDeserializeCommand::invalidPayload('A "status" of "disabled" or "enabled" is required.');
        }

        return new self(
            name: UserName::fromPayload($payload),
            isDisabled: 'disabled' === $status,
        );
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isDisabled(): bool
    {
        return $this->isDisabled;
    }
}
