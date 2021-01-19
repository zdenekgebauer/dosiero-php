<?php

declare(strict_types=1);

namespace Dosiero;

use Dosiero\Local\LocalDirectory;

class LocalStorageDeleteTest extends LocalStorageBase
{
    public function testDelete(): void
    {
        $baseDir = $this->testDirectory;

        $fileName1 = 'file1.txt';
        $fileName2 = 'file2.txt';
        $filePath1 = $baseDir . '/' . $fileName1;
        $filePath2 = $baseDir . '/' . $fileName2;
        file_put_contents($filePath1, '');
        file_put_contents($filePath2, '');

        $folderName1 = 'folder1';
        $folderName2 = 'folder2';
        $folderPath1 = $baseDir . '/' . $folderName1;
        $folderPath2 = $baseDir . '/' . $folderName2;
        mkdir($folderPath1 . '/subfolder', 0777, true);
        file_put_contents($folderPath1 . '/file.txt', '');
        mkdir($folderPath2 . '/subfolder', 0777, true);

        new LocalDirectory($baseDir, true, 50); // refresh cache

        // delete file
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'delete';
        $_POST['files'] = [$fileName1];

        $responseJson = $this->getConnectorDefault()->handleRequest()->toStdClass();

        $this->tester->assertEmpty($responseJson->msg);

        $itemFile1 = array_filter(
            $responseJson->files,
            static function (array $file) use ($fileName1) {
                return $file['name'] === $fileName1;
            }
        );
        $itemFile2 = array_filter(
            $responseJson->files,
            static function (array $file) use ($fileName2) {
                return $file['name'] === $fileName2;
            }
        );

        $this->tester->assertCount(0, $itemFile1);
        $this->tester->assertCount(1, $itemFile2);
        $this->tester->assertFalse(property_exists($responseJson, 'storage'));

        $this->tester->assertFileNotExists($filePath1);
        $this->tester->assertFileExists($filePath2);

        $cache = $this->getCached($this->testDirectory . '/.htdircache');
        $this->tester->assertArrayNotHasKey($fileName1, $cache);
        $this->tester->assertArrayHasKey($fileName2, $cache);

        // delete folder
        $_POST['files'] = [$folderName1];

        $responseJson = $this->getConnectorDefault()->handleRequest()->toStdClass();

        $this->tester->assertEmpty($responseJson->msg);

        $itemFolder1 = array_filter(
            $responseJson->files,
            static function (array $file) use ($folderName1) {
                return $file['name'] === $folderName1;
            }
        );
        $itemFolder2 = array_filter(
            $responseJson->files,
            static function (array $file) use ($folderName2) {
                return $file['name'] === $folderName2;
            }
        );

        $this->tester->assertCount(0, $itemFolder1);
        $this->tester->assertCount(1, $itemFolder2);
        $this->tester->assertTrue(property_exists($responseJson, 'storage'));

        $this->tester->assertFalse(is_dir($folderPath1));
        $this->tester->assertDirectoryExists($folderPath2);

        $cache = $this->getCached($this->testDirectory . '/.htdircache');
        $this->tester->assertArrayNotHasKey($folderName1, $cache);
        $this->tester->assertArrayHasKey($folderName2, $cache);
    }

    public function testDeleteFail(): void
    {
        $fileName = 'not-exists.txt';

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'delete';
        $_POST['files'] = [$fileName];

        $connector = $this->getConnectorDefault();

        $this->tester->expectThrowable(
            new StorageException('cannot delete "' . $fileName . '"'),
            static function () use ($connector) {
                $connector->handleRequest();
            }
        );
    }
}
