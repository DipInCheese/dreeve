<?php

namespace App\Tests;

use Carbon\Carbon;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\LocaleSwitcher;

abstract class ContainerTestCase extends KernelTestCase
{
    use ProvideSettings;

    protected static ?Connection $ourDbalConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$ourDbalConnection instanceof Connection) {
            self::bootKernel();
            self::$ourDbalConnection = self::getContainer()->get(Connection::class);
        }

        // Empty file systems.
        /** @var \League\Flysystem\FilesystemOperator[] $fileSystems */
        $fileSystems = [
            // $this->getContainer()->get('default.storage'),
            $this->getContainer()->get('file.storage'),
        ];

        foreach ($fileSystems as $fileSystem) {
            $fileSystem->deleteDirectory('/');
        }

        // Make sure every test is initialized with the default locale.
        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = $this->getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->reset();
        Carbon::setLocale($localeSwitcher->getLocale());

        // Seed settings.
        $this->provideSettings();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    protected function getConnection(): Connection
    {
        return self::$ourDbalConnection;
    }
}
