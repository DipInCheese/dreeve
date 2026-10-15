<?php

declare(strict_types=1);

namespace App\Infrastructure\ValueObject\String;

/**
 * Root directory of everything the app writes (storage/, build/ and watch/).
 * It is the project dir, unless the app runs for one user of a multi-user installation.
 */
final readonly class DataDir extends NonEmptyStringLiteral
{
}
