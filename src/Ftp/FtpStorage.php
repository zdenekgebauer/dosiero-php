<?php

declare(strict_types=1);

namespace Dosiero\Ftp;

use Dosiero\Cache;
use Dosiero\File;
use Dosiero\FileInterface;
use Dosiero\Folder;
use Dosiero\FolderInterface;
use Dosiero\Storage;
use Dosiero\StorageException;
use Dosiero\StorageInterface;
use Dosiero\Thumbnail;
use Dosiero\Utils;

use function in_array;

/**
 * There is no realpath() over ftp, so paths are confined lexically, and links are never listed,
 * descended into or operated on instead of being resolved.
 *
 * @phpstan-import-type UploadedFile from StorageInterface
 */
class FtpStorage extends Storage implements StorageInterface
{
    public const string OPTION_BASE_DIR = 'BASE_DIR';

    public const string OPTION_HOST = 'HOST';

    public const string OPTION_LOGIN = 'LOGIN';

    public const string OPTION_PASSIVE = 'PASSIVE';

    public const string OPTION_PASSWORD = 'PASSWORD';

    public const string OPTION_PORT = 'PORT';

    public const string OPTION_SSL = 'SSL';

    public const string OPTION_TIMEOUT = 'TIMEOUT';

    /** @var array<string> */
    private const array IMAGE_EXTENSIONS = ['avif', 'bmp', 'gif', 'jpeg', 'jpg', 'png', 'webp'];

    // no trailing slash, '/' for the server root
    private string $baseDir = '/';

    private ?Cache $cache = null;

    private ?FtpConnection $connection = null;

    private string $host = '';

    private string $login = 'anonymous';

    private bool $passive = true;

    private string $password = '';

    private int $port = 21;

    private bool $ssl = false;

    private int $timeout = 90;

    /** @param array<string> $files */
    public function copy(string $path, array $files, string $targetPath, bool &$copiedFolder): void
    {
        $sourceDir = $this->remotePath($path);
        $targetDir = $this->remotePath($targetPath);
        $sourceEntries = $this->listOrFail($sourceDir, $path);
        $this->assertTransferable($sourceDir, $sourceEntries, $files, $targetDir, $targetPath, 'copy');

        try {
            foreach ($files as $file) {
                $entry = $sourceEntries[$file] ?? null;
                $source = self::child($sourceDir, $file);
                $target = self::child($targetDir, $file);
                if ($entry?->isDir() === true) {
                    $this->copyTree($source, $target);
                    $copiedFolder = true;
                } elseif ($entry === null || !$this->ftp()->copyFile($source, $target)) {
                    throw new StorageException('cannot copy "' . $file . '"');
                }
            }
        } finally {
            $this->refreshCache($targetDir);
        }
    }

    /** @param array<string> $files */
    public function delete(string $path, array $files, bool &$deletedFolder): void
    {
        $directory = $this->remotePath($path);
        $entries = $this->listOrFail($directory, $path);

        try {
            foreach ($files as $file) {
                $entry = $entries[$file] ?? null;
                if ($entry?->isLink() === true) {
                    throw new StorageException('cannot delete the link "' . $file . '"');
                }
                if ($entry?->isDir() === true) {
                    $this->deleteTree(self::child($directory, $file));
                    $deletedFolder = true;
                } elseif ($entry === null || !$this->ftp()->delete(self::child($directory, $file))) {
                    throw new StorageException('cannot delete "' . $file . '"');
                }
            }
        } finally {
            $this->refreshCache($directory);
        }
    }

    /** @return iterable<FileInterface> */
    public function getFiles(string $path, bool $ignoreCache = false): iterable
    {
        $directory = $this->remotePath($path);
        $cached = $this->getCache()->load($this->cacheKey($directory));
        $files = $ignoreCache || $cached === null ? $this->readDirectory($directory, $path, $cached) : $cached;

        $directoryUrl = $this->baseUrl . Utils::encodeUrlPath($path);
        foreach ($files as $file) {
            $file->setDirectoryUrl($directoryUrl);
        }
        return $files;
    }

    /** @return iterable<FolderInterface> */
    public function getFolders(): iterable
    {
        return $this->getSubFolders($this->baseDir, '');
    }

    public function mkDir(string $path, string $newFolder): void
    {
        $directory = $this->remotePath($path);
        $target = self::child($directory, $newFolder);
        if (!$this->ftp()->mkdir($target)) {
            if ($this->ftp()->list($target) !== null) {
                return;
            }
            throw new StorageException('cannot create folder "' . $newFolder . '"');
        }
        $this->ftp()->chmod($target, $this->modeDir);
        $this->refreshCache($directory);
    }

    /** @param array<string> $files */
    public function move(string $path, array $files, string $targetPath, bool &$movedFolder): void
    {
        $sourceDir = $this->remotePath($path);
        $targetDir = $this->remotePath($targetPath);
        $sourceEntries = $this->listOrFail($sourceDir, $path);
        $this->assertTransferable($sourceDir, $sourceEntries, $files, $targetDir, $targetPath, 'move');

        try {
            foreach ($files as $file) {
                $entry = $sourceEntries[$file] ?? null;
                $source = self::child($sourceDir, $file);
                if ($entry === null || !$this->ftp()->rename($source, self::child($targetDir, $file))) {
                    throw new StorageException('cannot move "' . $file . '"');
                }
                if ($entry->isDir()) {
                    $movedFolder = true;
                }
            }
        } finally {
            $this->refreshCache($sourceDir);
            $this->refreshCache($targetDir);
        }
    }

    public function rename(string $path, string $oldName, string $newName, bool &$renamedFolder): void
    {
        $directory = $this->remotePath($path);
        $entries = $this->listOrFail($directory, $path);
        $entry = $entries[$oldName] ?? null;
        if ($entry === null || $entry->isLink()) {
            throw new StorageException('cannot rename "' . $oldName . '" to "' . $newName . '"');
        }
        if (!$entry->isDir()) {
            $this->assertNotExecutable($newName);
        }
        if (!$this->overwriteFiles && isset($entries[$newName])) {
            throw new StorageException('file "' . $newName . '" already exists');
        }

        if (!$this->ftp()->rename(self::child($directory, $oldName), self::child($directory, $newName))) {
            throw new StorageException('cannot rename "' . $oldName . '" to "' . $newName . '"');
        }
        $renamedFolder = $entry->isDir();
        $this->refreshCache($directory);
    }

    public function setOption(string $name, bool|int|string $value): void
    {
        // a connection made with the old settings would outlive them
        $this->connection = null;
        $this->cache = null;
        switch ($name) {
            case self::OPTION_BASE_DIR:
                $this->baseDir = self::normalizeBaseDir((string)$value);
                break;
            case self::OPTION_HOST:
                $this->host = (string)$value;
                break;
            case self::OPTION_LOGIN:
                $this->login = (string)$value;
                break;
            case self::OPTION_PASSIVE:
                $this->passive = (bool)$value;
                break;
            case self::OPTION_PASSWORD:
                $this->password = (string)$value;
                break;
            case self::OPTION_PORT:
                $this->port = (int)$value;
                break;
            case self::OPTION_SSL:
                $this->ssl = (bool)$value;
                break;
            case self::OPTION_TIMEOUT:
                $this->timeout = max(1, (int)$value);
                break;
            default:
                parent::setOption($name, $value);
        }
    }

    /** @param array<string, UploadedFile> $files */
    public function upload(string $path, array $files): void
    {
        $directory = $this->remotePath($path);
        $existing = null;
        $noOverwritten = [];

        try {
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

                if (!$this->overwriteFiles) {
                    $existing ??= $this->listOrFail($directory, $path);
                    if (isset($existing[$fileName])) {
                        $noOverwritten[] = $fileName;
                        continue;
                    }
                }

                $target = self::child($directory, $fileName);
                if (!$this->ftp()->put($target, $field['tmp_name'])) {
                    throw new StorageException('cannot store uploaded file "' . $fileName . '"');
                }
                $this->ftp()->chmod($target, $this->modeFile);
            }
        } finally {
            $this->refreshCache($directory);
        }

        if ($noOverwritten !== []) {
            throw new StorageException('files were not overwritten: ' . implode(',', $noOverwritten));
        }
    }

    protected function createCache(): Cache
    {
        return new Cache($this->cacheEnabled, $this->cacheDirectory, $this->name, true);
    }

    protected function createConnection(): FtpConnection
    {
        return new FtpConnection(
            $this->host,
            $this->port,
            $this->login,
            $this->password,
            $this->ssl,
            $this->passive,
            $this->timeout,
        );
    }

    /**
     * Same rules and messages as the local storage, but compared as strings: the whole batch is
     * refused before the first write.
     *
     * @param array<string, FtpEntry> $sourceEntries
     * @param array<string> $files
     */
    private function assertTransferable(
        string $sourceDir,
        array $sourceEntries,
        array $files,
        string $targetDir,
        string $targetPath,
        string $action,
    ): void {
        $targetEntries = $this->listOrFail($targetDir, $targetPath);
        $notOverwritten = [];
        foreach ($files as $file) {
            $entry = $sourceEntries[$file] ?? null;
            if ($entry === null) {
                continue;
            }
            $source = self::child($sourceDir, $file);
            if ($targetDir === $source || str_starts_with($targetDir, $source . '/')) {
                throw new StorageException('cannot ' . $action . ' "' . $file . '" into itself');
            }
            if ($targetDir === $sourceDir) {
                throw new StorageException('cannot ' . $action . ' "' . $file . '" onto itself');
            }
            if ($entry->isLink()) {
                throw new StorageException('cannot ' . $action . ' the link "' . $file . '"');
            }
            if (!$this->overwriteFiles && isset($targetEntries[$file])) {
                $notOverwritten[] = $file;
            }
        }
        if ($notOverwritten !== []) {
            throw new StorageException('files were not overwritten: ' . implode(',', $notOverwritten));
        }
    }

    // the login is part of it: two chrooted users of one server both see their own home as "/"
    private function cacheKey(string $directory): string
    {
        return 'ftp://' . rawurlencode($this->login) . '@' . $this->host . ':' . $this->port . $directory;
    }

    private static function child(string $directory, string $name): string
    {
        return rtrim($directory, '/') . '/' . $name;
    }

    private function copyTree(string $source, string $target): void
    {
        if (!$this->ftp()->mkdir($target) && $this->ftp()->list($target) === null) {
            throw new StorageException('cannot copy "' . basename($source) . '"');
        }
        $this->ftp()->chmod($target, $this->modeDir);

        foreach ($this->listOrFail($source, basename($source)) as $entry) {
            $from = self::child($source, $entry->name);
            $to = self::child($target, $entry->name);
            if ($entry->isDir()) {
                $this->copyTree($from, $to);
            } elseif (!$entry->isLink() && !$this->ftp()->copyFile($from, $to)) {
                throw new StorageException('cannot copy "' . $entry->name . '"');
            }
        }
    }

    // links inside are removed, never followed
    private function deleteTree(string $directory): void
    {
        foreach ($this->listOrFail($directory, basename($directory)) as $entry) {
            $child = self::child($directory, $entry->name);
            if ($entry->isDir()) {
                $this->deleteTree($child);
            } elseif (!$this->ftp()->delete($child)) {
                throw new StorageException('cannot delete "' . $entry->name . '"');
            }
        }
        if (!$this->ftp()->rmdir($directory)) {
            throw new StorageException('cannot delete "' . basename($directory) . '"');
        }
    }

    private function ftp(): FtpConnection
    {
        return $this->connection ??= $this->createConnection();
    }

    private function getCache(): Cache
    {
        return $this->cache ??= $this->createCache();
    }

    /** @return array<FolderInterface> */
    private function getSubFolders(string $directory, string $path): array
    {
        $result = [];
        foreach ($this->ftp()->list($directory) ?? [] as $entry) {
            if (!$entry->isDir() || Utils::isHiddenName($entry->name)) {
                continue;
            }
            $childPath = ltrim($path . '/' . $entry->name, '/');
            $result[] = new Folder(
                $entry->name,
                $childPath,
                $this->getSubFolders(self::child($directory, $entry->name), $childPath),
            );
        }
        return $result;
    }

    /** @return array<string, FtpEntry> */
    private function listOrFail(string $directory, string $path): array
    {
        return $this->ftp()->list($directory) ?? throw new StorageException('not found path "' . $path . '"');
    }

    private function loadImageFacts(string $remoteFile, string $extension, File $file): void
    {
        $tmpName = tempnam(sys_get_temp_dir(), 'dosiero-ftp-');
        if ($tmpName === false) {
            return;
        }
        // the extension is what Thumbnail picks the output format by
        $localFile = $tmpName . '.' . $extension;
        try {
            if ($this->ftp()->download($remoteFile, $localFile)) {
                $size = @getimagesize($localFile);
                if ($size !== false) {
                    $file->setWidth($size[0]);
                    $file->setHeight($size[1]);
                    $file->setThumbnail(Thumbnail::createThumbnailFromFile($localFile, $this->thumbnailSize));
                }
            }
        } finally {
            @unlink($tmpName);
            @unlink($localFile);
        }
    }

    private static function normalizeBaseDir(string $value): string
    {
        try {
            return '/' . implode('/', self::segments($value));
        } catch (StorageException) {
            throw new \InvalidArgumentException('invalid base directory "' . $value . '"');
        }
    }

    /**
     * @param array<string, FileInterface>|null $previous a listing whose thumbnails can be reused
     * @return array<string, FileInterface>
     */
    private function readDirectory(string $directory, string $path, ?array $previous): array
    {
        $maxFileSize = $this->getMaxFileSize();
        $files = [];
        foreach ($this->listOrFail($directory, $path) as $entry) {
            if ($entry->isLink() || Utils::isHiddenName($entry->name)) {
                continue;
            }
            $file = new File($entry->name, $entry->type);
            $file->setSize($entry->size);
            $file->setModified($entry->modified->format('c'));

            $extension = strtolower(pathinfo($entry->name, PATHINFO_EXTENSION));
            $isImage = !$entry->isDir() && in_array($extension, self::IMAGE_EXTENSIONS, true);
            $known = $previous[$entry->name] ?? null;
            if (
                $isImage
                && $known !== null
                && $known->getSize() === $file->getSize()
                && $known->getModified() === $file->getModified()
            ) {
                $file->setWidth($known->getWidth());
                $file->setHeight($known->getHeight());
                $file->setThumbnail($known->getThumbnail());
            } elseif ($isImage && ($maxFileSize === 0 || $entry->size <= $maxFileSize)) {
                $this->loadImageFacts(self::child($directory, $entry->name), $extension, $file);
            }
            $files[$entry->name] = $file;
        }

        $this->getCache()->save($this->cacheKey($directory), $files);
        return $files;
    }

    // a listing read straight after the change, so the next request served from the cache shows it
    private function refreshCache(string $directory): void
    {
        if (!$this->cacheEnabled || $this->cacheDirectory === '') {
            return;
        }
        $this->readDirectory($directory, $directory, $this->getCache()->load($this->cacheKey($directory)));
    }

    /** @param string $path relative to the base directory; result has no trailing slash */
    private function remotePath(string $path): string
    {
        try {
            $segments = self::segments($path);
        } catch (StorageException) {
            throw new StorageException('not found path "' . $path . '"');
        }
        return $segments === [] ? $this->baseDir : self::child($this->baseDir, implode('/', $segments));
    }

    /** @return array<string> */
    private static function segments(string $path): array
    {
        $segments = array_values(array_filter(explode('/', $path), static fn(string $part): bool => $part !== ''));
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..' || strpbrk($segment, "\\\0") !== false) {
                throw new StorageException('invalid path segment');
            }
        }
        return $segments;
    }
}
