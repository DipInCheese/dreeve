<?php

namespace App\Tests\Infrastructure\Tenancy;

use App\Infrastructure\Tenancy\InvalidTenant;
use App\Infrastructure\Tenancy\UserUrlTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserUrlTemplateTest extends TestCase
{
    #[DataProvider('provideValidTemplates')]
    public function testForUser(string $template, string $expectedUrl, bool $expectedPathBased): void
    {
        $userUrlTemplate = UserUrlTemplate::fromString($template);

        $this->assertSame($expectedUrl, $userUrlTemplate->forUser('alice'));
        $this->assertSame($expectedPathBased, $userUrlTemplate->isPathBased());
    }

    public static function provideValidTemplates(): iterable
    {
        yield 'path' => ['https://dreeve.example.com/{user}', 'https://dreeve.example.com/alice', true];
        yield 'path, trailing slash' => ['https://dreeve.example.com/{user}/', 'https://dreeve.example.com/alice', true];
        yield 'path, http with port' => ['http://localhost:8080/{user}', 'http://localhost:8080/alice', true];
        yield 'subdomain' => ['https://{user}.example.com', 'https://alice.example.com', false];
        yield 'subdomain, trailing slash' => ['https://{user}.example.com/', 'https://alice.example.com', false];
        yield 'subdomain, deeper domain' => ['https://{user}.dreeve.example.co.uk', 'https://alice.dreeve.example.co.uk', false];
        yield 'subdomain, http with port' => ['http://{user}.localhost:8080', 'http://alice.localhost:8080', false];
        yield 'whitespace' => ['  https://dreeve.example.com/{user}  ', 'https://dreeve.example.com/alice', true];
    }

    #[DataProvider('provideInvalidTemplates')]
    public function testFromStringItShouldThrow(string $template): void
    {
        $this->expectExceptionObject(InvalidTenant::invalidUserUrl(rtrim(trim($template), '/')));

        UserUrlTemplate::fromString($template);
    }

    public static function provideInvalidTemplates(): iterable
    {
        yield 'empty' => [''];
        yield 'no placeholder' => ['https://example.com'];
        yield 'placeholder in the middle of the hostname' => ['https://dreeve.{user}.example.com'];
        yield 'placeholder deeper in the path' => ['https://example.com/dreeve/{user}'];
        yield 'path after placeholder' => ['https://example.com/{user}/dreeve'];
        yield 'path after subdomain' => ['https://{user}.example.com/dreeve'];
        yield 'placeholder in hostname and path' => ['https://{user}.example.com/{user}'];
        yield 'query' => ['https://example.com/{user}?x=1'];
        yield 'other scheme' => ['ftp://example.com/{user}'];
        yield 'credentials' => ['https://me:secret@example.com/{user}'];
        yield 'no domain' => ['https://{user}'];
    }
}
