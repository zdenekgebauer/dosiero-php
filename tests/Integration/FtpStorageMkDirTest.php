<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\Ftp\FtpConnection;
use Dosiero\Ftp\FtpStorage;
use Dosiero\Storage;
use Dosiero\StorageException;

class FtpStorageMkDirTest extends FtpStorageBase
{
    public function testExistingFolderIsKept(): void
    {
        $this->writeFixture('a/docs/keep.txt');

        $this->createStorage()->mkDir('a', 'docs');

        $this->tester->assertFileExists($this->ftpDirectory . '/a/docs/keep.txt');
    }

    public function testFileOfTheSameNameIsAnError(): void
    {
        $this->writeFixture('docs');
        $storage = $this->createStorage();

        $this->tester->expectThrowable(
            new StorageException('cannot create folder "docs"'),
            static fn() => $storage->mkDir('', 'docs'),
        );
    }

    public function testFileOfTheSameNameIsAnErrorWithoutMlsd(): void
    {
        $this->writeFixture('docs');
        $storage = $this->createStorage([], new class(self::STORAGE_NAME) extends FtpStorage {
            protected function createConnection(): FtpConnection
            {
                $s = parent::createConnection();
                $password = (string)getenv('DOSIERO_FTP_PASSWORD') ?: 'dosiero';
                return new class($s->host, $s->port, $s->login, $password, false, true, 10) extends FtpConnection {
                    protected function supportsMlsd(): bool
                    {
                        return false;
                    }
                };
            }
        });

        $this->tester->expectThrowable(
            new StorageException('cannot create folder "docs"'),
            static fn() => $storage->mkDir('', 'docs'),
        );
    }

    public function testNewFolder(): void
    {
        mkdir($this->ftpDirectory . '/a');
        $storage = $this->createStorage([
            Storage::OPTION_CACHE_DIRECTORY => $this->cacheDirectory,
            Storage::OPTION_MODE_DIRECTORY => 0o700,
        ]);
        $storage->getFiles('a');

        $storage->mkDir('a', 'docs');

        $this->tester->assertDirectoryExists($this->ftpDirectory . '/a/docs');
        $this->tester->assertSame(0o700, fileperms($this->ftpDirectory . '/a/docs') & 0o777);
        $this->tester->assertSame(['docs'], self::names($storage->getFiles('a')));
    }

    public function testServerRefusal(): void
    {
        $storage = new class(self::STORAGE_NAME) extends FtpStorage {
            protected function createConnection(): FtpConnection
            {
                return new class('127.0.0.1', 2121, 'dosiero', 'dosiero', false, true, 10) extends FtpConnection {
                    public function list(string $directory): ?array
                    {
                        return null;
                    }

                    public function mkdir(string $path): bool
                    {
                        return false;
                    }
                };
            }
        };

        $this->tester->expectThrowable(
            new StorageException('cannot create folder "docs"'),
            static fn() => $storage->mkDir('', 'docs'),
        );
    }
}
