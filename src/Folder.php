<?php

declare(strict_types=1);

namespace Dosiero;

class Folder implements FolderInterface
{
    protected string $name;

    protected string $path;

    /**
     * @var iterable<FolderInterface>
     */
    protected iterable $folders;

    public function __construct(string $name, string $path, iterable $folders)
    {
        $this->name = $name;
        $this->path = $path;
        $this->folders = $folders;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getFolders(): iterable
    {
        return $this->folders;
    }
}
