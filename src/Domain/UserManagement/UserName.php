<?php

declare(strict_types=1);

namespace App\Domain\UserManagement;

use App\Infrastructure\CQRS\Command\Deserialize\CouldNotDeserializeCommand;
use App\Infrastructure\Tenancy\Registry\UserRegistry;

final readonly class UserName
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): string
    {
        $name = $payload['name'] ?? null;
        if (!is_string($name) || '' === trim($name)) {
            throw CouldNotDeserializeCommand::invalidPayload('A "name" is required.');
        }

        $name = strtolower(trim($name));
        if (!UserRegistry::isValidName($name)) {
            throw CouldNotDeserializeCommand::invalidPayload('Use 1 to 63 lowercase letters, digits and dashes for the name, starting and ending with a letter or digit.');
        }

        return $name;
    }
}
