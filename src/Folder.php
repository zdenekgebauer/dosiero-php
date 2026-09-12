<?php

declare(strict_types=1);

namespace Dosiero;

class Folder implements FolderInterface
{
    public function __construct(
        protected string $name,
        protected string $path,
        /** @var iterable<FolderInterface> */
        protected iterable $folders,
    ) {
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
