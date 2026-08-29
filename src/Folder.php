<?php

declare(strict_types=1);

namespace Dosiero;

class Folder implements FolderInterface
{
    /** @var iterable<FolderInterface> */
    protected iterable $folders;
    protected string $name;

    protected string $path;

    public function __construct(string $name, string $path, iterable $folders)
    {
        $this->name = $name;
        $this->path = $path;
        $this->folders = $folders;
    }

    public function getFolders(): iterable
    {
        return $this->folders;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
