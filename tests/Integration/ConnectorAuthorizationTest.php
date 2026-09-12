<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\AccessForbiddenException;
use Dosiero\Connector;
use Dosiero\Local\LocalStorage;
use Dosiero\Storage;

class ConnectorAuthorizationTest extends LocalStorageBase
{
    private const string SECOND_STORAGE_NAME = 'local2';

    public function testHiddenStorageIsNeitherListedNorReachable(): void
    {
        $connector = $this->connectorWith(
            static fn(string $action, string $storage): bool => $storage === self::STORAGE_NAME,
        );

        $_GET['action'] = 'storages';
        $storages = $connector->handleRequest()->toStdClass()->storages;
        $names = array_map(static fn(\stdClass $storage): string => $storage->name, $storages);
        $this->tester->assertSame([self::STORAGE_NAME], $names);

        $_GET['action'] = 'files';
        $_GET['storage'] = self::SECOND_STORAGE_NAME;
        $this->tester->expectThrowable(
            new AccessForbiddenException('access denied'),
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );
    }

    public function testReadingAllowedWhileWritingIsRefused(): void
    {
        file_put_contents($this->testDirectory . '/file1.txt', '');
        $connector = $this->connectorWith(
            static fn(string $action, string $storage): bool => $action === 'files',
        );

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'files';
        $this->tester->assertEmpty($connector->handleRequest()->toStdClass()->msg);

        $_GET['action'] = 'delete';
        $_POST['files'] = ['file1.txt'];
        $this->tester->expectThrowable(
            new AccessForbiddenException('access denied'),
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );
        $this->tester->assertFileExists($this->testDirectory . '/file1.txt');
    }

    public function testWithoutCallbackTheOtherChecksDecide(): void
    {
        $connector = $this->connectorWith(null);

        $_GET['action'] = 'storages';
        $storages = $connector->handleRequest()->toStdClass()->storages;

        $this->tester->assertCount(2, $storages);
    }

    protected function _after()
    {
        $this->tester->emptyDirRecursive(codecept_data_dir('local2'));
        parent::_after();
    }

    private function connectorWith(?callable $authorization): Connector
    {
        $config = $this->createConfig();
        if ($authorization !== null) {
            $config->requireCallback($authorization);
        }

        $connector = new Connector($config);
        $directories = [
            self::STORAGE_NAME => $this->testDirectory,
            self::SECOND_STORAGE_NAME => codecept_data_dir('local2'),
        ];
        foreach ($directories as $name => $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0o777, true);
            }
            $storage = new LocalStorage((string)$name);
            $storage->setOption(LocalStorage::OPTION_BASE_DIR, $dir);
            $storage->setOption(Storage::OPTION_BASE_URL, $this->testUrl);
            $connector->addStorage($storage);
        }
        return $connector;
    }
}
