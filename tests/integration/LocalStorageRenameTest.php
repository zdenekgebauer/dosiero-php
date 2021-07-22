<?php

declare(strict_types=1);

namespace Dosiero;

class LocalStorageRenameTest extends LocalStorageBase
{

    public function testRenameFile(): void
    {
        $fileName = 'file1.txt';
        $fileNameNew = 'file1.new';
        $filePath = $this->testDirectory . '/' . $fileName;
        $filePathNew = $this->testDirectory . '/' . $fileNameNew;
        file_put_contents($filePath, '');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'rename';
        $_POST['old'] = $fileName;
        $_POST['new'] = $fileNameNew;

        $responseJson = $this->getConnectorDefault()->handleRequest()->toStdClass();

        $this->tester->assertEmpty($responseJson->msg);

        $itemFile1 = array_filter(
            $responseJson->files,
            static function (array $file) use ($fileName) {
                return $file['name'] === $fileName;
            }
        );
        $itemFile1New = array_filter(
            $responseJson->files,
            static function (array $file) use ($fileNameNew) {
                return $file['name'] === $fileNameNew;
            }
        );
        $this->tester->assertCount(0, $itemFile1);
        $this->tester->assertCount(1, $itemFile1New);

        $this->tester->assertFalse(property_exists($responseJson, 'storage'));

        $this->tester->assertFileNotExists($filePath);
        $this->tester->assertFileExists($filePathNew);
    }

    public function testRenameFolder(): void
    {
        $folderName = 'folder1';
        $folderNameNew = 'folder1-new';
        $folderPath = $this->testDirectory . '/' . $folderName;
        mkdir($folderPath, 0777);
        $folderPathNew = $this->testDirectory . '/' . $folderNameNew;

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'rename';
        $_POST['old'] = $folderName;
        $_POST['new'] = $folderNameNew;

        $response = $this->getConnectorDefault()->handleRequest();
        $responseJson = $response->toStdClass();
        $this->tester->assertEmpty($responseJson->msg);

        $files = $responseJson->files;

        $itemFolder = array_filter(
            $files,
            static function (array $file) use ($folderName) {
                return $file['name'] === $folderName;
            }
        );
        $itemFolderNew = array_filter(
            $files,
            static function (array $file) use ($folderNameNew) {
                return $file['name'] === $folderNameNew;
            }
        );
        $this->tester->assertCount(0, $itemFolder);
        $this->tester->assertCount(1, $itemFolderNew);

        $this->tester->assertFalse(is_dir($folderPath));
        $this->tester->assertDirectoryExists($folderPathNew);

        $this->tester->assertTrue(property_exists($responseJson, 'storage'));
        $folders = $responseJson->storage->folders;
        $this->tester->assertEquals($folderNameNew, $folders[0]['name']);
    }

    public function testRenameNotExistingFile(): void
    {
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'rename';
        $_POST['old'] = 'not-exists.txt';
        $_POST['new'] = 'copy-file.txt';

        $connector = $this->getConnectorDefault();

        $this->tester->expectThrowable(
            new StorageException('cannot rename "not-exists.txt" to "copy-file.txt"'),
            static function () use ($connector) {
                $connector->handleRequest();
            }
        );
    }

    public function testRenameDifferentFolder(): void
    {
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'rename';
        $_POST['old'] = 'not-exists.txt';
        $_POST['new'] = 'folder/copy-file.txt';

        $connector = $this->getConnectorDefault();

        $this->tester->expectThrowable(
            new StorageException('allowed rename in current folder only'),
            static function () use ($connector) {
                $connector->handleRequest();
            }
        );
    }

    public function testRenameReadOnlyStorage(): void
    {
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'rename';
        $_POST['old'] = 'not-exists.txt';
        $_POST['new'] = 'copy-file.txt';

        $connector = $this->getConnectorReadOnly();

        $this->tester->expectThrowable(
            new AccessForbiddenException('storage "local1" is read only'),
            static function () use ($connector) {
                $connector->handleRequest();
            }
        );
    }
}
