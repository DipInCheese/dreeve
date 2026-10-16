<?php

declare(strict_types=1);

namespace App\Domain\Health;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;

/**
 * Where sources (health-sync for Garmin, later others like a smart scale) drop health data for the
 * app: one JSON file per delivery, in the user's data folder.
 */
final readonly class HealthInbox
{
    private const string FOLDER = 'inbox/health';
    private const string FAILED_FOLDER = self::FOLDER.'/failed';

    public function __construct(
        private FilesystemOperator $defaultStorage,
    ) {
    }

    public function hasFiles(): bool
    {
        return [] !== $this->listFiles();
    }

    /**
     * @return list<string> paths, oldest delivery first
     */
    public function listFiles(): array
    {
        if (!$this->defaultStorage->directoryExists(self::FOLDER)) {
            return [];
        }

        $paths = $this->defaultStorage->listContents(self::FOLDER, false)
            ->filter(static fn (StorageAttributes $attributes): bool => $attributes->isFile()
                && str_ends_with($attributes->path(), '.json')
                // Files still being written start with a dot.
                && !str_starts_with(basename($attributes->path()), '.'))
            ->map(static fn (StorageAttributes $attributes): string => $attributes->path())
            ->toArray();
        sort($paths);

        return $paths;
    }

    public function read(string $path): string
    {
        return $this->defaultStorage->read($path);
    }

    public function delete(string $path): void
    {
        $this->defaultStorage->delete($path);
    }

    public function moveToFailed(string $path): void
    {
        $this->defaultStorage->move($path, self::FAILED_FOLDER.'/'.basename($path));
    }
}
