<?php

namespace App\Tests\Infrastructure\Tenancy;

trait ProvideTemporaryProjectDir
{
    private string $projectDir;

    private function createProjectDir(): void
    {
        $this->projectDir = sys_get_temp_dir().'/dreeve-tenancy-test-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/users', 0o777, true);
    }

    private function removeProjectDir(): void
    {
        if (!is_dir($this->projectDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->projectDir);
    }

    private function getUsersDir(): string
    {
        return $this->projectDir.'/users';
    }

    private function assertFilePermissions(int $expected, string $path): void
    {
        clearstatcache();
        $this->assertSame($expected, fileperms($path) & 0o777, sprintf('Unexpected permissions on "%s"', $path));
    }
}
