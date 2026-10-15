<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

final class InvalidTenant extends \RuntimeException
{
    public static function invalidName(string $name): self
    {
        return new self(sprintf('Invalid user name "%s". Use lowercase letters, digits and dashes only, starting and ending with a letter or digit', $name));
    }

    public static function reservedVariable(string $name, string $variable): self
    {
        return new self(sprintf('User "%s" can not set "%s" in its .env file, it applies to the whole installation', $name, $variable));
    }

    public static function duplicateAddress(string $address, string $firstUser, string $secondUser): self
    {
        return new self(sprintf('Users "%s" and "%s" both use address "%s", every user needs its own address', $firstUser, $secondUser, $address));
    }

    public static function invalidUserUrl(string $template): self
    {
        return new self(sprintf(
            '%s "%s" is not valid. Use https://dreeve.example.com/{user} or https://{user}.example.com',
            UserUrlTemplate::VARIABLE,
            $template,
        ));
    }

    public static function missingUserUrl(): self
    {
        return new self(sprintf('This installation has users, but %s is not set. Set it to e.g. https://dreeve.example.com/{user}', UserUrlTemplate::VARIABLE));
    }

    public static function unknown(string $name, Tenants $tenants): self
    {
        return new self(sprintf('Unknown or disabled user "%s". Active users: %s', $name, implode(', ', $tenants->getNames()) ?: 'none'));
    }

    public static function noneSelected(Tenants $tenants): self
    {
        return new self(sprintf(
            'This installation has multiple users. Pick one by setting DREEVE_USER, e.g. "DREEVE_USER=%s bin/console ...". Active users: %s',
            $tenants->getNames()[0] ?? 'name',
            implode(', ', $tenants->getNames()) ?: 'none',
        ));
    }
}
