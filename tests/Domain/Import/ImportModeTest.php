<?php

namespace App\Tests\Domain\Import;

use App\Domain\Import\ImportMode;
use PHPUnit\Framework\TestCase;

class ImportModeTest extends TestCase
{
    private mixed $originalImportMode;

    public function testFromServerVar(): void
    {
        $_SERVER['IMPORT_MODE'] = 'stravaApi';
        $this->assertSame(ImportMode::STRAVA_API, ImportMode::fromServerVar());

        $_SERVER['IMPORT_MODE'] = 'files';
        $this->assertSame(ImportMode::FILES, ImportMode::fromServerVar());
    }

    public function testFromServerVarDefaultsToFiles(): void
    {
        unset($_SERVER['IMPORT_MODE']);

        $this->assertSame(ImportMode::FILES, ImportMode::fromServerVar());
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalImportMode = $_SERVER['IMPORT_MODE'] ?? null;
    }

    #[\Override]
    protected function tearDown(): void
    {
        $_SERVER['IMPORT_MODE'] = $this->originalImportMode;
        if (null === $this->originalImportMode) {
            unset($_SERVER['IMPORT_MODE']);
        }

        parent::tearDown();
    }
}
