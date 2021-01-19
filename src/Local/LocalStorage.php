<?php

declare(strict_types=1);

namespace Dosiero\Local;

use DirectoryIterator;
use Dosiero\Folder;
use Dosiero\FolderInterface;
use Dosiero\Storage;
use Dosiero\StorageException;
use Dosiero\StorageInterface;
use Dosiero\UploadException;
use Dosiero\Utils;
use InvalidArgumentException;
use RuntimeException;

class LocalStorage extends Storage implements StorageInterface
{

    public const OPTION_BASE_DIR = 'BASE_DIR';

    /**
     * @var string include trailing slash
     */
    private string $baseDir = '';

    public function setOption(string $name, bool | int | string $value): void
    {
        if ($name === self::OPTION_BASE_DIR) {
            $value = (string)$value;
            if (!is_dir($value)) {
                throw new InvalidArgumentException('directory "' . $value . '" not found');
            }
            $this->baseDir = rtrim($value, '/') . '/';
        } else {
            parent::setOption($name, $value);
        }
    }

    public function getFolders(): iterable
    {
        return $this->getSubFolders($this->baseDir);
    }

    /**
     * recursive function, returns folders in given directory
     * @param string $dir
     * @return iterable<FolderInterface>
     */
    private function getSubFolders(string $dir): iterable
    {
        $result = [];

        $iterator = new DirectoryIterator($dir);
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir() && !$fileInfo->isDot()) {
                $path = str_replace([$this->baseDir, '\\'], ['', '/'], $fileInfo->getPathname());
                $path = trim($path, '/');

                $item = new Folder($fileInfo->getBasename(), $path, $this->getSubFolders($fileInfo->getPathname()));
                $result[] = $item;
            }
        }
        return $result;
    }

    public function getFiles(string $path, bool $ignoreCache = false): iterable
    {
        $targetDir = $this->absPath($path);
        $directory = new LocalDirectory($targetDir, $ignoreCache, $this->thumbnailSize);
        $files = $directory->getFiles();
        foreach ($files as $file) {
            $file->setDirectoryUrl($this->baseUrl . $path);
        }
        return $files;
    }

    private function absPath(string $path): string
    {
        $path = trim($path, '/');
        $fullPath = $this->baseDir . $path;
        if (!is_dir($fullPath)) {
            throw new StorageException('not found path "' . $path . '"');
        }
        return $fullPath;
    }

    public function mkDir(string $path, string $newFolder): void
    {
        $targetDir = $this->absPath($path);
        $directory = new LocalDirectory($targetDir, false, $this->thumbnailSize);
        $directory->mkDir($newFolder, $this->modeDir);
    }

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
            }

            $targetFullPath = $targetDir . $fileName;
            if (!$this->overwriteFiles && is_file($targetFullPath)) {
                $noOverwritten[] = $fileName;
                continue;
            }

            // move_uploaded_file doesn`t work in tests in docker environment
            $uploadFunction = (PHP_SAPI === 'cli' ? 'copy' : 'move_uploaded_file');
            $uploadFunction($field['tmp_name'], $targetFullPath);
            chmod($targetFullPath, $this->modeFile);
        }
        if (!empty($noOverwritten)) {
            throw new StorageException('files were not overwritten: ' . implode(',', $noOverwritten));
        }
    }

    public function delete(string $path, array $files, bool &$deletedFolder): void
    {
        $directory = new LocalDirectory($this->absPath($path), false, $this->thumbnailSize);
        $directory->deleteFiles($files, $deletedFolder);
    }

    public function rename(string $path, string $oldName, string $newName, bool &$renamedFolder): void
    {
        $directory = new LocalDirectory($this->absPath($path), false, $this->thumbnailSize);
        $directory->rename($oldName, $newName, $renamedFolder);
    }

    public function copy(string $path, array $files, string $targetPath, bool &$copiedFolder): void
    {
        $sourceDir = $this->absPath($path);
        $targetDir = $this->absPath($targetPath);

        foreach ($files as $file) {
            $sourceFullPath = $sourceDir . $file;
            if (is_dir($sourceFullPath)) {
                $this->recursiveCopy($sourceFullPath, $targetDir . '/' . $file);
                $copiedFolder = true;
            } elseif (is_file($sourceFullPath)) {
                copy($sourceFullPath, $targetDir . '/' . $file);
            } else {
                throw new StorageException('cannot copy "' . $file . '"');
            }
        }
        new LocalDirectory($targetDir, true, $this->thumbnailSize);
    }

    private function recursiveCopy(string $sourceDir, string $targetDir): void
    {
        if (!is_dir($targetDir) && !mkdir($targetDir, $this->modeDir) && !is_dir($targetDir)) {
            throw new RuntimeException('Directory "' . $targetDir . '" was not created');
        }

        $iterator = new DirectoryIterator($sourceDir);
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile()) {
                copy((string)$fileInfo->getRealPath(), $targetDir . '/' . $fileInfo->getFilename());
            } elseif (!$fileInfo->isDot() && $fileInfo->isDir()) {
                $this->recursiveCopy((string)$fileInfo->getRealPath(), $targetDir . '/' . $fileInfo);
            }
        }
    }

    public function move(string $path, array $files, string $targetPath, bool &$movedFolder): void
    {
        $sourceDir = $this->absPath($path);
        $targetDir = $this->absPath($targetPath);

        foreach ($files as $file) {
            $sourceFullPath = $sourceDir . $file;
            if (is_dir($sourceFullPath)) {
                $this->recursiveMove($sourceFullPath, $targetDir . '/' . $file);
                $movedFolder = true;
            } elseif (is_file($sourceFullPath)) {
                rename($sourceFullPath, $targetDir . '/' . $file);
            } else {
                throw new StorageException('cannot move "' . $file . '"');
            }
        }
        new LocalDirectory($targetDir, true, $this->thumbnailSize);
    }

    private function recursiveMove(string $sourceDir, string $targetDir): void
    {
        if (!is_dir($targetDir) && !mkdir($targetDir, $this->modeDir) && !is_dir($targetDir)) {
            throw new RuntimeException('Directory "' . $targetDir . '" was not created');
        }

        $iterator = new DirectoryIterator($sourceDir);
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile()) {
                rename((string)$fileInfo->getRealPath(), $targetDir . '/' . $fileInfo->getFilename());
            } elseif (!$fileInfo->isDot() && $fileInfo->isDir()) {
                $this->recursiveMove((string)$fileInfo->getRealPath(), $targetDir . '/' . $fileInfo);
            }
        }
        rmdir($sourceDir);
    }
}
