<?php

declare(strict_types=1);

namespace Dosiero\Local;

use DateTimeImmutable;
use DirectoryIterator;
use Dosiero\File;
use Dosiero\FileInterface;
use Dosiero\StorageException;
use Dosiero\Thumbnail;
use JsonException;
use SplFileInfo;

use function is_array;

class LocalDirectory
{

    private const CACHE_FILE = '.htdircache';

    private string $folder;

    /**
     * @var array<FileInterface>
     */
    private array $files;

    private string $cacheFile;

    private int $thumbnailSize;

    public function __construct(string $folder, bool $ignoreCache, int $thumbnailSize)
    {
        $this->folder = rtrim($folder, '/') . '/';
        $this->thumbnailSize = $thumbnailSize;

        $loaded = false;
        $this->cacheFile = $this->folder . self::CACHE_FILE;
        if (!$ignoreCache && is_file($this->cacheFile) && filemtime($this->cacheFile) > time() - 7200) {
            $loaded = $this->loadFilesFromCache();
        }
        if (!$loaded) {
            $this->loadFiles();
            $this->saveFilesToCache();
        }
    }

    /**
     * returns array of files and subfolders in folder
     * @return iterable<FileInterface>
     */
    public function getFiles(): iterable
    {
        return $this->files;
    }

    /**
     * refresh cache with  content
     */
    private function loadFiles(): void
    {
        clearstatcache();
        $this->files = [];
        foreach (new DirectoryIterator($this->folder) as $fileInfo) {
            if ($fileInfo->isDot() || $fileInfo->getBasename() === self::CACHE_FILE) {
                continue;
            }
            $this->loadFile((string)$fileInfo->getRealPath());
        }
    }

    private function loadFile(string $realPath): void
    {
        $fileInfo = new SplFileInfo($realPath);
        $modified = new DateTimeImmutable('@' . $fileInfo->getMTime());
        $imageWidth = null;
        $imageHeight = null;
        $thumbnail = null;
        if ($fileInfo !== 'dir') {
            $realPath = (string)$fileInfo->getRealPath();
            $contentType = (string)mime_content_type($realPath);
            $isImage = strncmp($contentType, 'image', 5) === 0;
            if ($isImage) {
                $size = getimagesize($realPath);
                if (is_array($size)) {
                    [$imageWidth, $imageHeight] = $size;
                }
                $thumbnail = Thumbnail::createThumbnailFromFile($realPath, $this->thumbnailSize);
            }
        }

        $file = new File($fileInfo->getBasename(), $fileInfo->getType());
        $file->setSize($fileInfo->getSize());
        $file->setModified($modified->format('c'));
        $file->setWidth($imageWidth);
        $file->setHeight($imageHeight);
        $file->setThumbnail($thumbnail);
        $this->files[$file->getName()] = $file;
    }

    private function saveFilesToCache(): void
    {
        $cache = [];
        foreach ($this->files as $file) {
            $fileName = $file->getName();
            $cache[$fileName] = [
                'name' => $fileName,
                'type' => $file->getType(),
                'size' => $file->getSize(),
                'modified' => $file->getModified(),
                'width' => $file->getWidth(),
                'height' => $file->getHeight(),
                'thumbnail' => $file->getThumbnail(),
            ];
        }
        ksort($cache);
        file_put_contents($this->cacheFile, json_encode($cache, JSON_THROW_ON_ERROR));
    }

    private function loadFilesFromCache(): bool
    {
        try {
            $cache = json_decode((string)file_get_contents($this->cacheFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return false;
        }
        $this->files = [];
        foreach ($cache as $item) {
            $file = new File($item['name'], $item['type']);
            $file->setSize($item['size']);
            $file->setModified($item['modified']);
            $file->setWidth($item['width']);
            $file->setHeight($item['height']);
            $file->setThumbnail($item['thumbnail']);
            $this->files[$file->getName()] = $file;
        }
        return true;
    }

    public function deleteFiles(array $files, bool &$deletedFolder): void
    {
        $deleted = false;
        foreach ($files as $file) {
            $fullPath = $this->folder . $file;
            if (is_dir($fullPath)) {
                $this->rmdirRecursive($fullPath);
                $deletedFolder = true;
                unset($this->files[basename($fullPath)]);
                $deleted = true;
            } else {
                /** @noinspection PhpUsageOfSilenceOperatorInspection */
                if (!@unlink($fullPath)) {
                    throw new StorageException('cannot delete "' . $file . '"');
                }
                unset($this->files[basename($fullPath)]);
                $deleted = true;
            }
        }
        if ($deleted) {
            $this->saveFilesToCache();
        }
    }

    private function rmdirRecursive(string $path): void
    {
        if (trim(pathinfo($path, PATHINFO_BASENAME), '.') === '') {
            return;
        }

        $deleted = false;
        if (is_dir($path)) {
            $files = glob($path . '/{,.}*', GLOB_BRACE | GLOB_NOSORT);
            if (is_array($files)) {
                array_map([$this, 'rmdirRecursive'], $files);
                $deleted = rmdir($path);
            }
        } else {
            $deleted = unlink($path);
        }
        if (!$deleted) {
            throw new StorageException('cannot delete "' . basename($path) . '"');
        }
    }

    public function mkDir(string $newFolder, int $mode): void
    {
        $fullPath = $this->folder . $newFolder;
        if (is_dir($fullPath)) {
            return;
        }

        /** @noinspection MkdirRaceConditionInspection */
        if (mkdir($fullPath, $mode)) {
            $this->loadFile($fullPath);
            $this->saveFilesToCache();
        } else {
            throw new StorageException('cannot create folder "' . $newFolder . '"');
        }
    }

    public function rename(string $oldName, string $newName, bool &$renamedFolder): void
    {
        $oldFullPath = $this->folder . $oldName;
        $newFullPath = $this->folder . $newName;

        /** @noinspection PhpUsageOfSilenceOperatorInspection */
        if (@rename($oldFullPath, $newFullPath)) {
            $renamedFolder = is_dir($newFullPath);
            unset($this->files[$oldName]);
            $this->loadFile($newFullPath);
            $this->saveFilesToCache();
        } else {
            throw new StorageException('cannot rename "' . $oldName . '" to "' . $newName . '"');
        }
    }
}
