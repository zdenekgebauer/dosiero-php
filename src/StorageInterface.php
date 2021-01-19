<?php

declare(strict_types=1);

namespace Dosiero;

interface StorageInterface
{

    public function __construct(string $name);

    public function getName(): string;

    public function isReadOnly(): bool;

    public function setOption(string $name, int | string $value): void;

    /**
     * @return iterable<FolderInterface>
     */
    public function getFolders(): iterable;

    /**
     * @param string $path
     * @param bool $ignoreCache
     * @return iterable<FileInterface>
     */
    public function getFiles(string $path, bool $ignoreCache = false): iterable;

    public function mkDir(string $path, string $newFolder): void;

    public function upload(string $path, array $files): void;

    public function delete(string $path, array $files, bool &$deletedFolder): void;

    public function rename(string $path, string $oldName, string $newName, bool &$renamedFolder): void;

    public function copy(string $path, array $files, string $targetPath, bool &$copiedFolder): void;

    public function move(string $path, array $files, string $targetPath, bool &$movedFolder): void;
}
