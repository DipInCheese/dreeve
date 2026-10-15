<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\AddUser;

use App\Domain\UserManagement\UserName;
use App\Infrastructure\CQRS\Command\Deserialize\DeserializableCommand;
use App\Infrastructure\CQRS\Command\Deserialize\ProvidesCommandName;
use App\Infrastructure\CQRS\Command\DomainCommand;
use App\Infrastructure\CQRS\Command\SuppressesFlashMessage;

#[SuppressesFlashMessage]
final readonly class AddUser extends DomainCommand implements DeserializableCommand
{
    use ProvidesCommandName;

    private function __construct(
        private string $name,
    ) {
    }

    public static function fromPayload(array $payload): self
    {
        return new self(UserName::fromPayload($payload));
    }

    public function getName(): string
    {
        return $this->name;
    }
}
