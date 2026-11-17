<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\AccessForbiddenException;
use Dosiero\Config;
use Dosiero\Connector;
use Dosiero\InvalidRequestException;
use Dosiero\Local\LocalStorage;
use Dosiero\Storage;

// the connector drives an ftp storage exactly as a local one, side by side in one request
class ConnectorFtpStorageTest extends FtpStorageBase
{
    private const string LOCAL_STORAGE_NAME = 'local1';

    public function testCopyToTheLocalStorageIsRefused(): void
    {
        $this->writeFixture('a.txt');
        $_GET = ['action' => 'copy', 'storage' => self::STORAGE_NAME];
        $_POST = ['files' => ['a.txt'], 'target_storage' => self::LOCAL_STORAGE_NAME, 'target_path' => ''];
        $connector = $this->createConnector();

        $this->tester->expectThrowable(
            new InvalidRequestException('unsupported operation: copy to different storage'),
            static fn() => $connector->handleRequest(),
        );
        $this->tester->assertFileDoesNotExist($this->localDirectory() . '/a.txt');
    }

    public function testFilesAction(): void
    {
        $this->writeFixture('sub/a b.txt', 'abc');
        $_GET = ['action' => 'files', 'storage' => self::STORAGE_NAME, 'path' => 'sub'];

        $files = $this->createConnector()->handleRequest()->toStdClass()->files;

        $this->tester->assertCount(1, $files);
        $this->tester->assertSame('a b.txt', $files[0]['name']);
        $this->tester->assertSame(3, $files[0]['size']);
        $this->tester->assertSame($this->testUrl . 'sub/a%20b.txt', $files[0]['url']);
    }

    public function testMkdirRespondsWithTheNewListing(): void
    {
        $_GET = ['action' => 'mkdir', 'storage' => self::STORAGE_NAME];
        $_POST = ['folder' => 'docs'];

        $response = $this->createConnector()->handleRequest()->toStdClass();

        $this->tester->assertSame(['docs'], array_column($response->files, 'name'));
        $this->tester->assertDirectoryExists($this->ftpDirectory . '/docs');
    }

    public function testReadOnlyFtpStorageRefusesMutations(): void
    {
        $_GET = ['action' => 'mkdir', 'storage' => self::STORAGE_NAME];
        $_POST = ['folder' => 'docs'];
        $connector = $this->createConnector(true);

        $this->tester->expectThrowable(
            new AccessForbiddenException('storage "' . self::STORAGE_NAME . '" is read only'),
            static fn() => $connector->handleRequest(),
        );
        $this->tester->assertDirectoryDoesNotExist($this->ftpDirectory . '/docs');
    }

    public function testStoragesListsBoth(): void
    {
        mkdir($this->ftpDirectory . '/remote-folder');
        $_GET = ['action' => 'storages'];

        $storages = $this->createConnector()->handleRequest()->toStdClass()->storages;

        $this->tester->assertSame([self::STORAGE_NAME, self::LOCAL_STORAGE_NAME], array_column($storages, 'name'));
        $this->tester->assertSame('remote-folder', $storages[0]->folders[0]['name']);
    }

    protected function _after()
    {
        $this->tester->emptyDirRecursive($this->localDirectory());
        parent::_after();
    }

    private function createConnector(bool $ftpReadOnly = false): Connector
    {
        $config = new Config();
        $config->allowAnonymous();
        $connector = new Connector($config);
        $connector->addStorage($this->createStorage([Storage::OPTION_READ_ONLY => $ftpReadOnly]));

        if (!is_dir($this->localDirectory())) {
            mkdir($this->localDirectory(), 0o777, true);
        }
        $local = new LocalStorage(self::LOCAL_STORAGE_NAME);
        $local->setOption(LocalStorage::OPTION_BASE_DIR, $this->localDirectory());
        $connector->addStorage($local);
        return $connector;
    }

    private function localDirectory(): string
    {
        return codecept_data_dir('local');
    }
}
