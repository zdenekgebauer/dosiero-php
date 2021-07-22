<?php

declare(strict_types=1);

namespace Dosiero;


class LocalStorageMkDirTest extends LocalStorageBase
{
    public function testMkDirInRoot(): void
    {
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'mkdir';
        $newFolderName = 'newfolder';
        $newFolderPath = $this->testDirectory . '/'. $newFolderName;
        $_POST['folder'] = $newFolderName;

        $response = $this->getConnectorDefault()->handleRequest();
        $responseJson = $response->toStdClass();

        $this->tester->assertEmpty($responseJson->msg);

        $this->tester->assertCount(1, $responseJson->files);
        $itemFolder = $responseJson->files[0];
        $this->tester->assertEquals('dir', $itemFolder['type']);
        $this->tester->assertEquals($this->testUrl . $newFolderName, $itemFolder['url']);

        $this->tester->assertCount(1, $responseJson->storage->folders);
        $this->tester->assertDirectoryExists($newFolderPath);

        // create existing folder
        $response = $this->getConnectorDefault()->handleRequest();
        $responseJson = $response->toStdClass();
        $this->tester->assertEmpty($responseJson->msg);
        $this->tester->assertDirectoryExists($newFolderPath);

        rmdir($newFolderPath);
    }

    public function testMkDirWithEmptyFolder(): void
    {
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'mkdir';
        $connector = $this->getConnectorDefault();

        $this->tester->expectThrowable(
            new InvalidRequestException('invalid folder name ""'),
            static function () use ($connector) {
                $connector->handleRequest();
            }
        );

        $_POST['folder'] = '*';
        $this->tester->expectThrowable(
            new InvalidRequestException('invalid folder name "*"'),
            static function () use ($connector) {
                $connector->handleRequest();
            }
        );
    }

    public function testMkDirReadOnlyStorage(): void
    {
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'mkdir';
        $_POST['folder'] = 'newfolder';

        $connector = $this->getConnectorReadOnly();

        $this->tester->expectThrowable(
            new AccessForbiddenException('storage "' . self::STORAGE_NAME .'" is read only'),
            static function () use ($connector) {
                $connector->handleRequest();
            }
        );
    }
}
