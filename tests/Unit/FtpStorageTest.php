<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Dosiero\Ftp\FtpConnection;
use Dosiero\Ftp\FtpStorage;
use Dosiero\Storage;
use Dosiero\StorageException;
use Tests\Support\UnitTester;

class FtpStorageTest extends Unit
{
    protected UnitTester $tester;

    /** @return array<string, array{string}> */
    public function provideEscapingPaths(): array
    {
        return [
            'parent' => ['..'],
            'parent inside' => ['a/../../etc'],
            'current' => ['a/./b'],
            'backslash' => ['a\\..\\b'],
            'nul byte' => ["a\0b"],
        ];
    }

    /** @return array<string, array{string, string, string}> */
    public function provideRemotePaths(): array
    {
        return [
            'root of server root' => ['/', '', '/'],
            'root of base dir' => ['/srv/data/', '', '/srv/data'],
            'slash only' => ['srv', '/', '/srv'],
            'subfolder' => ['srv', 'a/b', '/srv/a/b'],
            'surrounding and doubled slashes' => ['/srv/', '//a//b/', '/srv/a/b'],
            'subfolder of server root' => ['/', 'a', '/a'],
            'name with spaces' => ['/', 'my photos', '/my photos'],
        ];
    }

    public function testBaseDirRefusesTraversal(): void
    {
        $this->tester->expectThrowable(
            new \InvalidArgumentException('invalid base directory "/srv/../etc"'),
            static fn() => (new FtpStorage('ftp'))->setOption(FtpStorage::OPTION_BASE_DIR, '/srv/../etc'),
        );
    }

    public function testDefaults(): void
    {
        $storage = $this->createStorage();
        $storage->setOption(FtpStorage::OPTION_HOST, 'ftp.example.org');

        $connection = $storage->exposeConnection();

        $this->tester->assertSame('ftp.example.org', $connection->host);
        $this->tester->assertSame(21, $connection->port);
        $this->tester->assertSame('anonymous', $connection->login);
        $this->tester->assertFalse($connection->ssl);
        $this->tester->assertTrue($connection->passive);
        $this->tester->assertSame(90, $connection->timeout);
    }

    /** @dataProvider provideEscapingPaths */
    public function testEscapingPathIsRefusedBeforeContactingTheServer(string $path): void
    {
        $storage = $this->createStorage();

        $this->tester->expectThrowable(
            new StorageException('not found path "' . $path . '"'),
            static fn() => $storage->getFiles($path),
        );
        $this->tester->assertSame(0, $storage->connections);
    }

    public function testEscapingTargetPathIsRefused(): void
    {
        $storage = $this->createStorage();
        $copied = false;

        $this->tester->expectThrowable(
            new StorageException('not found path "a/../../outside"'),
            static function () use ($storage, &$copied): void {
                $storage->copy('', ['x.txt'], 'a/../../outside', $copied);
            },
        );
        $this->tester->assertSame(0, $storage->connections);
    }

    public function testNoConnectionUntilNeeded(): void
    {
        $storage = $this->createStorage();
        $storage->setOption(FtpStorage::OPTION_HOST, 'ftp.example.org');
        $storage->setOption(Storage::OPTION_READ_ONLY, true);

        $this->tester->assertSame(0, $storage->connections);
        $storage->getFiles('');
        $storage->getFiles('a');
        $this->tester->assertSame(1, $storage->connections);
    }

    public function testOptionChangeDropsTheConnection(): void
    {
        $storage = $this->createStorage();
        $storage->getFiles('');
        $storage->setOption(FtpStorage::OPTION_HOST, 'other.example.org');
        $storage->getFiles('');

        $this->tester->assertSame(2, $storage->connections);
    }

    public function testOptions(): void
    {
        $storage = $this->createStorage();
        $storage->setOption(FtpStorage::OPTION_HOST, 'ftp.example.org');
        $storage->setOption(FtpStorage::OPTION_PORT, '2121');
        $storage->setOption(FtpStorage::OPTION_LOGIN, 'user');
        $storage->setOption(FtpStorage::OPTION_PASSWORD, 'secret');
        $storage->setOption(FtpStorage::OPTION_SSL, 1);
        $storage->setOption(FtpStorage::OPTION_PASSIVE, 0);
        $storage->setOption(FtpStorage::OPTION_TIMEOUT, 15);

        $connection = $storage->exposeConnection();

        $this->tester->assertSame('ftp.example.org', $connection->host);
        $this->tester->assertSame(2121, $connection->port);
        $this->tester->assertSame('user', $connection->login);
        $this->tester->assertTrue($connection->ssl);
        $this->tester->assertFalse($connection->passive);
        $this->tester->assertSame(15, $connection->timeout);
    }

    /** @dataProvider provideRemotePaths */
    public function testRemotePath(string $baseDir, string $path, string $expected): void
    {
        $storage = $this->createStorage();
        $storage->setOption(FtpStorage::OPTION_BASE_DIR, $baseDir);

        $storage->getFiles($path);

        $this->tester->assertSame([$expected], $storage->listed);
    }

    public function testSharedOptionsStillApply(): void
    {
        $storage = $this->createStorage();
        $storage->setOption(Storage::OPTION_READ_ONLY, true);

        $this->tester->assertTrue($storage->isReadOnly());
    }

    public function testTimeoutIsAtLeastOneSecond(): void
    {
        $storage = $this->createStorage();
        $storage->setOption(FtpStorage::OPTION_TIMEOUT, 0);

        $this->tester->assertSame(1, $storage->exposeConnection()->timeout);
    }

    public function testUnknownOptionIsRefused(): void
    {
        $this->tester->expectThrowable(
            new \InvalidArgumentException('invalid option "NOPE"'),
            static fn() => (new FtpStorage('ftp'))->setOption('NOPE', 1),
        );
    }

    private function createStorage(): object
    {
        return new class('ftp') extends FtpStorage {
            public int $connections = 0;

            /** @var array<string> */
            public array $listed = [];

            public function exposeConnection(): FtpConnection
            {
                return parent::createConnection();
            }

            protected function createConnection(): FtpConnection
            {
                $this->connections++;
                $storage = $this;
                return new class('host', 21, 'u', 'p', false, true, 1, $storage) extends FtpConnection {
                    public function __construct(
                        string $host,
                        int $port,
                        string $login,
                        string $password,
                        bool $ssl,
                        bool $passive,
                        int $timeout,
                        private readonly object $storage,
                    ) {
                        parent::__construct($host, $port, $login, $password, $ssl, $passive, $timeout);
                    }

                    public function list(string $directory): ?array
                    {
                        $this->storage->listed[] = $directory;
                        return [];
                    }
                };
            }
        };
    }
}
