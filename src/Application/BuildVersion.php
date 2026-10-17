<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\ValueObject\String\KernelProjectDir;

/**
 * The app version plus a fingerprint of the code, templates and translations. Images built from
 * a fork all carry the same app version, so the version alone can not tell one build from another.
 */
final class BuildVersion
{
    private const array DIRECTORIES = ['src', 'templates', 'translations'];

    private ?string $version = null;

    public function __construct(
        private readonly KernelProjectDir $projectDir,
    ) {
    }

    public function get(): string
    {
        return $this->version ??= AppVersion::getSemanticVersion().'-'.$this->fingerprint();
    }

    private function fingerprint(): string
    {
        $hashes = [];
        foreach (self::DIRECTORIES as $directory) {
            $path = $this->projectDir.'/'.$directory;
            if (!is_dir($path)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $hashes[substr($file->getPathname(), strlen((string) $this->projectDir))] = (string) md5_file($file->getPathname());
                }
            }
        }
        ksort($hashes);

        return substr(md5(implode('', array_keys($hashes)).implode('', $hashes)), 0, 8);
    }
}
