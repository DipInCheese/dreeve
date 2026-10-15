<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy\Registry;

final class UserRegistryException extends \RuntimeException
{
    public static function invalidName(string $name): self
    {
        return new self(sprintf('"%s" is not a valid user name. Use 1 to 63 lowercase letters, digits and dashes, starting and ending with a letter or digit', $name));
    }

    public static function alreadyExists(string $name): self
    {
        return new self(sprintf('User "%s" already exists', $name));
    }

    public static function notFound(string $name): self
    {
        return new self(sprintf('User "%s" does not exist', $name));
    }

    public static function ownerCanNotBeChanged(string $name): self
    {
        return new self(sprintf('User "%s" is the owner of this installation and can not be disabled or removed', $name));
    }

    public static function invalidInvite(): self
    {
        return new self('This invite link is invalid or has expired. Ask for a new one');
    }

    public static function passwordTooShort(int $minLength): self
    {
        return new self(sprintf('The password needs at least %d characters', $minLength));
    }

    public static function corrupt(string $path): self
    {
        return new self(sprintf('The user registry "%s" is not valid JSON. Restore it from a backup', $path));
    }

    public static function couldNotMoveData(string $directory, string $removedDirectory): self
    {
        return new self(sprintf('Could not move "%s" to "%s", the user was not removed. Check the permissions of the data directory', $directory, $removedDirectory));
    }

    public static function couldNotWrite(string $path): self
    {
        return new self(sprintf('Could not write the user registry "%s", check the permissions of the data directory', $path));
    }
}
