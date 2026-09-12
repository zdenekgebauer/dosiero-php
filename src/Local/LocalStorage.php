<?php

declare(strict_types=1);

namespace Dosiero\Local;

use Dosiero\Folder;
use Dosiero\FolderInterface;
use Dosiero\Storage;
use Dosiero\StorageException;
use Dosiero\StorageInterface;
use Dosiero\Utils;

/**
 * @phpstan-import-type UploadedFile from StorageInterface
 */
class LocalStorage extends Storage implements StorageInterface
{
    public const string OPTION_BASE_DIR = 'BASE_DIR';

    /** @var string include trailing slash */
    private string $baseDir = '';

    /** @param array<string> $files */
    public function copy(string $path, array $files, string $targetPath, bool &$copiedFolder): void
    {
        $sourceDir = $this->absPath($path);
        $targetDir = $this->absPath($targetPath);
        $this->assertTransferable($sourceDir, $files, $targetDir, 'copy');

        foreach ($files as $file) {
            $sourceFullPath = $sourceDir . '/' . $file;
            if (is_dir($sourceFullPath)) {
                $this->recursiveCopy($sourceFullPath, $targetDir . '/' . $file);
                $copiedFolder = true;
            } elseif (is_file($sourceFullPath)) {
                if (!copy($sourceFullPath, $targetDir . '/' . $file)) {
                    throw new StorageException('cannot copy "' . $file . '"');
                }
            } else {
                throw new StorageException('cannot copy "' . $file . '"');
            }
        }
        new LocalDirectory($targetDir, true, $this->thumbnailSize, $this->createCache());
    }

    /** @param array<string> $files */
    public function delete(string $path, array $files, bool &$deletedFolder): void
    {
        $directory = new LocalDirectory($this->absPath($path), false, $this->thumbnailSize, $this->createCache());
        $directory->deleteFiles($files, $deletedFolder);
    }

    public function getFiles(string $path, bool $ignoreCache = false): iterable
    {
        $targetDir = $this->absPath($path);
        $directory = new LocalDirectory($targetDir, $ignoreCache, $this->thumbnailSize, $this->createCache());
        $files = $directory->getFiles();
        $directoryUrl = $this->baseUrl . self::encodePath($path);
        foreach ($files as $file) {
            $file->setDirectoryUrl($directoryUrl);
        }
        return $files;
    }

    public function getFolders(): iterable
    {
        return $this->getSubFolders($this->baseDir);
    }

    public function mkDir(string $path, string $newFolder): void
    {
        $targetDir = $this->absPath($path);
        $directory = new LocalDirectory($targetDir, false, $this->thumbnailSize, $this->createCache());
        $directory->mkDir($newFolder, $this->modeDir);
    }

    /** @param array<string> $files */
    public function move(string $path, array $files, string $targetPath, bool &$movedFolder): void
    {
        $sourceDir = $this->absPath($path);
        $targetDir = $this->absPath($targetPath);
        $this->assertTransferable($sourceDir, $files, $targetDir, 'move');

        foreach ($files as $file) {
            $sourceFullPath = $sourceDir . '/' . $file;
            if (is_dir($sourceFullPath)) {
                $this->recursiveMove($sourceFullPath, $targetDir . '/' . $file);
                $movedFolder = true;
            } elseif (is_file($sourceFullPath)) {
                if (!rename($sourceFullPath, $targetDir . '/' . $file)) {
                    throw new StorageException('cannot move "' . $file . '"');
                }
            } else {
                throw new StorageException('cannot move "' . $file . '"');
            }
        }
        new LocalDirectory($targetDir, true, $this->thumbnailSize, $this->createCache());
    }

    public function rename(string $path, string $oldName, string $newName, bool &$renamedFolder): void
    {
        $absPath = $this->absPath($path);
        $source = $absPath . '/' . $oldName;
        // a folder carries no extension to judge, and a link is not followed anywhere else either
        if (is_file($source) && !is_link($source)) {
            $this->assertNotExecutable($newName);
        }
        if (!$this->overwriteFiles && file_exists($absPath . '/' . $newName)) {
            throw new StorageException('file "' . $newName . '" already exists');
        }
        $directory = new LocalDirectory($absPath, false, $this->thumbnailSize, $this->createCache());
        $directory->rename($oldName, $newName, $renamedFolder);
    }

    public function setOption(string $name, bool|int|string $value): void
    {
        if ($name === self::OPTION_BASE_DIR) {
            $value = (string)$value;
            if (!is_dir($value)) {
                throw new \InvalidArgumentException('directory "' . $value . '" not found');
            }
            $this->baseDir = rtrim($value, '/') . '/';
        } else {
            parent::setOption($name, $value);
        }
    }

    /** @param array<string, UploadedFile> $files */
    public function upload(string $path, array $files): void
    {
        $targetDir = $this->absPath($path);
        $noOverwritten = [];

        foreach ($files as $field) {
            if ($field['error'] !== 0) {
                throw new StorageException('upload "' . $field['name'] . '" failed', $field['error']);
            }
            $fileName = basename($field['name']);
            if ($this->normalizeNames) {
                $fileName = Utils::normalizeFileName($fileName);
            } elseif (!Utils::isValidFileName($fileName)) {
                throw new StorageException('invalid file name "' . $fileName . '"');
            }
            $this->assertAllowedUpload($fileName, $field['tmp_name']);

            $targetFullPath = $targetDir . '/' . $fileName;
            if (!$this->overwriteFiles && is_file($targetFullPath)) {
                $noOverwritten[] = $fileName;
                continue;
            }

            $uploadFunction = (PHP_SAPI === 'cli' ? 'copy' : 'move_uploaded_file');
            if (!$uploadFunction($field['tmp_name'], $targetFullPath)) {
                throw new StorageException('cannot store uploaded file "' . $fileName . '"');
            }
            chmod($targetFullPath, $this->modeFile);
        }
        if ($noOverwritten !== []) {
            throw new StorageException('files were not overwritten: ' . implode(',', $noOverwritten));
        }
    }

    /** @param string $path absolute path without trailing slash */
    private function absPath(string $path): string
    {
        $baseDir = realpath($this->baseDir);
        $fullPath = realpath($this->baseDir . trim($path, '/'));

        if ($baseDir === false || $fullPath === false || !self::isInsideBaseDir($fullPath, $baseDir)) {
            throw new StorageException('not found path "' . $path . '"');
        }

        return $fullPath;
    }

    /**
     * Runs before the first change, so a batch that would recurse into itself does not leave half
     * of the files moved behind it.
     *
     * @param array<string> $files
     */
    private function assertTransferable(string $sourceDir, array $files, string $targetDir, string $action): void
    {
        $notOverwritten = [];
        foreach ($files as $file) {
            $source = realpath($sourceDir . '/' . $file);
            if ($source === false) {
                continue;
            }
            /* a target inside the source is what makes the iterator walk into the copy it is
               writing, growing A/A/A until the disk or the process gives out; the same path on both
               sides truncates a file, because php copy() opens the destination for writing first */
            if (self::isInsideBaseDir($targetDir, $source)) {
                throw new StorageException('cannot ' . $action . ' "' . $file . '" into itself');
            }
            if (realpath($targetDir . '/' . $file) === $source) {
                throw new StorageException('cannot ' . $action . ' "' . $file . '" onto itself');
            }
            if (is_link($sourceDir . '/' . $file)) {
                throw new StorageException('cannot ' . $action . ' the link "' . $file . '"');
            }
            if (!$this->overwriteFiles && file_exists($targetDir . '/' . $file)) {
                $notOverwritten[] = $file;
            }
        }
        if ($notOverwritten !== []) {
            throw new StorageException('files were not overwritten: ' . implode(',', $notOverwritten));
        }
    }

    /** result is either empty or ends with a slash */
    private static function encodePath(string $path): string
    {
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }
        return implode('/', array_map(rawurlencode(...), explode('/', $path))) . '/';
    }

    /** @return iterable<FolderInterface> */
    private function getSubFolders(string $dir): iterable
    {
        $result = [];

        $iterator = new \DirectoryIterator($dir);
        foreach ($iterator as $fileInfo) {
            if (
                $fileInfo->isDir()
                && !$fileInfo->isDot()
                && !$fileInfo->isLink()
                && !Utils::isHiddenName($fileInfo->getBasename())
            ) {
                $path = str_replace([$this->baseDir, '\\'], ['', '/'], $fileInfo->getPathname());
                $path = trim($path, '/');

                $item = new Folder($fileInfo->getBasename(), $path, $this->getSubFolders($fileInfo->getPathname()));
                $result[] = $item;
            }
        }
        return $result;
    }

    /** Both arguments must already be canonicalized by realpath(). */
    private static function isInsideBaseDir(string $path, string $baseDir): bool
    {
        return $path === $baseDir || str_starts_with($path, $baseDir . DIRECTORY_SEPARATOR);
    }

    private function recursiveCopy(string $sourceDir, string $targetDir): void
    {
        if (!is_dir($targetDir) && !mkdir($targetDir, $this->modeDir) && !is_dir($targetDir)) {
            throw new \RuntimeException('Directory "' . $targetDir . '" was not created');
        }

        $iterator = new \DirectoryIterator($sourceDir);
        foreach ($iterator as $fileInfo) {
            /* a link is never followed: absPath() guards the root of the request, not every entry
               met on the way down, so descending into one would copy data from outside the storage */
            if ($fileInfo->isLink()) {
                continue;
            }
            if ($fileInfo->isFile()) {
                if (!copy((string)$fileInfo->getRealPath(), $targetDir . '/' . $fileInfo->getFilename())) {
                    throw new StorageException('cannot copy "' . $fileInfo->getFilename() . '"');
                }
            } elseif (!$fileInfo->isDot() && $fileInfo->isDir()) {
                $this->recursiveCopy((string)$fileInfo->getRealPath(), $targetDir . '/' . $fileInfo);
            }
        }
    }

    private function recursiveMove(string $sourceDir, string $targetDir): void
    {
        if (!is_dir($targetDir) && !mkdir($targetDir, $this->modeDir) && !is_dir($targetDir)) {
            throw new \RuntimeException('Directory "' . $targetDir . '" was not created');
        }

        $iterator = new \DirectoryIterator($sourceDir);
        $movedEverything = true;
        foreach ($iterator as $fileInfo) {
            // see recursiveCopy(): a link stays where it is rather than dragging its target along
            if ($fileInfo->isLink()) {
                $movedEverything = false;
                continue;
            }
            if ($fileInfo->isFile()) {
                if (!rename((string)$fileInfo->getRealPath(), $targetDir . '/' . $fileInfo->getFilename())) {
                    throw new StorageException('cannot move "' . $fileInfo->getFilename() . '"');
                }
            } elseif (!$fileInfo->isDot() && $fileInfo->isDir()) {
                $this->recursiveMove((string)$fileInfo->getRealPath(), $targetDir . '/' . $fileInfo);
            }
        }
        // a directory still holding a link it refused to move cannot be removed, and must not be
        if ($movedEverything) {
            rmdir($sourceDir);
        }
    }
}
