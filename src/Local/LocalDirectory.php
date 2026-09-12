<?php

declare(strict_types=1);

namespace Dosiero\Local;

use Dosiero\Cache;
use Dosiero\File;
use Dosiero\FileInterface;
use Dosiero\StorageException;
use Dosiero\Thumbnail;
use Dosiero\Utils;

use function is_array;

class LocalDirectory
{
    private readonly Cache $cache;

    /** @var array<string, FileInterface> key is file name */
    private array $files;

    private readonly string $folder;

    public function __construct(
        string $folder,
        bool $ignoreCache,
        private readonly int $thumbnailSize,
        ?Cache $cache = null,
    ) {
        $this->folder = rtrim($folder, '/') . '/';
        $this->cache = $cache ?? new Cache();

        $cached = $ignoreCache ? null : $this->cache->load($this->folder);
        if ($cached === null) {
            $this->loadFiles();
            $this->saveFilesToCache();
        } else {
            $this->files = $cached;
        }
    }

    /** @param array<string> $files */
    public function deleteFiles(array $files, bool &$deletedFolder): void
    {
        $deleted = false;
        foreach ($files as $file) {
            $fullPath = $this->folder . $file;
            if (is_link($fullPath)) {
                throw new StorageException('cannot delete the link "' . $file . '"');
            }
            if (is_dir($fullPath)) {
                $this->rmdirRecursive($fullPath);
                $deletedFolder = true;
                unset($this->files[basename($fullPath)]);
                $deleted = true;
            } else {
                /* @noinspection PhpUsageOfSilenceOperatorInspection */
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

    /**
     * returns array of files and subfolders in folder
     *
     * @return iterable<FileInterface>
     */
    public function getFiles(): iterable
    {
        return $this->files;
    }

    public function mkDir(string $newFolder, int $mode): void
    {
        $fullPath = $this->folder . $newFolder;
        if (is_dir($fullPath)) {
            return;
        }

        /* @noinspection MkdirRaceConditionInspection */
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

        /* @noinspection PhpUsageOfSilenceOperatorInspection */
        if (@rename($oldFullPath, $newFullPath)) {
            $renamedFolder = is_dir($newFullPath);
            unset($this->files[$oldName]);
            $this->loadFile($newFullPath);
            $this->saveFilesToCache();
        } else {
            throw new StorageException('cannot rename "' . $oldName . '" to "' . $newName . '"');
        }
    }

    private function loadFile(string $realPath): void
    {
        $fileInfo = new \SplFileInfo($realPath);
        $modified = new \DateTimeImmutable('@' . $fileInfo->getMTime());
        $imageWidth = null;
        $imageHeight = null;
        $thumbnail = null;
        if ($fileInfo->getType() !== 'dir') {
            $realPath = (string)$fileInfo->getRealPath();
            $contentType = (string)mime_content_type($realPath);
            $isImage = str_starts_with($contentType, 'image');
            if ($isImage) {
                /* not every image/* is a raster one getimagesize() can read - an svg raises a
                   notice, which with display_errors on lands in the body and breaks the json.
                   A vector image simply has no pixel size and no thumbnail. */
                /* @noinspection PhpUsageOfSilenceOperatorInspection */
                $size = @getimagesize($realPath);
                if (is_array($size)) {
                    [$imageWidth, $imageHeight] = $size;
                    $thumbnail = Thumbnail::createThumbnailFromFile($realPath, $this->thumbnailSize);
                }
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

    /** refresh cache with  content */
    private function loadFiles(): void
    {
        clearstatcache();
        $this->files = [];
        foreach (new \DirectoryIterator($this->folder) as $fileInfo) {
            /* links are not listed at all: the manager would show the target's size and thumbnail
               under a name inside the storage, and every operation on it would reach outside */
            if ($fileInfo->isDot() || $fileInfo->isLink() || Utils::isHiddenName($fileInfo->getBasename())) {
                continue;
            }
            $this->loadFile((string)$fileInfo->getRealPath());
        }
    }

    private function rmdirRecursive(string $path): void
    {
        if (trim(pathinfo($path, PATHINFO_BASENAME), '.') === '') {
            return;
        }

        $deleted = false;
        /* is_dir() follows a link, so a link to a directory used to be descended into and its
           target emptied - outside the storage, where nothing checks the path */
        if (is_link($path)) {
            $deleted = unlink($path);
        } elseif (is_dir($path)) {
            $files = glob($path . '/{,.}*', GLOB_BRACE | GLOB_NOSORT);
            if (is_array($files)) {
                array_map($this->rmdirRecursive(...), $files);
                $deleted = rmdir($path);
            }
        } else {
            $deleted = unlink($path);
        }
        if (!$deleted) {
            throw new StorageException('cannot delete "' . basename($path) . '"');
        }
    }

    private function saveFilesToCache(): void
    {
        $this->cache->save($this->folder, $this->files);
    }
}
