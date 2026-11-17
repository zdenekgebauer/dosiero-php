<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\Storage;
use Dosiero\StorageException;

class FtpStorageRenameTest extends FtpStorageBase
{
    public function testExecutableNameIsRefusedForFiles(): void
    {
        $this->writeFixture('a.txt');
        $storage = $this->createStorage();
        $renamedFolder = false;

        $this->tester->expectThrowable(
            new StorageException('file "a.php" has a forbidden extension'),
            static function () use ($storage, &$renamedFolder): void {
                $storage->rename('', 'a.txt', 'a.php', $renamedFolder);
            },
        );
        $this->tester->assertFileExists($this->ftpDirectory . '/a.txt');
    }

    public function testFolderMayCarryADot(): void
    {
        mkdir($this->ftpDirectory . '/old');
        $renamedFolder = false;

        $this->createStorage()->rename('', 'old', 'release.php', $renamedFolder);

        $this->tester->assertDirectoryExists($this->ftpDirectory . '/release.php');
    }

    public function testLinkIsRefused(): void
    {
        $this->writeFixture('a.txt');
        symlink('a.txt', $this->ftpDirectory . '/link.txt');
        $storage = $this->createStorage();
        $renamedFolder = false;

        $this->tester->expectThrowable(
            new StorageException('cannot rename "link.txt" to "b.txt"'),
            static function () use ($storage, &$renamedFolder): void {
                $storage->rename('', 'link.txt', 'b.txt', $renamedFolder);
            },
        );
        $this->tester->assertTrue(is_link($this->ftpDirectory . '/link.txt'));
    }

    public function testMissingItem(): void
    {
        $storage = $this->createStorage();
        $renamedFolder = false;

        $this->tester->expectThrowable(
            new StorageException('cannot rename "missing.txt" to "b.txt"'),
            static function () use ($storage, &$renamedFolder): void {
                $storage->rename('', 'missing.txt', 'b.txt', $renamedFolder);
            },
        );
    }

    public function testNoOverwrite(): void
    {
        $this->writeFixture('a.txt', 'a');
        $this->writeFixture('b.txt', 'b');
        $storage = $this->createStorage([Storage::OPTION_OVERWRITE_FILES => false]);
        $renamedFolder = false;

        $this->tester->expectThrowable(
            new StorageException('file "b.txt" already exists'),
            static function () use ($storage, &$renamedFolder): void {
                $storage->rename('', 'a.txt', 'b.txt', $renamedFolder);
            },
        );
        $this->tester->assertSame('a', file_get_contents($this->ftpDirectory . '/a.txt'));
        $this->tester->assertSame('b', file_get_contents($this->ftpDirectory . '/b.txt'));
    }

    public function testOverwrite(): void
    {
        $this->writeFixture('a.txt', 'a');
        $this->writeFixture('b.txt', 'b');
        $renamedFolder = false;

        $this->createStorage()->rename('', 'a.txt', 'b.txt', $renamedFolder);

        $this->tester->assertSame('a', file_get_contents($this->ftpDirectory . '/b.txt'));
        $this->tester->assertFileDoesNotExist($this->ftpDirectory . '/a.txt');
    }

    public function testRenameFileInCachedSubfolder(): void
    {
        $this->writeFixture('sub/a.txt');
        $storage = $this->createStorage([Storage::OPTION_CACHE_DIRECTORY => $this->cacheDirectory]);
        $storage->getFiles('sub');
        $renamedFolder = true;

        $storage->rename('sub', 'a.txt', 'b.txt', $renamedFolder);

        $this->tester->assertFalse($renamedFolder);
        $this->tester->assertFileExists($this->ftpDirectory . '/sub/b.txt');
        $this->tester->assertSame(['b.txt'], self::names($storage->getFiles('sub')));
    }

    public function testRenameFolder(): void
    {
        $this->writeFixture('old/inside.txt');
        $renamedFolder = false;

        $this->createStorage()->rename('', 'old', 'new', $renamedFolder);

        $this->tester->assertTrue($renamedFolder);
        $this->tester->assertFileExists($this->ftpDirectory . '/new/inside.txt');
        $this->tester->assertDirectoryDoesNotExist($this->ftpDirectory . '/old');
    }

    public function testServerRefusal(): void
    {
        $this->writeFixture('a.txt');
        mkdir($this->ftpDirectory . '/b.txt');
        $this->writeFixture('b.txt/inside.txt');
        $storage = $this->createStorage();
        $renamedFolder = false;

        $this->tester->expectThrowable(
            new StorageException('cannot rename "b.txt" to "missing/c.txt"'),
            static function () use ($storage, &$renamedFolder): void {
                $storage->rename('', 'b.txt', 'missing/c.txt', $renamedFolder);
            },
        );
    }
}
