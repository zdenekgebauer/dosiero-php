<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\Storage;
use Dosiero\StorageException;

class FtpStorageCopyTest extends FtpStorageBase
{
    public function testCopyFilesToRootTargetOfCachedListing(): void
    {
        $this->writeFixture('a/x.txt', 'x');
        $storage = $this->createStorage([Storage::OPTION_CACHE_DIRECTORY => $this->cacheDirectory]);
        $storage->getFiles('');
        $copiedFolder = false;

        $storage->copy('a', ['x.txt'], '', $copiedFolder);

        $this->tester->assertFalse($copiedFolder);
        $this->tester->assertSame('x', file_get_contents($this->ftpDirectory . '/x.txt'));
        $this->tester->assertFileExists($this->ftpDirectory . '/a/x.txt');
        $this->tester->assertSame(['a', 'x.txt'], self::names($storage->getFiles('')));
    }

    public function testCopyFolderBetweenSubfolders(): void
    {
        $this->writeFixture('src/a/x.txt', 'x');
        $this->writeFixture('src/a/deep/y.txt', 'y');
        symlink('x.txt', $this->ftpDirectory . '/src/a/link');
        mkdir($this->ftpDirectory . '/dst/b', 0o777, true);
        $copiedFolder = false;

        $this->createStorage([Storage::OPTION_MODE_DIRECTORY => 0o700])->copy('src', ['a'], 'dst/b', $copiedFolder);

        $this->tester->assertTrue($copiedFolder);
        $this->tester->assertSame('x', file_get_contents($this->ftpDirectory . '/dst/b/a/x.txt'));
        $this->tester->assertSame('y', file_get_contents($this->ftpDirectory . '/dst/b/a/deep/y.txt'));
        $this->tester->assertSame(0o700, fileperms($this->ftpDirectory . '/dst/b/a') & 0o777);
        $this->tester->assertFalse(file_exists($this->ftpDirectory . '/dst/b/a/link'));
        $this->tester->assertFileExists($this->ftpDirectory . '/src/a/x.txt');
    }

    public function testCopyFolderOntoExistingFolderMerges(): void
    {
        $this->writeFixture('a/x.txt', 'new');
        $this->writeFixture('b/a/x.txt', 'old');
        $this->writeFixture('b/a/kept.txt');
        $copiedFolder = false;

        $this->createStorage()->copy('', ['a'], 'b', $copiedFolder);

        $this->tester->assertSame('new', file_get_contents($this->ftpDirectory . '/b/a/x.txt'));
        $this->tester->assertFileExists($this->ftpDirectory . '/b/a/kept.txt');
    }

    public function testIntoItself(): void
    {
        $this->writeFixture('a/sub/x.txt');
        $storage = $this->createStorage();
        $copiedFolder = false;

        $this->tester->expectThrowable(
            new StorageException('cannot copy "a" into itself'),
            static function () use ($storage, &$copiedFolder): void {
                $storage->copy('', ['a'], 'a/sub', $copiedFolder);
            },
        );
        $this->tester->assertSame(['.', '..', 'x.txt'], scandir($this->ftpDirectory . '/a/sub'));
    }

    public function testIntoItselfExactly(): void
    {
        mkdir($this->ftpDirectory . '/a');
        $storage = $this->createStorage();
        $copiedFolder = false;

        $this->tester->expectThrowable(
            new StorageException('cannot copy "a" into itself'),
            static function () use ($storage, &$copiedFolder): void {
                $storage->copy('', ['a'], 'a', $copiedFolder);
            },
        );
    }

    public function testLinkIsRefused(): void
    {
        $this->writeFixture('a.txt');
        symlink('a.txt', $this->ftpDirectory . '/link.txt');
        mkdir($this->ftpDirectory . '/b');
        $storage = $this->createStorage();
        $copiedFolder = false;

        $this->tester->expectThrowable(
            new StorageException('cannot copy the link "link.txt"'),
            static function () use ($storage, &$copiedFolder): void {
                $storage->copy('', ['link.txt'], 'b', $copiedFolder);
            },
        );
    }

    public function testMissingFile(): void
    {
        mkdir($this->ftpDirectory . '/b');
        $storage = $this->createStorage();
        $copiedFolder = false;

        $this->tester->expectThrowable(
            new StorageException('cannot copy "missing.txt"'),
            static function () use ($storage, &$copiedFolder): void {
                $storage->copy('', ['missing.txt'], 'b', $copiedFolder);
            },
        );
    }

    public function testMissingTarget(): void
    {
        $this->writeFixture('a.txt');
        $storage = $this->createStorage();
        $copiedFolder = false;

        $this->tester->expectThrowable(
            new StorageException('not found path "missing"'),
            static function () use ($storage, &$copiedFolder): void {
                $storage->copy('', ['a.txt'], 'missing', $copiedFolder);
            },
        );
    }

    public function testNoOverwriteRefusesTheWholeBatch(): void
    {
        $this->writeFixture('x.txt', 'new');
        $this->writeFixture('y.txt', 'y');
        $this->writeFixture('b/x.txt', 'old');
        $storage = $this->createStorage([Storage::OPTION_OVERWRITE_FILES => false]);
        $copiedFolder = false;

        $this->tester->expectThrowable(
            new StorageException('files were not overwritten: x.txt'),
            static function () use ($storage, &$copiedFolder): void {
                $storage->copy('', ['x.txt', 'y.txt'], 'b', $copiedFolder);
            },
        );
        $this->tester->assertSame('old', file_get_contents($this->ftpDirectory . '/b/x.txt'));
        $this->tester->assertFileDoesNotExist($this->ftpDirectory . '/b/y.txt');
    }

    public function testOntoItself(): void
    {
        $this->writeFixture('a/x.txt', 'x');
        $storage = $this->createStorage();
        $copiedFolder = false;

        $this->tester->expectThrowable(
            new StorageException('cannot copy "x.txt" onto itself'),
            static function () use ($storage, &$copiedFolder): void {
                $storage->copy('a', ['x.txt'], 'a', $copiedFolder);
            },
        );
        $this->tester->assertSame('x', file_get_contents($this->ftpDirectory . '/a/x.txt'));
    }

    public function testSiblingWithCommonPrefixIsNotInsideTheSource(): void
    {
        $this->writeFixture('a/x.txt', 'x');
        mkdir($this->ftpDirectory . '/ab');
        $copiedFolder = false;

        $this->createStorage()->copy('', ['a'], 'ab', $copiedFolder);

        $this->tester->assertFileExists($this->ftpDirectory . '/ab/a/x.txt');
    }

    public function testUnreadableFileInFolderIsReported(): void
    {
        $this->writeFixture('a/secret.txt');
        chmod($this->ftpDirectory . '/a/secret.txt', 0o000);
        mkdir($this->ftpDirectory . '/b');
        $storage = $this->createStorage();
        $copiedFolder = false;

        try {
            $this->tester->expectThrowable(
                new StorageException('cannot copy "secret.txt"'),
                static function () use ($storage, &$copiedFolder): void {
                    $storage->copy('', ['a'], 'b', $copiedFolder);
                },
            );
        } finally {
            chmod($this->ftpDirectory . '/a/secret.txt', 0o644);
        }
    }

    public function testUnwritableTargetFolderIsReported(): void
    {
        $this->writeFixture('a/x.txt');
        mkdir($this->ftpDirectory . '/b');
        chmod($this->ftpDirectory . '/b', 0o555);
        $storage = $this->createStorage();
        $copiedFolder = false;

        try {
            $this->tester->expectThrowable(
                new StorageException('cannot copy "a"'),
                static function () use ($storage, &$copiedFolder): void {
                    $storage->copy('', ['a'], 'b', $copiedFolder);
                },
            );
        } finally {
            chmod($this->ftpDirectory . '/b', 0o755);
        }
    }
}
