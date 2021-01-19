<?php

declare(strict_types=1);

namespace Dosiero;

use InvalidArgumentException;
use stdClass;

class Response
{

    public const STATUS_OK = 200;

    public const STATUS_BAD_REQUEST = 400;

    private int $httpStatus;

    private string $message;

    /**
     * @var iterable<FileInterface>|null
     */
    private $files;

    /**
     * @var StorageInterface[]
     */
    private $storages = [];

    /**
     * @var StorageInterface
     */
    private $storage;

    private string $allowDomain = '';

    public function __construct(int $httpStatus = self::STATUS_OK, string $message = '')
    {
        $this->httpStatus = $httpStatus;
        $this->message = $message;
    }

    /**
     * @param iterable<FileInterface> $files
     */
    public function setFiles(iterable $files): void
    {
        $this->files = $files;
    }

    /**
     * @param StorageInterface[] $storages
     */
    public function setStorages(array $storages): void
    {
        $this->storages = $storages;
    }

    public function setStorage(StorageInterface $storage): void
    {
        $this->storage = $storage;
    }

    public function toStdClass(): stdClass
    {
        $result = new stdClass();
        $result->msg = $this->message;
        if ($this->files !== null) {
            $result->files = self::filesToStdClass($this->files);
        }
        if ($this->storage !== null) {
            $result->storage = new stdClass();
            $result->storage->name = $this->storage->getName();
            $result->storage->read_only = $this->storage->isReadOnly();
            $result->storage->folders = self::foldersToStdClass($this->storage->getFolders());
        }
        if ($this->storages) {
            foreach ($this->storages as $storage) {
                $item = new stdClass();
                $item->name = $storage->getName();
                $item->read_only = $storage->isReadOnly();
                $item->folders = self::foldersToStdClass($storage->getFolders());
                $result->storages[] = $item;
            }
        }
        return $result;
    }

    /**
     * @param FolderInterface[] $folders
     * @return array
     */
    private static function foldersToStdClass(iterable $folders): iterable
    {
        $result = [];
        foreach ($folders as $folder) {
            $result[] = [
                'name' => $folder->getName(),
                'path' => $folder->getPath(),
                'folders' => self::foldersToStdClass($folder->getFolders()),
            ];
        }
        return $result;
    }

    /**
     * @param FileInterface[] $files
     * @return array
     */
    private static function filesToStdClass(iterable $files): iterable
    {
        $result = [];
        foreach ($files as $file) {
            $result[] = [
                'name' => $file->getName(),
                'type' => $file->getType(),
                'size' => $file->getSize(),
                'modified' => $file->getModified(),
                'url' => $file->getUrl(),
                'width' => $file->getWidth(),
                'height' => $file->getHeight(),
                'thumbnail' => $file->getThumbnail(),
            ];
        }
        return $result;
    }

    /**
     * @param string $domain including protocol or *
     */
    public function allowAccessFromDomain(string $domain): void
    {
        if ($domain !== '*' && strncmp($domain, 'http', 4) !== 0) {
            throw new InvalidArgumentException('expected domain including protocol or *');
        }
        $this->allowDomain = $domain;
    }

    public function sendOutput(): void
    {
        if ($this->allowDomain === '*') {
            header('Access-Control-Allow-Origin: *');
        }
        if ($this->allowDomain !== '') {
            header('Access-Control-Allow-Origin: ' . $this->allowDomain);
            header('Vary: Origin');
        }
        header('Content-type: application/json; charset=utf-8');
        http_response_code($this->httpStatus);
        echo json_encode($this->toStdClass(), JSON_THROW_ON_ERROR);
    }
}
