<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\Connector;
use Dosiero\InvalidRequestException;
use Dosiero\Local\LocalStorage;
use Dosiero\Storage;

// copy and move between two storages are refused; K10 in Dosiero/docs/01-nalezy.md keeps the case
class ConnectorCrossStorageTest extends LocalStorageBase
{
    private const string SECOND_STORAGE_NAME = 'local2';

    public function testCopyToDifferentStorageIsRefused(): void
    {
        $this->prepareRequest('copy');
        $connector = $this->getConnectorTwoStorages();

        $this->tester->expectThrowable(
            new InvalidRequestException('unsupported operation: copy to different storage'),
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );
        $this->tester->assertFileNotExists($this->secondDirectory() . '/file1.txt');
    }

    public function testMoveToDifferentStorageIsRefused(): void
    {
        $this->prepareRequest('move');
        $connector = $this->getConnectorTwoStorages();

        $this->tester->expectThrowable(
            new InvalidRequestException('unsupported operation: move to different storage'),
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );
        $this->tester->assertFileExists($this->testDirectory . '/file1.txt');
    }

    protected function _after()
    {
        $this->tester->emptyDirRecursive($this->secondDirectory());
        parent::_after();
    }

    private function getConnectorTwoStorages(): Connector
    {
        $connector = new Connector($this->createConfig());
        $directories = [
            self::STORAGE_NAME => $this->testDirectory,
            self::SECOND_STORAGE_NAME => $this->secondDirectory(),
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

    private function prepareRequest(string $action): void
    {
        file_put_contents($this->testDirectory . '/file1.txt', '');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = $action;
        $_POST['files'] = ['file1.txt'];
        $_POST['target_storage'] = self::SECOND_STORAGE_NAME;
        $_POST['target_path'] = '';
    }

    private function secondDirectory(): string
    {
        return codecept_data_dir('local2');
    }
}
