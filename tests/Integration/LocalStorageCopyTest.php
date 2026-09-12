<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\StorageException;

class LocalStorageCopyTest extends LocalStorageBase
{
    public function testCopyDoesNotOverwriteWhenForbidden(): void
    {
        mkdir($this->testDirectory . '/target');
        file_put_contents($this->testDirectory . '/keep.txt', 'source');
        file_put_contents($this->testDirectory . '/target/keep.txt', 'target');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'copy';
        $_POST['files'] = ['keep.txt'];
        $_POST['target_storage'] = self::STORAGE_NAME;
        $_POST['target_path'] = 'target';

        $connector = $this->getConnectorNoOverwrite();
        $this->tester->expectThrowable(StorageException::class, static function () use ($connector): void {
            $connector->handleRequest();
        });

        $this->tester->assertSame('target', file_get_contents($this->testDirectory . '/target/keep.txt'));
        $this->tester->assertSame('source', file_get_contents($this->testDirectory . '/keep.txt'));
    }
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
            static fn(array $file) => $file['name'] === $fileName1,
        );
        $itemFile2 = array_filter(
            $files,
            static fn(array $file) => $file['name'] === $fileName2,
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

    public function testCopyFolderIntoItselfIsRefused(): void
    {
        mkdir($this->testDirectory . '/folder');
        file_put_contents($this->testDirectory . '/folder/inside.txt', 'content');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'copy';
        $_POST['files'] = ['folder'];
        $_POST['target_storage'] = self::STORAGE_NAME;
        $_POST['target_path'] = 'folder';

        $connector = $this->getConnectorDefault();
        $this->tester->expectThrowable(StorageException::class, static function () use ($connector): void {
            $connector->handleRequest();
        });

        $this->tester->assertDirectoryDoesNotExist($this->testDirectory . '/folder/folder');
        $this->tester->assertFileExists($this->testDirectory . '/folder/inside.txt');
    }

    public function testCopyFolderIntoItsOwnChildIsRefused(): void
    {
        mkdir($this->testDirectory . '/folder/child', 0o777, true);

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'copy';
        $_POST['files'] = ['folder'];
        $_POST['target_storage'] = self::STORAGE_NAME;
        $_POST['target_path'] = 'folder/child';

        $connector = $this->getConnectorDefault();
        $this->tester->expectThrowable(StorageException::class, static function () use ($connector): void {
            $connector->handleRequest();
        });

        $this->tester->assertDirectoryDoesNotExist($this->testDirectory . '/folder/child/folder');
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

        file_put_contents($this->testDirectory . '/' . $fileNameRoot1, '');
        file_put_contents($this->testDirectory . '/' . $fileNameRoot2, '');
        mkdir($this->testDirectory . '/' . $folder1);
        mkdir($this->testDirectory . '/' . $folder2 . '/' . $subFolder2, 0o777, true);

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
            static fn(array $file) => $file['name'] === $folder1,
        );
        $itemFolder2 = array_filter(
            $responseJson->files,
            static fn(array $file) => $file['name'] === $folder2,
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
        $this->tester->assertArrayHasKey($fileNameRoot1, $cache);
        $this->tester->assertArrayNotHasKey($fileNameRoot2, $cache);
    }

    /** A name that merely starts with the source name is a different folder, not a descendant. */
    public function testCopyIntoAConfusinglyNamedSiblingWorks(): void
    {
        mkdir($this->testDirectory . '/ab');
        mkdir($this->testDirectory . '/a');
        file_put_contents($this->testDirectory . '/a/inside.txt', 'content');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'copy';
        $_POST['files'] = ['a'];
        $_POST['target_storage'] = self::STORAGE_NAME;
        $_POST['target_path'] = 'ab';

        $responseJson = $this->getConnectorDefault()->handleRequest()->toStdClass();

        $this->tester->assertEmpty($responseJson->msg);
        $this->tester->assertFileExists($this->testDirectory . '/ab/a/inside.txt');
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
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );
    }
}
