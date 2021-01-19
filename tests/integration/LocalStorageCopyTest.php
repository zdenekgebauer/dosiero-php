<?php

declare(strict_types=1);

namespace Dosiero;

class LocalStorageCopyTest extends LocalStorageBase
{

    public function testCopyFiles(): void
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
        $_GET['action'] = 'copy';
        $_POST['files'] = [$fileName1, $fileName2];
        $_POST['target_storage'] = self::STORAGE_NAME;
        $_POST['target_path'] = $targetPath;

        $responseJson = $this->getConnectorDefault()->handleRequest()->toStdClass();

        $files = $responseJson->files;
        $itemFile1 = array_filter(
            $files,
            static function (array $file) use ($fileName1) {
                return $file['name'] === $fileName1;
            }
        );
        $itemFile2 = array_filter(
            $files,
            static function (array $file) use ($fileName2) {
                return $file['name'] === $fileName2;
            }
        );

        $this->tester->assertCount(1, $itemFile1);
        $this->tester->assertCount(1, $itemFile2);

        $this->tester->assertFalse(property_exists($responseJson, 'storage'));

        $this->tester->assertFileExists($filePath1);
        $this->tester->assertFileExists($filePath2);

        $this->tester->assertFileExists($targetDir . '/' . $fileName1);
        $this->tester->assertFileExists($targetDir . '/' . $fileName2);

        $cache = $this->getCached($this->testDirectory . '/' . $targetPath . '/.htdircache');
        $this->tester->assertArrayHasKey($fileName1, $cache);
        $this->tester->assertArrayHasKey($fileName2, $cache);
    }

    public function testCopyFolders(): void
    {
        $fileNameRoot1 = 'file_root1.txt';
        $fileNameRoot2 = 'file_root2.txt';
        $fileName1 = 'file1.txt';
        $fileName2 = 'file2.txt';

        $folder1 = 'folder1';
        $folder2 = 'folder2';
        $subFolder2 = 'subfolder2';

        file_put_contents($this->testDirectory . '/'. $fileNameRoot1, '');
        file_put_contents($this->testDirectory . '/'. $fileNameRoot2, '');
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
        $_GET['action'] = 'copy';
        $_POST['files'] = [$folder1, $folder2, $fileNameRoot1];
        $_POST['target_storage'] = self::STORAGE_NAME;
        $_POST['target_path'] = $targetPath;

        $responseJson = $this->getConnectorDefault()->handleRequest()->toStdClass();

        $itemFolder1 = array_filter(
            $responseJson->files,
            static function (array $file) use ($folder1) {
                return $file['name'] === $folder1;
            }
        );
        $itemFolder2 = array_filter(
            $responseJson->files,
            static function (array $file) use ($folder2) {
                return $file['name'] === $folder2;
            }
        );

        $this->tester->assertCount(1, $itemFolder1);
        $this->tester->assertCount(1, $itemFolder2);

        $this->tester->assertTrue(property_exists($responseJson, 'storage'));

        $this->tester->assertFileExists($filePath1);
        $this->tester->assertFileExists($filePath2);

        $this->tester->assertFileExists($targetDir . '/' . $fileNameRoot1);
        $this->tester->assertFileNotExists($targetDir . '/' . $fileNameRoot2);
        $this->tester->assertFileExists($targetDir . '/' . $folder1 . '/' . $fileName1);
        $this->tester->assertFileExists($targetDir . '/' . $folder2 . '/' . $fileName2);
        $this->tester->assertDirectoryExists($targetDir . '/' . $folder2 . '/' . $subFolder2);

        $cache = $this->getCached($this->testDirectory . '/' . $targetPath . '/.htdircache');
        $this->tester->assertArrayHasKey($folder1, $cache);
        $this->tester->assertArrayHasKey($folder2, $cache);
        $this->tester->assertArrayHasKey($fileNameRoot1,  $cache);
        $this->tester->assertArrayNotHasKey($fileNameRoot2,  $cache);
    }

    public function testCopyNotExistingFile(): void
    {
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'copy';
        $_POST['files'] = ['not-exists.txt'];
        $_POST['target_storage'] = self::STORAGE_NAME;
        $_POST['target_path'] = '';

        $connector = $this->getConnectorDefault();

        $this->tester->expectThrowable(
            new StorageException('cannot copy "not-exists.txt"'),
            static function () use ($connector) {
                $connector->handleRequest();
            }
        );
    }
}
