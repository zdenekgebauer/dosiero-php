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
        return isset($_POST['files']) && is_array($_POST['files']) ? array_map('\strval', $_POST['files']) : [];
    }

    public function getNewFolder(): string
    {
        return trim($_POST['folder'] ?? '');
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
        return trim($_POST['old'] ?? '');
    }

    public function getNewFile(): string
    {
        return trim($_POST['new'] ?? '');
    }
}
