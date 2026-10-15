<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

/**
 * Turns a user name into the address the user opens Dreeve on,
 * e.g. "https://{user}.example.com" becomes "https://alice.example.com".
 */
final readonly class UserUrlTemplate implements \Stringable
{
    public const string VARIABLE = 'DREEVE_USER_URL';
    public const string PLACEHOLDER = '{user}';
    // The user name must be the first part of the hostname: https://{user}.example.com[:port], nothing after it.
    private const string PATTERN = '#^https?://\{user\}\.[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::[0-9]{1,5})?$#i';

    private function __construct(
        private string $template,
    ) {
    }

    public static function fromString(string $template): self
    {
        $template = rtrim(trim($template), '/');
        if (1 !== preg_match(self::PATTERN, $template)) {
            throw InvalidTenant::invalidUserUrl($template);
        }

        return new self($template);
    }

    public function forUser(string $name): string
    {
        return str_replace(self::PLACEHOLDER, $name, $this->template);
    }

    public function __toString(): string
    {
        return $this->template;
    }
}
