<?php

declare(strict_types=1);

namespace Dosiero;

use Dosiero\Local\LocalDirectory;

class LocalStorageFilesTest extends LocalStorageBase
{

    public function testGetStorages(): void
    {
        mkdir($this->testDirectory . '/folder/subfolder', 0777, true);
        file_put_contents($this->testDirectory . '/folder/subfolder/file2.txt', '');
        file_put_contents($this->testDirectory . '/file.txt', 'lorem ipsum');
        copy(codecept_data_dir() . '/phpunit.jpg', $this->testDirectory . '/phpunit.jpg');

        $_GET['action'] = 'storages';
        $response = $this->getConnectorDefault()->handleRequest();
        $responseJson = $response->toStdClass();

        $this->tester->assertEmpty($responseJson->msg);
        $this->tester->assertCount(1, $responseJson->storages);
        $this->tester->assertEquals('local1', $responseJson->storages[0]->name);
        $this->tester->assertFalse($responseJson->storages[0]->read_only);
        $this->tester->assertCount(1, $responseJson->storages[0]->folders);
    }

    public function testInvalidPath(): void
    {
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'files';
        $_GET['path'] = 'not-exists';

        $connector = $this->getConnectorDefault();
        $this->tester->expectThrowable(
            new StorageException('not found path "not-exists"'),
            static function () use ($connector) {
                $connector->handleRequest();
            }
        );
    }

    public function testFilesInRoot(): void
    {
        mkdir($this->testDirectory . '/folder/subfolder', 0777, true);
        file_put_contents($this->testDirectory . '/folder/subfolder/file2.txt', '');
        file_put_contents($this->testDirectory . '/file.txt', 'lorem ipsum');
        copy(codecept_data_dir() . '/phpunit.jpg', $this->testDirectory . '/phpunit.jpg');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'files';

        $response = $this->getConnectorDefault()->handleRequest();
        $responseJson = $response->toStdClass();

        $this->tester->assertEmpty($responseJson->msg);
        $this->tester->assertFalse(property_exists($responseJson, 'storages'));
        $files = $responseJson->files;
        $this->tester->assertCount(3, $files);

        $itemFolder = array_filter(
            $files,
            static function (array $file) {
                return $file['name'] === 'folder';
            }
        );

        $itemFolder = reset($itemFolder);
        $this->tester->assertEquals('dir', $itemFolder['type']);
        $this->tester->assertEquals($this->testUrl . 'folder', $itemFolder['url']);
        $this->tester->assertEquals(
            date('c', filemtime($this->testDirectory . '/folder')),
            $itemFolder['modified']
        );
        $this->tester->assertNull($itemFolder['width']);
        $this->tester->assertNull($itemFolder['height']);
        $this->tester->assertNull($itemFolder['thumbnail']);

        $itemFile = array_filter(
            $files,
            static function (array $file) {
                return $file['name'] === 'file.txt';
            }
        );
        $itemFile = reset($itemFile);
        $this->tester->assertEquals('file', $itemFile['type']);
        $this->tester->assertEquals($this->testUrl . 'file.txt', $itemFile['url']);
        $this->tester->assertEquals(filesize($this->testDirectory . '/file.txt'), $itemFile['size']);
        $this->tester->assertEquals(
            date('c', filemtime($this->testDirectory . '/file.txt')),
            $itemFile['modified']
        );
        $this->tester->assertNull($itemFile['width']);
        $this->tester->assertNull($itemFile['height']);
        $this->tester->assertNull($itemFile['thumbnail']);

        $itemImage = array_filter(
            $files,
            static function (array $file) {
                return $file['name'] === 'phpunit.jpg';
            }
        );
        $itemImage = reset($itemImage);
        $this->tester->assertEquals('file', $itemImage['type']);
        $this->tester->assertEquals($this->testUrl . 'phpunit.jpg', $itemImage['url']);
        $this->tester->assertEquals(filesize($this->testDirectory . '/phpunit.jpg'), $itemImage['size']);
        $this->tester->assertEquals(date('c', filemtime($this->testDirectory . '/phpunit.jpg')),
            $itemImage['modified']);
        [$width, $height] = getimagesize($this->testDirectory . '/phpunit.jpg');
        $this->tester->assertEquals($width, $itemImage['width']);
        $this->tester->assertEquals($height, $itemImage['height']);
        $this->tester->assertStringContainsString('data:image/jp', $itemImage['thumbnail']);

        $cache = $this->getCached($this->testDirectory . '/.htdircache');
        $this->tester->assertCount(3, $cache);
        $this->tester->assertArrayHasKey('folder', $cache);
        $this->tester->assertArrayHasKey('file.txt', $cache);
        $this->tester->assertArrayHasKey('phpunit.jpg', $cache);
    }

    public function testFilesReload(): void
    {
        $fileName = 'file.txt';

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'files-reload';
        $baseDir = $this->testDirectory;
        new LocalDirectory($baseDir, true, 50); // refresh cache
        file_put_contents($baseDir . '/' . $fileName, '');

        $responseJson = $this->getConnectorDefault()->handleRequest()->toStdClass();
        $this->tester->assertEmpty($responseJson->msg);
        $this->tester->assertFalse(property_exists($responseJson, 'storages'));
        $files = $responseJson->files;
        $this->tester->assertCount(1, $files);

        $itemFile = array_filter(
            $files,
            static function (array $file) use ($fileName) {
                return $file['name'] === $fileName;
            }
        );
        $this->tester->assertCount(1, $itemFile);
    }

    public function testFilesInRootWithBrokenCacheFile(): void
    {
        $cacheFile = $this->testDirectory . '/.htdircache';
        file_put_contents($this->testDirectory . '/file.txt', '');
        file_put_contents($cacheFile, '{');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'files';

        $responseJson = $this->getConnectorDefault()->handleRequest()->toStdClass();

        $this->tester->assertEmpty($responseJson->msg);
        $this->tester->assertFalse(property_exists($responseJson, 'storages'));
        $this->tester->assertCount(1, $responseJson->files);

        $cache = $this->getCached($cacheFile);
        $this->tester->assertCount(1, $cache);
        $this->tester->assertArrayHasKey('file.txt', $cache);
    }

    public function testFilesInSubFolder(): void
    {
        mkdir($this->testDirectory . '/folder/subfolder', 0777, true);
        file_put_contents($this->testDirectory . '/folder/subfolder/file2.txt', '');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'files';
        $_GET['path'] = 'folder/subfolder';

        $responseJson = $this->getConnectorDefault()->handleRequest()->toStdClass();

        $this->tester->assertEmpty($responseJson->msg);
        $this->tester->assertFalse(property_exists($responseJson, 'storages'));
        $files = $responseJson->files;
        $this->tester->assertCount(1, $files);
        $this->tester->assertEquals('file2.txt', $files[0]['name']);

        $cache = $this->getCached($this->testDirectory . '/folder/subfolder/.htdircache');
        $this->tester->assertCount(1, $cache);
        $this->tester->assertArrayHasKey('file2.txt', $cache);
    }
}
