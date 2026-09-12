<?php

declare(strict_types=1);

namespace Dosiero;

/**
 * @phpstan-type UploadedFile array{name: string, type: string, tmp_name: string, error: int, size: int}
 */
interface StorageInterface
{
    public function __construct(string $name);

    /** @param array<string> $files */
    public function copy(string $path, array $files, string $targetPath, bool &$copiedFolder): void;

    /** @param array<string> $files */
    public function delete(string $path, array $files, bool &$deletedFolder): void;

    /** @return array<string> */
    public function getAllowedExtensions(): array;

    /** @return iterable<FileInterface> */
    public function getFiles(string $path, bool $ignoreCache = false): iterable;

    /** @return iterable<FolderInterface> */
    public function getFolders(): iterable;

    /** @return int bytes, 0 when nothing limits the size */
    public function getMaxFileSize(): int;

    public function getName(): string;

    public function isReadOnly(): bool;

    public function mkDir(string $path, string $newFolder): void;

    /** @param array<string> $files */
    public function move(string $path, array $files, string $targetPath, bool &$movedFolder): void;

    public function rename(string $path, string $oldName, string $newName, bool &$renamedFolder): void;

    public function setOption(string $name, int|string $value): void;

    /** @param array<string, UploadedFile> $files */
    public function upload(string $path, array $files): void;
}
