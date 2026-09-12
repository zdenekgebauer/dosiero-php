<?php

declare(strict_types=1);

namespace Dosiero;

use function is_array;
use function is_scalar;

/**
 * @phpstan-import-type UploadedFile from StorageInterface
 */
class Request
{
    private readonly string $action;

    private readonly string $path;

    private readonly string $storage;

    public function __construct()
    {
        $this->action = self::queryString('action');
        $this->storage = self::queryString('storage');
        $this->path = rtrim(self::queryString('path'), '/');
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getNewFile(): string
    {
        return self::assertNewName(trim(self::postString('new')));
    }

    public function getNewFolder(): string
    {
        return self::assertNewName(trim(self::postString('folder')));
    }

    public function getOldFile(): string
    {
        return self::assertPlainName(trim(self::postString('old')));
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /** @return array<string> */
    public function getSelectedFiles(): array
    {
        if (!isset($_POST['files']) || !is_array($_POST['files'])) {
            return [];
        }
        $names = array_map(static fn(mixed $name): string => is_scalar($name) ? (string)$name : '', $_POST['files']);
        return array_values(array_map(self::assertPlainName(...), $names));
    }

    public function getStorage(): string
    {
        return $this->storage;
    }

    public function getTargetPath(): string
    {
        return trim(self::postString('target_path'));
    }

    public function getTargetStorage(): string
    {
        return trim(self::postString('target_storage'));
    }

    /** @return array<string, UploadedFile> */
    public function getUploadedFiles(): array
    {
        $result = [];
        foreach ($_FILES as $field => $upload) {
            if (!is_array($upload)) {
                continue;
            }
            $name = $upload['name'] ?? null;
            $tmpName = $upload['tmp_name'] ?? null;
            $error = $upload['error'] ?? null;
            if (!is_string($name) || !is_string($tmpName) || !is_int($error)) {
                continue;
            }
            $result[(string)$field] = [
                'name' => $name,
                'tmp_name' => $tmpName,
                'error' => $error,
                'size' => is_int($upload['size'] ?? null) ? $upload['size'] : 0,
                'type' => is_string($upload['type'] ?? null) ? $upload['type'] : '',
            ];
        }
        return $result;
    }

    private static function assertNewName(string $name): string
    {
        self::assertPlainName($name);
        if (!Utils::isValidFileName($name)) {
            throw new InvalidRequestException('name "' . $name . '" contains characters that are not allowed');
        }
        return $name;
    }

    // weaker than assertNewName(): a name that predates the installation still has to be deletable
    private static function assertPlainName(string $name): string
    {
        if (
            $name === ''
            || $name === '.'
            || $name === '..'
            || basename($name) !== $name
            || strpbrk($name, "/\\\0") !== false
            || Utils::isHiddenName($name)
        ) {
            throw new InvalidRequestException('invalid name "' . $name . '"');
        }
        return $name;
    }

    private static function postString(string $key): string
    {
        $value = $_POST[$key] ?? '';
        return is_scalar($value) ? (string)$value : '';
    }

    private static function queryString(string $key): string
    {
        $value = $_GET[$key] ?? '';
        return is_scalar($value) ? (string)$value : '';
    }
}
