<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

/**
 * Turns a user name into the address the user opens Dreeve on. Two forms are supported:
 *  - a path on one hostname: "https://dreeve.example.com/{user}" becomes "https://dreeve.example.com/alice"
 *  - a subdomain:            "https://{user}.example.com" becomes "https://alice.example.com"
 */
final readonly class UserUrlTemplate implements \Stringable
{
    public const string VARIABLE = 'DREEVE_USER_URL';
    public const string PLACEHOLDER = '{user}';
    private const string HOST = '[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::[0-9]{1,5})?';
    // https://{user}.example.com[:port], nothing after it.
    private const string SUBDOMAIN_PATTERN = '#^https?://\{user\}\.'.self::HOST.'$#i';
    // https://dreeve.example.com[:port]/{user}, nothing after it.
    private const string PATH_PATTERN = '#^https?://'.self::HOST.'/\{user\}$#i';

    private function __construct(
        private string $template,
        private bool $isPathBased,
    ) {
    }

    public static function fromString(string $template): self
    {
        $template = rtrim(trim($template), '/');

        if (1 === preg_match(self::PATH_PATTERN, $template)) {
            return new self($template, true);
        }
        if (1 === preg_match(self::SUBDOMAIN_PATTERN, $template)) {
            return new self($template, false);
        }

        throw InvalidTenant::invalidUserUrl($template);
    }

    public function isPathBased(): bool
    {
        return $this->isPathBased;
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
