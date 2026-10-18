<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Infrastructure\ValueObject\String\DataDir;
use App\Infrastructure\ValueObject\String\Path;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;

final readonly class WatchDirectory
{
    private const string FOLDER_NAME = 'watch';
    private const string STAGING_FOLDER_NAME = self::FOLDER_NAME.'/.uploads';
    public const string FAILED_FOLDER_NAME = self::FOLDER_NAME.'/failed';

    public function __construct(
        private DataDir $dataDir,
        private FilesystemOperator $defaultStorage,
    ) {
    }

    public function exists(): bool
    {
        return $this->defaultStorage->directoryExists(self::FOLDER_NAME);
    }

    public function hasFilesThatCanBeProcessed(): bool
    {
        foreach ($this->listFilesThatCanBeProcessed() as $processableFile) {
            return true;
        }

        return false;
    }

    public function listFilesThatCanBeProcessed(): DirectoryListing
    {
        return $this->listFiles()
            ->filter(fn (StorageAttributes $file): bool => SupportedFileExtension::tryFrom(
                Path::fromString($file->path())->getExtension()
            ) instanceof SupportedFileExtension)
            ->sortByPath();
    }

    public function listFiles(): DirectoryListing
    {
        return $this->defaultStorage->listContents(self::FOLDER_NAME, false)
            ->filter(fn (StorageAttributes $attributes): bool => $attributes->isFile());
    }

    public function readFile(Path $filePath): string
    {
        return $this->defaultStorage->read(self::FOLDER_NAME.'/'.$filePath->getBasename());
    }

    public function writeFile(ActivityFileName $filename, string $contents): ActivityFileName
    {
        $availableFilename = $filename;
        $suffix = 1;
        while ($this->defaultStorage->fileExists(self::FOLDER_NAME.'/'.$availableFilename)) {
            $availableFilename = $filename->withSuffix((string) $suffix++);
        }

        $stagedPath = self::STAGING_FOLDER_NAME.'/'.$availableFilename;
        $this->defaultStorage->write($stagedPath, $contents);
        $this->defaultStorage->move($stagedPath, self::FOLDER_NAME.'/'.$availableFilename);

        return $availableFilename;
    }

    /**
     * Moves a file that broke the import out of the way, so it can not block the files after it.
     * It is kept, for a look later.
     */
    public function moveToFailed(Path $filePath): void
    {
        $target = self::FAILED_FOLDER_NAME.'/'.$filePath->getBasename();
        if ($this->defaultStorage->fileExists($target)) {
            $this->defaultStorage->delete($target);
        }
        $this->defaultStorage->move(self::FOLDER_NAME.'/'.$filePath->getBasename(), $target);
    }

    public function deleteFile(Path $filePath): void
    {
        $this->defaultStorage->delete(self::FOLDER_NAME.'/'.$filePath->getBasename());
    }

    public function getAbsolutePathFor(StorageAttributes $file): Path
    {
        return Path::fromString($this->dataDir.'/'.$file->path());
    }
}
