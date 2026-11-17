<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\File;
use Dosiero\Ftp\FtpConnection;
use Dosiero\Ftp\FtpEntry;
use Dosiero\StorageException;

class FtpConnectionTest extends FtpStorageBase
{
    public function testChmod(): void
    {
        $this->writeFixture('a.txt');
        $connection = $this->createConnection();

        $this->tester->assertTrue($connection->chmod('/a.txt', 0o600));
        clearstatcache();
        $this->tester->assertSame(0o600, fileperms($this->ftpDirectory . '/a.txt') & 0o777);
        $this->tester->assertFalse($connection->chmod('/missing.txt', 0o600));
    }

    public function testCloseThenReopen(): void
    {
        $this->writeFixture('a.txt');
        $connection = $this->createConnection();
        $connection->list('/');

        $connection->close();
        $connection->close();

        $this->tester->assertSame(['a.txt'], array_keys($connection->list('/') ?? []));
    }

    public function testCopyFile(): void
    {
        $this->writeFixture('a.txt', 'abc');
        mkdir($this->ftpDirectory . '/sub');
        $connection = $this->createConnection();

        $this->tester->assertTrue($connection->copyFile('/a.txt', '/sub/a.txt'));
        $this->tester->assertSame('abc', file_get_contents($this->ftpDirectory . '/sub/a.txt'));
        $this->tester->assertFalse($connection->copyFile('/missing.txt', '/sub/b.txt'));
        $this->tester->assertFalse($connection->copyFile('/a.txt', '/missing/a.txt'));
    }

    public function testDeleteAndRmdir(): void
    {
        $this->writeFixture('sub/a.txt');
        $connection = $this->createConnection();

        $this->tester->assertFalse($connection->rmdir('/sub'));
        $this->tester->assertTrue($connection->delete('/sub/a.txt'));
        $this->tester->assertTrue($connection->rmdir('/sub'));
        $this->tester->assertDirectoryDoesNotExist($this->ftpDirectory . '/sub');
        $this->tester->assertFalse($connection->delete('/missing.txt'));
    }

    public function testListFallsBackToListWithoutMlsd(): void
    {
        $this->createFixtureTree();
        $connection = new class(...self::settings()) extends FtpConnection {
            protected function supportsMlsd(): bool
            {
                return false;
            }
        };

        $this->assertFixtureTree($connection->list('/'));
        $this->tester->assertNull($connection->list('/missing'));
        $this->tester->assertNull($connection->list('/a.txt'));
        $this->tester->assertSame(['b c.txt'], array_keys($connection->list('/my dir') ?? []));
    }

    public function testListOverMlsd(): void
    {
        $this->createFixtureTree();
        $connection = new class(...self::settings()) extends FtpConnection {
            public int $featCalls = 0;

            public function list(string $directory): ?array
            {
                $this->featCalls += $this->supportsMlsd() ? 1 : 100;
                return parent::list($directory);
            }
        };

        $this->assertFixtureTree($connection->list('/'));
        $this->tester->assertNull($connection->list('/missing'));
        $this->tester->assertNull($connection->list('/a.txt'));
        $this->tester->assertSame(3, $connection->featCalls);
    }

    public function testListOverTls(): void
    {
        $this->createFixtureTree();

        $this->assertFixtureTree($this->createConnection(true)->list('/'));
    }

    public function testLoginFailureDoesNotRevealThePassword(): void
    {
        $connection = $this->createConnection(false, 'wrong-secret');

        try {
            $connection->list('/');
            $this->fail('login should have failed');
        } catch (StorageException $exception) {
            $expected = 'ftp login as "' . self::env('LOGIN', 'dosiero') . '" failed';
            $this->tester->assertSame($expected, $exception->getMessage());
            $this->tester->assertStringNotContainsString('wrong-secret', $exception->getMessage());
        }
    }

    public function testMissingExtension(): void
    {
        $connection = new class('127.0.0.1', 2121, 'dosiero', 'dosiero', false, true, 10) extends FtpConnection {
            protected function isExtensionLoaded(): bool
            {
                return false;
            }
        };

        $this->tester->expectThrowable(
            new StorageException('the ftp extension is not loaded'),
            static fn() => $connection->list('/'),
        );
    }

    public function testMissingHost(): void
    {
        $connection = new FtpConnection('', 2121, 'dosiero', 'dosiero', false, true, 10);

        $this->tester->expectThrowable(
            new StorageException('invalid configuration: missing ftp host'),
            static fn() => $connection->list('/'),
        );
    }

    public function testMkdirPutDownloadRename(): void
    {
        $local = $this->cacheDirectory . '/upload.txt';
        file_put_contents($local, 'uploaded');
        $connection = $this->createConnection();

        $this->tester->assertTrue($connection->mkdir('/sub'));
        $this->tester->assertFalse($connection->mkdir('/sub'));
        $this->tester->assertTrue($connection->put('/sub/a.txt', $local));
        $this->tester->assertFalse($connection->put('/missing/a.txt', $local));
        $this->tester->assertTrue($connection->download('/sub/a.txt', $this->cacheDirectory . '/downloaded.txt'));
        $this->tester->assertSame('uploaded', file_get_contents($this->cacheDirectory . '/downloaded.txt'));
        $this->tester->assertFalse($connection->download('/sub/missing.txt', $this->cacheDirectory . '/missing.txt'));
        $this->tester->assertTrue($connection->rename('/sub/a.txt', '/sub/b.txt'));
        $this->tester->assertFalse($connection->rename('/sub/a.txt', '/sub/c.txt'));
        $this->tester->assertFileExists($this->ftpDirectory . '/sub/b.txt');
    }

    public function testUnreachableServer(): void
    {
        $connection = new FtpConnection('127.0.0.1', 1, 'dosiero', 'dosiero', false, true, 2);

        $this->tester->expectThrowable(
            new StorageException('cannot connect to ftp server "127.0.0.1:1"'),
            static fn() => $connection->list('/'),
        );
    }

    /** @param array<string, FtpEntry>|null $entries */
    private function assertFixtureTree(?array $entries): void
    {
        $this->tester->assertNotNull($entries);
        $this->tester->assertSame(['.hidden', 'a.txt', 'link', 'my dir', 'old.txt'], array_keys($entries));
        $this->tester->assertSame(File::TYPE_FILE, $entries['a.txt']->type);
        $this->tester->assertSame(3, $entries['a.txt']->size);
        $this->tester->assertSame(File::TYPE_LINK, $entries['link']->type);
        $this->tester->assertSame(File::TYPE_DIR, $entries['my dir']->type);
        $this->tester->assertSame('2024', $entries['old.txt']->modified->format('Y'));
    }

    private function createFixtureTree(): void
    {
        $this->writeFixture('a.txt', 'abc');
        $this->writeFixture('.hidden');
        $this->writeFixture('my dir/b c.txt');
        $this->writeFixture('old.txt');
        touch($this->ftpDirectory . '/old.txt', strtotime('2024-03-01 10:00:00 UTC'));
        symlink('my dir', $this->ftpDirectory . '/link');
    }

    /** @return array{string, int, string, string, bool, bool, int} */
    private static function settings(): array
    {
        return [
            self::env('HOST', '127.0.0.1'),
            (int)self::env('PORT', '2121'),
            self::env('LOGIN', 'dosiero'),
            self::env('PASSWORD', 'dosiero'),
            false,
            true,
            10,
        ];
    }
}
