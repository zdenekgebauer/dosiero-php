<?php

declare(strict_types=1);

namespace Dosiero;

use function is_array;

class Request
{

    /**
     * @var string
     */
    private $storage;

    /**
     * @var string
     */
    private $path;

    /**
     * @var string
     */
    private $action;

    public function __construct()
    {
        $this->action = (string)($_GET['action'] ?? '');
        $this->storage = (string)($_GET['storage'] ?? '');
        $this->path = rtrim((string)($_GET['path'] ?? ''), '/');
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getStorage(): string
    {
        return $this->storage;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getSelectedFiles(): array
    {
        if (!isset($_POST['files']) || !is_array($_POST['files'])) {
            return [];
        }
        return array_map([self::class, 'assertPlainName'], array_map('\strval', $_POST['files']));
    }

    public function getNewFolder(): string
    {
        return self::assertNewName(trim($_POST['folder'] ?? ''));
    }

    /**
     * For names of items that already exist. Deliberately weaker than
     * assertNewName(): such a name may predate the installation or come from
     * outside, and it still has to be possible to delete or move it.
     */
    private static function assertPlainName(string $name): string
    {
        if (
            $name === ''
            || $name === '.'
            || $name === '..'
            || basename($name) !== $name
            || strpbrk($name, "/\\\0") !== false
        ) {
            throw new InvalidRequestException('invalid name "' . $name . '"');
        }
        return $name;
    }

    /**
     * For names being created: additionally has to be storable on every supported system.
     */
    private static function assertNewName(string $name): string
    {
        self::assertPlainName($name);
        if (!Utils::isValidFileName($name)) {
            throw new InvalidRequestException('name "' . $name . '" contains characters that are not allowed');
        }
        return $name;
    }

    public function getTargetStorage(): string
    {
        return trim($_POST['target_storage'] ?? '');
    }

    public function getTargetPath(): string
    {
        return trim($_POST['target_path'] ?? '');
    }

    public function getUploadedFiles(): array
    {
        return $_FILES ?? [];
    }

    public function getOldFile(): string
    {
        return self::assertPlainName(trim($_POST['old'] ?? ''));
    }

    public function getNewFile(): string
    {
        return self::assertNewName(trim($_POST['new'] ?? ''));
    }
}
