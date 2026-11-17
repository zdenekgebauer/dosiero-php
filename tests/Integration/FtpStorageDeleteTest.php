<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\Storage;
use Dosiero\StorageException;

class FtpStorageDeleteTest extends FtpStorageBase
{
    public function testDeleteFilesInCachedSubfolder(): void
    {
        $this->writeFixture('sub/a.txt');
        $this->writeFixture('sub/b.txt');
        $this->writeFixture('sub/c.txt');
        $storage = $this->createStorage([Storage::OPTION_CACHE_DIRECTORY => $this->cacheDirectory]);
        $storage->getFiles('sub');
        $deletedFolder = false;

        $storage->delete('sub', ['a.txt', 'b.txt'], $deletedFolder);

        $this->tester->assertFalse($deletedFolder);
        $this->tester->assertSame(['c.txt'], self::names($storage->getFiles('sub')));
    }

    public function testDeleteFolderWithContent(): void
    {
        $this->writeFixture('a/b/c.txt');
        $this->writeFixture('a/.htaccess');
        symlink('../../outside.txt', $this->ftpDirectory . '/a/link');
        $this->writeFixture('outside.txt');
        $deletedFolder = false;

        $this->createStorage()->delete('', ['a'], $deletedFolder);

        $this->tester->assertTrue($deletedFolder);
        $this->tester->assertDirectoryDoesNotExist($this->ftpDirectory . '/a');
        $this->tester->assertFileExists($this->ftpDirectory . '/outside.txt');
    }

    public function testEmptySelectionChangesNothing(): void
    {
        $this->writeFixture('a.txt');
        $deletedFolder = false;

        $this->createStorage()->delete('', [], $deletedFolder);

        $this->tester->assertFileExists($this->ftpDirectory . '/a.txt');
    }

    public function testLinkIsRefused(): void
    {
        $this->writeFixture('a.txt');
        symlink('a.txt', $this->ftpDirectory . '/link.txt');
        $storage = $this->createStorage();
        $deletedFolder = false;

        $this->tester->expectThrowable(
            new StorageException('cannot delete the link "link.txt"'),
            static function () use ($storage, &$deletedFolder): void {
                $storage->delete('', ['link.txt'], $deletedFolder);
            },
        );
        $this->tester->assertTrue(is_link($this->ftpDirectory . '/link.txt'));
    }

    public function testMissingFile(): void
    {
        $storage = $this->createStorage();
        $deletedFolder = false;

        $this->tester->expectThrowable(
            new StorageException('cannot delete "missing.txt"'),
            static function () use ($storage, &$deletedFolder): void {
                $storage->delete('', ['missing.txt'], $deletedFolder);
            },
        );
    }

    public function testUndeletableContentIsReported(): void
    {
        $this->writeFixture('a/locked/file.txt');
        chmod($this->ftpDirectory . '/a/locked', 0o555);
        $storage = $this->createStorage();
        $deletedFolder = false;

        try {
            $this->tester->expectThrowable(
                new StorageException('cannot delete "file.txt"'),
                static function () use ($storage, &$deletedFolder): void {
                    $storage->delete('', ['a'], $deletedFolder);
                },
            );
        } finally {
            chmod($this->ftpDirectory . '/a/locked', 0o755);
        }
    }

    public function testUndeletableFileIsReported(): void
    {
        $this->writeFixture('locked/file.txt');
        chmod($this->ftpDirectory . '/locked', 0o555);
        $storage = $this->createStorage();
        $deletedFolder = false;

        try {
            $this->tester->expectThrowable(
                new StorageException('cannot delete "file.txt"'),
                static function () use ($storage, &$deletedFolder): void {
                    $storage->delete('locked', ['file.txt'], $deletedFolder);
                },
            );
        } finally {
            chmod($this->ftpDirectory . '/locked', 0o755);
        }
    }

    public function testUnremovableFolderIsReported(): void
    {
        mkdir($this->ftpDirectory . '/a/empty', 0o777, true);
        chmod($this->ftpDirectory . '/a', 0o555);
        $storage = $this->createStorage();
        $deletedFolder = false;

        try {
            $this->tester->expectThrowable(
                new StorageException('cannot delete "empty"'),
                static function () use ($storage, &$deletedFolder): void {
                    $storage->delete('a', ['empty'], $deletedFolder);
                },
            );
        } finally {
            chmod($this->ftpDirectory . '/a', 0o755);
        }
    }
}
