<?php

namespace App\Tests\Infrastructure\Tenancy;

use App\Infrastructure\Tenancy\InvalidTenant;
use App\Infrastructure\Tenancy\UserUrlTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserUrlTemplateTest extends TestCase
{
    #[DataProvider('provideValidTemplates')]
    public function testForUser(string $template, string $expectedUrl): void
    {
        $this->assertSame($expectedUrl, UserUrlTemplate::fromString($template)->forUser('alice'));
    }

    public static function provideValidTemplates(): iterable
    {
        yield 'https' => ['https://{user}.example.com', 'https://alice.example.com'];
        yield 'trailing slash' => ['https://{user}.example.com/', 'https://alice.example.com'];
        yield 'deeper domain' => ['https://{user}.dreeve.example.co.uk', 'https://alice.dreeve.example.co.uk'];
        yield 'http with port' => ['http://{user}.localhost:8080', 'http://alice.localhost:8080'];
        yield 'whitespace' => ['  https://{user}.example.com  ', 'https://alice.example.com'];
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
        yield 'placeholder not first' => ['https://dreeve.{user}.example.com'];
        yield 'placeholder in path' => ['https://example.com/{user}'];
        yield 'path after host' => ['https://{user}.example.com/dreeve'];
        yield 'query' => ['https://{user}.example.com?x=1'];
        yield 'other scheme' => ['ftp://{user}.example.com'];
        yield 'credentials' => ['https://me:secret@{user}.example.com'];
        yield 'no domain' => ['https://{user}'];
    }
}
