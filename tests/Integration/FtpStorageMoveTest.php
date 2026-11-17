<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\Storage;
use Dosiero\StorageException;

class FtpStorageMoveTest extends FtpStorageBase
{
    public function testIntoItself(): void
    {
        mkdir($this->ftpDirectory . '/a/sub', 0o777, true);
        $storage = $this->createStorage();
        $movedFolder = false;

        $this->tester->expectThrowable(
            new StorageException('cannot move "a" into itself'),
            static function () use ($storage, &$movedFolder): void {
                $storage->move('', ['a'], 'a/sub', $movedFolder);
            },
        );
        $this->tester->assertDirectoryExists($this->ftpDirectory . '/a/sub');
    }

    public function testMissingFile(): void
    {
        mkdir($this->ftpDirectory . '/b');
        $storage = $this->createStorage();
        $movedFolder = false;

        $this->tester->expectThrowable(
            new StorageException('cannot move "missing.txt"'),
            static function () use ($storage, &$movedFolder): void {
                $storage->move('', ['missing.txt'], 'b', $movedFolder);
            },
        );
    }

    public function testMoveFileBetweenSubfolders(): void
    {
        $this->writeFixture('a/x.txt', 'x');
        mkdir($this->ftpDirectory . '/b');
        $movedFolder = false;

        $this->createStorage()->move('a', ['x.txt'], 'b', $movedFolder);

        $this->tester->assertFalse($movedFolder);
        $this->tester->assertSame('x', file_get_contents($this->ftpDirectory . '/b/x.txt'));
        $this->tester->assertFileDoesNotExist($this->ftpDirectory . '/a/x.txt');
    }

    public function testMoveFolder(): void
    {
        $this->writeFixture('a/deep/x.txt', 'x');
        mkdir($this->ftpDirectory . '/b');
        $movedFolder = false;

        $this->createStorage()->move('', ['a'], 'b', $movedFolder);

        $this->tester->assertTrue($movedFolder);
        $this->tester->assertSame('x', file_get_contents($this->ftpDirectory . '/b/a/deep/x.txt'));
        $this->tester->assertDirectoryDoesNotExist($this->ftpDirectory . '/a');
    }

    public function testMoveUpdatesBothCachedDirectories(): void
    {
        $this->writeFixture('a/x.txt');
        $this->writeFixture('a/y.txt');
        mkdir($this->ftpDirectory . '/b');
        $storage = $this->createStorage([Storage::OPTION_CACHE_DIRECTORY => $this->cacheDirectory]);
        $storage->getFiles('a');
        $storage->getFiles('b');
        $movedFolder = false;

        $storage->move('a', ['x.txt'], 'b', $movedFolder);

        $this->tester->assertSame(['y.txt'], self::names($storage->getFiles('a')));
        $this->tester->assertSame(['x.txt'], self::names($storage->getFiles('b')));
    }

    public function testNoOverwriteRefusesTheWholeBatch(): void
    {
        $this->writeFixture('x.txt', 'new');
        $this->writeFixture('y.txt');
        $this->writeFixture('b/x.txt', 'old');
        $storage = $this->createStorage([Storage::OPTION_OVERWRITE_FILES => false]);
        $movedFolder = false;

        $this->tester->expectThrowable(
            new StorageException('files were not overwritten: x.txt'),
            static function () use ($storage, &$movedFolder): void {
                $storage->move('', ['x.txt', 'y.txt'], 'b', $movedFolder);
            },
        );
        $this->tester->assertSame('old', file_get_contents($this->ftpDirectory . '/b/x.txt'));
        $this->tester->assertFileExists($this->ftpDirectory . '/y.txt');
    }

    public function testOntoItself(): void
    {
        $this->writeFixture('a/x.txt', 'x');
        $storage = $this->createStorage();
        $movedFolder = false;

        $this->tester->expectThrowable(
            new StorageException('cannot move "x.txt" onto itself'),
            static function () use ($storage, &$movedFolder): void {
                $storage->move('a', ['x.txt'], 'a', $movedFolder);
            },
        );
        $this->tester->assertSame('x', file_get_contents($this->ftpDirectory . '/a/x.txt'));
    }

    public function testServerRefusal(): void
    {
        $this->writeFixture('x.txt');
        mkdir($this->ftpDirectory . '/b');
        chmod($this->ftpDirectory . '/b', 0o555);
        $storage = $this->createStorage();
        $movedFolder = false;

        try {
            $this->tester->expectThrowable(
                new StorageException('cannot move "x.txt"'),
                static function () use ($storage, &$movedFolder): void {
                    $storage->move('', ['x.txt'], 'b', $movedFolder);
                },
            );
        } finally {
            chmod($this->ftpDirectory . '/b', 0o755);
        }
    }
}
