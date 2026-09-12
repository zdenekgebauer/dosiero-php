<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\AccessForbiddenException;
use Dosiero\InvalidRequestException;
use Dosiero\StorageException;

class LocalStorageRenameTest extends LocalStorageBase
{
    /** @return array<string, array<string>> */
    public static function executableNames(): array
    {
        return [
            'php' => ['evil.php'],
            'phtml' => ['evil.phtml'],
            'phar' => ['evil.phar'],
            'trailing extension wins nothing' => ['evil.php.txt'],
            'uppercase' => ['evil.PHP'],
            'htaccess' => ['evil.htaccess'],
        ];
    }
    public function testRenameDifferentFolder(): void
    {
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'rename';
        $_POST['old'] = 'not-exists.txt';
        $_POST['new'] = 'folder/copy-file.txt';

        $connector = $this->getConnectorDefault();

        $this->tester->expectThrowable(
            new InvalidRequestException('invalid name "folder/copy-file.txt"'),
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );
    }

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
            static fn(array $file) => $file['name'] === $fileName,
        );
        $itemFile1New = array_filter(
            $responseJson->files,
            static fn(array $file) => $file['name'] === $fileNameNew,
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
        mkdir($folderPath, 0o777);
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
            static fn(array $file) => $file['name'] === $folderName,
        );
        $itemFolderNew = array_filter(
            $files,
            static fn(array $file) => $file['name'] === $folderNameNew,
        );
        $this->tester->assertCount(0, $itemFolder);
        $this->tester->assertCount(1, $itemFolderNew);

        $this->tester->assertFalse(is_dir($folderPath));
        $this->tester->assertDirectoryExists($folderPathNew);

        $this->tester->assertTrue(property_exists($responseJson, 'storage'));
        $folders = $responseJson->storage->folders;
        $this->tester->assertEquals($folderNameNew, $folders[0]['name']);
    }

    public function testRenameFolderToAnExecutableNameIsAllowed(): void
    {
        mkdir($this->testDirectory . '/folder');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'rename';
        $_POST['old'] = 'folder';
        $_POST['new'] = 'config.ini';

        $responseJson = $this->getConnectorDefault()->handleRequest()->toStdClass();

        // a directory is not served as a script, and the rule is about what the web server executes
        $this->tester->assertEmpty($responseJson->msg);
        $this->tester->assertDirectoryExists($this->testDirectory . '/config.ini');
    }

    /**
     * The allowlist deliberately does not apply here: files reach the directory by ftp and by hand
     * too, and those are exactly the ones whose names need repairing.
     */
    public function testRenameKeepsAnExtensionOutsideTheAllowlist(): void
    {
        $filePath = $this->testDirectory . '/broken name.svg';
        file_put_contents($filePath, '<svg></svg>');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'rename';
        $_POST['old'] = 'broken name.svg';
        $_POST['new'] = 'logo.svg';

        $responseJson = $this->getConnectorDefault()->handleRequest()->toStdClass();

        $this->tester->assertEmpty($responseJson->msg);
        $this->tester->assertFileExists($this->testDirectory . '/logo.svg');
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
            static function () use ($connector): void {
                $connector->handleRequest();
            },
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
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );
    }

    /**
     * The upload rules were never applied to rename, so a file that got in as .txt could be turned
     * into .php afterwards - the extension allowlist held for the way in and nowhere else.
     *
     * @dataProvider executableNames
     */
    public function testRenameToExecutableIsRefused(string $newName): void
    {
        $filePath = $this->testDirectory . '/innocent.txt';
        file_put_contents($filePath, 'content');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'rename';
        $_POST['old'] = 'innocent.txt';
        $_POST['new'] = $newName;

        $connector = $this->getConnectorDefault();
        $this->tester->expectThrowable(StorageException::class, static function () use ($connector): void {
            $connector->handleRequest();
        });

        // a refusal must leave the original where it was
        $this->tester->assertFileExists($filePath);
        $this->tester->assertFileDoesNotExist($this->testDirectory . '/' . $newName);
    }
}
