<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

final class InvalidTenant extends \RuntimeException
{
    public static function invalidName(string $name): self
    {
        return new self(sprintf('Invalid user name "%s". Use lowercase letters, digits and dashes only, starting with a letter or digit', $name));
    }

    public static function missingEnvFile(string $name, string $envFile): self
    {
        return new self(sprintf('User "%s" has no settings file, expected "%s"', $name, $envFile));
    }

    public static function reservedVariable(string $name, string $variable): self
    {
        return new self(sprintf('User "%s" can not set "%s" in its .env file, it applies to the whole installation', $name, $variable));
    }

    public static function duplicateHost(string $host, string $firstUser, string $secondUser): self
    {
        return new self(sprintf('Users "%s" and "%s" both use host "%s" in their APP_URL, every user needs its own (sub)domain', $firstUser, $secondUser, $host));
    }

    public static function missingHost(string $name): self
    {
        return new self(sprintf(
            'User "%s" has no URL. Set %sURL=https://%s.your-domain.com, it decides on which (sub)domain the user is reached',
            $name,
            Tenants::getVariablePrefix($name),
            $name,
        ));
    }

    public static function duplicateName(string $name): self
    {
        return new self(sprintf('User "%s" is configured more than once', $name));
    }

    public static function overlappingNames(string $name, string $otherName): self
    {
        return new self(sprintf(
            'User names "%s" and "%s" can not be combined: the settings of "%2$s" (%s...) would also be read as settings of "%1$s". Rename one of them',
            $name,
            $otherName,
            Tenants::getVariablePrefix($otherName),
        ));
    }

    public static function reservedName(string $name): self
    {
        return new self(sprintf('User name "%s" is reserved because its settings would clash with the settings of the app itself, pick another name', $name));
    }

    public static function passwordTooShort(string $name, int $minLength): self
    {
        return new self(sprintf('The password of user "%s" is too short, use at least %d characters', $name, $minLength));
    }

    public static function passwordAndPasswordHash(string $name): self
    {
        return new self(sprintf('User "%s" has both a password and a password hash, set only one of them', $name));
    }

    public static function unknown(string $name, Tenants $tenants): self
    {
        return new self(sprintf('Unknown user "%s". Configured users: %s', $name, implode(', ', $tenants->getNames())));
    }

    public static function noneSelected(Tenants $tenants): self
    {
        return new self(sprintf(
            'This installation has multiple users. Pick one by setting DREEVE_USER, e.g. "DREEVE_USER=%s bin/console ...". Configured users: %s',
            $tenants->getNames()[0] ?? 'name',
            implode(', ', $tenants->getNames())
        ));
    }
}
