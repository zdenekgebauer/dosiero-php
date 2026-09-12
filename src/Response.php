<?php

declare(strict_types=1);

namespace Dosiero;

class Response
{
    public const int STATUS_BAD_REQUEST = 400;

    public const int STATUS_OK = 200;

    private ?Config $config = null;

    /** @var iterable<FileInterface>|null */
    private ?iterable $files = null;

    private ?StorageInterface $storage = null;

    /** @var array<StorageInterface> */
    private array $storages = [];

    public function __construct(
        private readonly int $httpStatus = self::STATUS_OK,
        private readonly string $message = '',
    ) {
    }

    // origins are configured on Config, so an error response built in the entry point carries the
    // same headers as a successful one - without them the client cannot even read the error
    public function allowAccessFrom(Config $config): void
    {
        $this->config = $config;
    }

    // a framework controller builds its own response object, so it needs the status as a value
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function sendOutput(): void
    {
        $this->sendCorsHeaders();
        header('Content-type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        http_response_code($this->httpStatus);
        echo json_encode($this->toStdClass(), JSON_THROW_ON_ERROR);
    }

    /**
     * Answers the request a browser makes before it is willing to send a cross-origin request
     * carrying the protocol header. Returns true when it answered, so the entry point can stop.
     */
    public function sendPreflight(): bool
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if ($method !== 'OPTIONS') {
            return false;
        }

        $this->sendCorsHeaders();
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: ' . Connector::PROTOCOL_HEADER);
        header('Access-Control-Max-Age: 600');
        http_response_code(204);
        return true;
    }

    /** @param iterable<FileInterface> $files */
    public function setFiles(iterable $files): void
    {
        $this->files = $files;
    }

    public function setStorage(StorageInterface $storage): void
    {
        $this->storage = $storage;
    }

    /** @param array<StorageInterface> $storages */
    public function setStorages(array $storages): void
    {
        $this->storages = $storages;
    }

    public function toStdClass(): \stdClass
    {
        $result = new \stdClass();
        $result->msg = $this->message;
        if ($this->files !== null) {
            $result->files = self::filesToStdClass($this->files);
        }
        if ($this->storage !== null) {
            $result->storage = self::storageToStdClass($this->storage);
        }
        if ($this->storages !== []) {
            $result->storages = array_map(
                self::storageToStdClass(...),
                array_values($this->storages),
            );
        }
        return $result;
    }

    /**
     * @param iterable<FileInterface> $files
     * @return array<int, array<string, mixed>>
     */
    private static function filesToStdClass(iterable $files): array
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
     * @param iterable<FolderInterface> $folders
     * @return array<int, array<string, mixed>>
     */
    private static function foldersToStdClass(iterable $folders): array
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

    private function sendCorsHeaders(): void
    {
        if ($this->config === null || $this->config->getAllowedOrigins() === []) {
            return;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $origin = is_string($origin) ? rtrim($origin, '/') : '';
        if ($origin !== '' && $this->config->isOriginAllowed($origin)) {
            // the named origin even for a wildcard configuration: a browser refuses "*" together
            // with credentials, and naming it keeps both cases on one code path
            header('Access-Control-Allow-Origin: ' . $origin);
        } elseif (in_array('*', $this->config->getAllowedOrigins(), true)) {
            header('Access-Control-Allow-Origin: *');
        } else {
            return;
        }

        header('Vary: Origin');
        if ($origin !== '' && $this->config->isCredentialsAllowed($origin)) {
            header('Access-Control-Allow-Credentials: true');
        }
    }

    private static function storageToStdClass(StorageInterface $storage): \stdClass
    {
        $result = new \stdClass();
        $result->name = $storage->getName();
        $result->read_only = $storage->isReadOnly();
        $result->max_file_size = $storage->getMaxFileSize();
        $result->allowed_extensions = $storage->getAllowedExtensions();
        $result->folders = self::foldersToStdClass($storage->getFolders());
        return $result;
    }
}
