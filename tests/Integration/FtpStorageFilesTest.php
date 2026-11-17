<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\File;
use Dosiero\Ftp\FtpStorage;
use Dosiero\Storage;
use Dosiero\StorageException;

class FtpStorageFilesTest extends FtpStorageBase
{
    public function testBaseDirBelowServerRoot(): void
    {
        $this->writeFixture('base/inside.txt');
        $this->writeFixture('outside.txt');

        $files = $this->createStorage([FtpStorage::OPTION_BASE_DIR => '/base/'])->getFiles('');

        $this->tester->assertSame(['inside.txt'], self::names($files));
    }

    public function testDirectoryUrlIsEncodedPerSegment(): void
    {
        $this->writeFixture('my photos/a b.txt');

        $files = $this->createStorage([Storage::OPTION_BASE_URL => 'http://example.org/files/'])->getFiles('my photos');

        $this->tester->assertSame('http://example.org/files/my%20photos/a%20b.txt', $files['a b.txt']->getUrl());
    }

    public function testListingOverTls(): void
    {
        $this->writeFixture('a.txt');

        $files = $this->createStorage([FtpStorage::OPTION_SSL => true])->getFiles('');

        $this->tester->assertSame(['a.txt'], self::names($files));
    }

    public function testMissingDirectory(): void
    {
        $storage = $this->createStorage();

        $this->tester->expectThrowable(
            new StorageException('not found path "missing"'),
            static fn() => $storage->getFiles('missing'),
        );
    }

    public function testMixedDirectory(): void
    {
        $this->writeFixture('b.txt', 'hello');
        $this->writeFixture('.htaccess');
        mkdir($this->ftpDirectory . '/a');
        symlink('b.txt', $this->ftpDirectory . '/link.txt');
        touch($this->ftpDirectory . '/b.txt', strtotime('2026-11-17 10:20:30 UTC'));

        $files = $this->createStorage()->getFiles('');

        $this->tester->assertSame(['a', 'b.txt'], self::names($files));
        $this->tester->assertSame(File::TYPE_DIR, $files['a']->getType());
        $this->tester->assertSame(File::TYPE_FILE, $files['b.txt']->getType());
        $this->tester->assertSame(5, $files['b.txt']->getSize());
        $this->tester->assertSame('2026-11-17T10:20:30+00:00', $files['b.txt']->getModified());
        $this->tester->assertSame($this->testUrl . 'b.txt', $files['b.txt']->getUrl());
    }

    public function testModificationTimeKeepsThePreviousYear(): void
    {
        $this->writeFixture('old.txt');
        touch($this->ftpDirectory . '/old.txt', strtotime('2024-03-01 10:00:00 UTC'));

        $files = $this->createStorage()->getFiles('');

        $this->tester->assertSame('2024-03-01T10:00:00+00:00', $files['old.txt']->getModified());
    }

    public function testSubfolder(): void
    {
        $this->writeFixture('photos/2026/a.txt');
        $this->writeFixture('photos/b.txt');

        $files = $this->createStorage()->getFiles('/photos/2026/');

        $this->tester->assertSame(['a.txt'], self::names($files));
        $this->tester->assertSame($this->testUrl . 'photos/2026/a.txt', $files['a.txt']->getUrl());
    }

    public function testWrongPasswordIsAStorageErrorWithoutThePassword(): void
    {
        $storage = $this->createStorage([FtpStorage::OPTION_PASSWORD => 'wrong-secret']);

        try {
            $storage->getFiles('');
            $this->fail('login should have failed');
        } catch (StorageException $exception) {
            $this->tester->assertStringNotContainsString('wrong-secret', $exception->getMessage());
        }
    }
}
