<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Application\AppVersion;
use App\Infrastructure\Config\DevMode;
use App\Infrastructure\ValueObject\Identifier\UuidFactory;
use App\Infrastructure\ValueObject\String\KernelProjectDir;
use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;

final class AssetVersioningStrategy implements VersionStrategyInterface
{
    private const string FORMAT = '%s?%s';
    /**
     * The built files, every chunk included (pages load some scripts on demand, like the chat). When one
     * changes, every asset gets a new version, so browsers fetch the new styles and scripts even when the
     * app version stays the same (as it does for every image of a fork).
     */
    private const array BUILT_FILES = [
        'public/css/dist/*.css',
        'public/js/dist/*.js',
    ];

    private ?string $version = null;

    public function __construct(
        private readonly DevMode $devMode,
        private readonly UuidFactory $uuidFactory,
        private readonly ?KernelProjectDir $projectDir = null,
    ) {
    }

    public function getVersion(string $path): string
    {
        if ($this->devMode->isEnabled()) {
            return $this->uuidFactory->random();
        }

        return $this->version ??= $this->buildVersion();
    }

    public function applyVersion(string $path): string
    {
        $versionized = \sprintf(self::FORMAT, ltrim($path, '/'), $this->getVersion($path));

        if ($path && '/' === $path[0]) {
            return '/'.$versionized;
        }

        return $versionized;
    }

    private function buildVersion(): string
    {
        $version = AppVersion::getSemanticVersion();
        if (!$this->projectDir instanceof KernelProjectDir) {
            return $version;
        }

        $hashes = '';
        foreach (self::BUILT_FILES as $pattern) {
            $paths = glob($this->projectDir.'/'.$pattern) ?: [];
            sort($paths);
            foreach ($paths as $path) {
                $hashes .= basename($path).md5_file($path);
            }
        }

        return '' === $hashes ? $version : $version.'-'.substr(md5($hashes), 0, 8);
    }
}
