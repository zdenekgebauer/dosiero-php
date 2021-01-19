<?php

declare(strict_types=1);

namespace Dosiero;

interface FolderInterface
{
    /**
     * @param string $name
     * @param string $path
     * @param iterable<FolderInterface> $folders
     */
    public function __construct(string $name, string $path, iterable $folders);

    public function getName(): string;

    public function getPath(): string;

    /**
     * @return iterable<FolderInterface>
     */
    public function getFolders(): iterable;
}
