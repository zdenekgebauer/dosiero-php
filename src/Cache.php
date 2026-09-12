<?php

declare(strict_types=1);

namespace Dosiero;

use function dirname;
use function is_array;

class Cache
{
    /** Legacy name, kept so existing installations do not lose their cache. */
    public const string FILE_NAME = '.htdircache';

    private const int CLEAN_PROBABILITY = 50;

    /** Only catches edits in place - those leave the directory mtime untouched. */
    private const int TTL = 7200;

    private readonly string $cacheDirectory;

    public function __construct(
        private readonly bool $enabled = true,
        string $cacheDirectory = '',
        private readonly string $namespace = '',
    ) {
        $this->cacheDirectory = $cacheDirectory === '' ? '' : rtrim($cacheDirectory, '/') . '/';
    }

    /** A file next to the data dies with it, so only an external directory needs sweeping. */
    public function cleanExpired(): void
    {
        if (!$this->enabled || $this->cacheDirectory === '' || !is_dir($this->cacheDirectory)) {
            return;
        }

        $limit = time() - self::TTL;
        foreach (new \DirectoryIterator($this->cacheDirectory) as $fileInfo) {
            /* only entries this class wrote: the directory may be one the integrator also keeps
               other things in, and sweeping it by age alone deleted them. A link is never ours. */
            if ($fileInfo->isLink() || !$fileInfo->isFile() || !self::isOwnEntry($fileInfo->getFilename())) {
                continue;
            }
            if ($fileInfo->getMTime() < $limit) {
                /* @noinspection PhpUsageOfSilenceOperatorInspection */
                @unlink((string)$fileInfo->getRealPath());
            }
        }
    }

    /** @return array<string, FileInterface>|null */
    public function load(string $directory): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $cacheFile = $this->cacheFile($directory);
        if (!is_file($cacheFile) || filemtime($cacheFile) <= time() - self::TTL) {
            return null;
        }

        try {
            $cache = json_decode((string)file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($cache) || !is_array($cache['files'] ?? null)) {
            return null;
        }
        if (($cache['mtime'] ?? null) !== self::directoryMtime($directory)) {
            return null;
        }

        $result = [];
        foreach ($cache['files'] as $item) {
            if (!is_array($item) || !is_string($item['name'] ?? null) || !is_string($item['type'] ?? null)) {
                return null;
            }
            $file = new File($item['name'], $item['type']);
            $file->setSize(is_int($item['size'] ?? null) ? $item['size'] : 0);
            $file->setModified(is_string($item['modified'] ?? null) ? $item['modified'] : '');
            $file->setWidth(is_int($item['width'] ?? null) ? $item['width'] : null);
            $file->setHeight(is_int($item['height'] ?? null) ? $item['height'] : null);
            $file->setThumbnail(is_string($item['thumbnail'] ?? null) ? $item['thumbnail'] : null);
            $result[$file->getName()] = $file;
        }
        return $result;
    }

    /** @param array<string, FileInterface> $files */
    public function save(string $directory, array $files): void
    {
        if (!$this->enabled) {
            return;
        }

        $cacheFile = $this->cacheFile($directory);
        if (!self::isWritable($cacheFile)) {
            return;
        }

        $serialized = [];
        foreach ($files as $file) {
            $serialized[$file->getName()] = self::serialize($file);
        }
        ksort($serialized);

        $mtime = self::directoryMtime($directory);
        file_put_contents($cacheFile, self::encode($mtime, $serialized));

        $mtimeAfter = self::directoryMtime($directory);
        if ($mtimeAfter !== $mtime) {
            file_put_contents($cacheFile, self::encode($mtimeAfter, $serialized));
        }

        if (random_int(1, self::CLEAN_PROBABILITY) === 1) {
            $this->cleanExpired();
        }
    }

    private function cacheFile(string $directory): string
    {
        if ($this->cacheDirectory === '') {
            return rtrim($directory, '/') . '/' . self::FILE_NAME;
        }
        return $this->cacheDirectory . sha1($this->namespace . "\0" . rtrim($directory, '/')) . '.json';
    }

    private static function directoryMtime(string $directory): ?int
    {
        clearstatcache(true, $directory);
        if (!is_dir($directory)) {
            return null;
        }
        $mtime = filemtime($directory);
        return $mtime === false ? null : $mtime;
    }

    /**
     * @param array<string, array<string, mixed>> $files
     *
     * @throws \JsonException
     */
    private static function encode(?int $mtime, array $files): string
    {
        return json_encode(['mtime' => $mtime, 'files' => $files], JSON_THROW_ON_ERROR);
    }

    /** Cache files are sha1 hashes; anything else in the directory belongs to somebody else. */
    private static function isOwnEntry(string $fileName): bool
    {
        return preg_match('/^[0-9a-f]{40}\.json$/', $fileName) === 1;
    }

    private static function isWritable(string $cacheFile): bool
    {
        if (is_file($cacheFile)) {
            return is_writable($cacheFile);
        }
        $directory = dirname($cacheFile);
        return is_dir($directory) && is_writable($directory);
    }

    /** @return array<string, mixed> */
    private static function serialize(FileInterface $file): array
    {
        return [
            'height' => $file->getHeight(),
            'modified' => $file->getModified(),
            'name' => $file->getName(),
            'size' => $file->getSize(),
            'thumbnail' => $file->getThumbnail(),
            'type' => $file->getType(),
            'width' => $file->getWidth(),
        ];
    }
}
