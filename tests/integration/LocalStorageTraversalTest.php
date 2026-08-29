<?php

declare(strict_types=1);

namespace Dosiero;

/**
 * Nothing may escape OPTION_BASE_DIR - neither through the "path" parameter nor
 * through an item name in files[] / old / new / folder.
 */
class LocalStorageTraversalTest extends LocalStorageBase
{

    /**
     * @return array<string, array{string}>
     */
    public function providerEscapingPaths(): array
    {
        return [
            'parent' => ['..'],
            'nested parent' => ['sub/../..'],
            'deep parent' => ['../../..'],
            'leading slash parent' => ['/..'],
            'absolute' => [DIRECTORY_SEPARATOR === '\\' ? 'C:/Windows' : '/etc'],
        ];
    }

    /**
     * @dataProvider providerEscapingPaths
     */
    public function testListingOutsideBaseDirIsRefused(string $path): void
    {
        mkdir($this->testDirectory . '/sub');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'files';
        $_GET['path'] = $path;

        $connector = $this->getConnectorDefault();

        $this->tester->expectThrowable(
            StorageException::class,
            static function () use ($connector) {
                $connector->handleRequest();
            }
        );
    }

    public function testDeleteOutsideCurrentFolderIsRefused(): void
    {
        $outside = $this->testDirectory . '/../outside.txt';
        file_put_contents($outside, 'keep me');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'delete';
        $_POST['files'] = ['../outside.txt'];

        $connector = $this->getConnectorDefault();

        try {
            $connector->handleRequest();
        } catch (\Throwable $exception) {
            // refusing with an exception is the expected behaviour
        }

        $this->tester->assertFileExists($outside, 'file outside the storage must survive');
        unlink($outside);
    }

    public function testUploadOutsideCurrentFolderIsRefused(): void
    {
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'upload';
        $_FILES = [
            'file' => [
                'name' => '../escaped.txt',
                'type' => 'text/plain',
                'size' => 3,
                'tmp_name' => codecept_data_dir('phpunit.jpg'),
                'error' => 0,
            ],
        ];

        $connector = $this->getConnectorDefault();

        try {
            $connector->handleRequest();
        } catch (\Throwable $exception) {
            // refusing with an exception is the expected behaviour
        }

        $this->tester->assertFileNotExists(
            $this->testDirectory . '/../escaped.txt',
            'upload must not write outside the storage'
        );
    }

    public function testRenameOutsideCurrentFolderIsRefused(): void
    {
        file_put_contents($this->testDirectory . '/inside.txt', '');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'rename';
        $_POST['old'] = 'inside.txt';
        $_POST['new'] = '../escaped.txt';

        $connector = $this->getConnectorDefault();

        try {
            $connector->handleRequest();
        } catch (\Throwable $exception) {
            // refusing with an exception is the expected behaviour
        }

        $this->tester->assertFileNotExists($this->testDirectory . '/../escaped.txt');
        $this->tester->assertFileExists($this->testDirectory . '/inside.txt');
    }

    public function testMkDirOutsideCurrentFolderIsRefused(): void
    {
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'mkdir';
        $_POST['folder'] = '..';

        $connector = $this->getConnectorDefault();

        $this->tester->expectThrowable(
            InvalidRequestException::class,
            static function () use ($connector) {
                $connector->handleRequest();
            }
        );
    }
}