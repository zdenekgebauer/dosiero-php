<?php

declare(strict_types=1);

namespace Dosiero;

class LocalStorageMoveTest extends LocalStorageBase
{

    public function testMoveFiles(): void
    {
        $fileName1 = 'file1.txt';
        $fileName2 = 'file2.txt';

        $filePath1 = $this->testDirectory . '/' . $fileName1;
        $filePath2 = $this->testDirectory . '/' . $fileName2;
        file_put_contents($filePath1, '');
        file_put_contents($filePath2, '');
        $targetPath = 'target_path';
        $targetDir = $this->testDirectory . '/' . $targetPath;
        mkdir($targetDir);

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'move';
        $_POST['files'] = [$fileName1, $fileName2];
        $_POST['target_storage'] = self::STORAGE_NAME;
        $_POST['target_path'] = $targetPath;

        $responseJson = $this->getConnectorDefault()->handleRequest()->toStdClass();

        $this->tester->assertCount(1, $responseJson->files);

        $itemFolder = array_filter(
            $responseJson->files,
            static function (array $file) use ($targetPath) {
                return $file['name'] === $targetPath;
            }
        );
        $this->tester->assertCount(1, $itemFolder);

        $this->tester->assertFalse(property_exists($responseJson, 'storage'));

        $this->tester->assertFileNotExists($filePath1);
        $this->tester->assertFileNotExists($filePath2);

        $this->tester->assertFileExists($targetDir . '/' . $fileName1);
        $this->tester->assertFileExists($targetDir . '/' . $fileName2);

        $cache = $this->getCached($this->testDirectory . '/' . $targetPath . '/.htdircache');
        $this->tester->assertArrayHasKey($fileName1, $cache);
        $this->tester->assertArrayHasKey($fileName2, $cache);
    }

    public function testMoveFolders(): void
    {
        $fileNameRoot1 = 'file_root1.txt';
        $fileNameRoot2 = 'file_root2.txt';
        $fileName1 = 'file1.txt';
        $fileName2 = 'file2.txt';

        $folder1 = 'folder1';
        $folder2 = 'folder2';
        $subFolder2 = 'subfolder2';

        file_put_contents($this->testDirectory . '/' . $fileNameRoot1, '');
        file_put_contents($this->testDirectory . '/' . $fileNameRoot2, '');
        mkdir($this->testDirectory . '/' . $folder1);
        mkdir($this->testDirectory . '/' . $folder2 . '/' . $subFolder2, 0777, true);

        $filePath1 = $this->testDirectory . '/' . $folder1 . '/' . $fileName1;
        $filePath2 = $this->testDirectory . '/' . $folder2 . '/' . $fileName2;
        file_put_contents($filePath1, '');
        file_put_contents($filePath2, '');

        $targetPath = 'target_folder';
        $targetDir = $this->testDirectory . '/' . $targetPath;
        mkdir($targetDir);

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'move';
        $_POST['files'] = [$folder1, $folder2, $fileNameRoot1];
        $_POST['target_storage'] = self::STORAGE_NAME;
        $_POST['target_path'] = $targetPath;

        $responseJson = $this->getConnectorDefault()->handleRequest()->toStdClass();

        $this->tester->assertCount(2, $responseJson->files);
        $files = $responseJson->files;
        $itemFolder3 = array_filter(
            $responseJson->files,
            static function (array $file) use ($targetPath) {
                return $file['name'] === $targetPath;
            }
        );

        $itemFileNameRoot2 = array_filter(
            $files,
            static function (array $file) use ($fileNameRoot2) {
                return $file['name'] === $fileNameRoot2;
            }
        );
        $this->tester->assertCount(1, $itemFolder3);
        $this->tester->assertCount(1, $itemFileNameRoot2);

        $this->tester->assertTrue(property_exists($responseJson, 'storage'));

        $this->tester->assertFileNotExists($filePath1);
        $this->tester->assertFileNotExists($filePath2);

        $this->tester->assertFileExists($this->testDirectory . '/' . $targetPath . '/' . $folder1 . '/' . $fileName1);
        $this->tester->assertFileExists($this->testDirectory . '/' . $targetPath . '/' . $folder2 . '/' . $fileName2);
        $this->tester->assertDirectoryExists($this->testDirectory . '/' . $targetPath . '/' . $folder2 . '/' . $subFolder2);

        $cache = $this->getCached($this->testDirectory . '/.htdircache');
        $this->tester->assertArrayNotHasKey($folder1, $cache);
        $this->tester->assertArrayNotHasKey($folder2, $cache);
        $this->tester->assertArrayNotHasKey($fileNameRoot1, $cache);
        $this->tester->assertArrayHasKey($fileNameRoot2, $cache);
    }

    public function testMoveNotExistingFile(): void
    {
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'move';
        $_POST['files'] = ['not-exists.txt'];
        $_POST['target_storage'] = self::STORAGE_NAME;
        $_POST['target_path'] = '';

        $connector = $this->getConnectorDefault();

        $this->tester->expectThrowable(
            new StorageException('cannot move "not-exists.txt"'),
            static function () use ($connector) {
                $connector->handleRequest();
            }
        );
    }
}
