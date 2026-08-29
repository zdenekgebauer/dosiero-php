<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\Folder;
use Dosiero\Storage;

/**
 * Copy and move used to work only in the root of the storage: absPath() returned
 * the base directory with a trailing slash there, but a sub folder without one,
 * so "$sourceDir . $file" produced "/data/subfile.txt". The whole suite worked in
 * the root, so nothing caught it.
 */
class LocalStorageSubFolderTest extends LocalStorageBase
{
    private const SOURCE = 'source';

    private const TARGET = 'target';

    public function testCopyFromSubFolder(): void
    {
        $fileName = 'file.txt';
        file_put_contents($this->testDirectory . '/' . self::SOURCE . '/' . $fileName, 'content');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'copy';
        $_GET['path'] = self::SOURCE;
        $_POST['files'] = [$fileName];
        $_POST['target_storage'] = self::STORAGE_NAME;
        $_POST['target_path'] = self::TARGET;

        $this->getConnectorDefault()->handleRequest();

        $this->tester->assertFileExists($this->testDirectory . '/' . self::SOURCE . '/' . $fileName);
        $this->tester->assertFileExists($this->testDirectory . '/' . self::TARGET . '/' . $fileName);
    }

    public function testMoveFolderFromSubFolder(): void
    {
        $folder = 'nested';
        $fileName = 'file.txt';
        mkdir($this->testDirectory . '/' . self::SOURCE . '/' . $folder);
        file_put_contents($this->testDirectory . '/' . self::SOURCE . '/' . $folder . '/' . $fileName, '');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'move';
        $_GET['path'] = self::SOURCE;
        $_POST['files'] = [$folder];
        $_POST['target_storage'] = self::STORAGE_NAME;
        $_POST['target_path'] = self::TARGET;

        $this->getConnectorDefault()->handleRequest();

        $this->tester->assertFileNotExists($this->testDirectory . '/' . self::SOURCE . '/' . $folder);
        $this->tester->assertFileExists(
            $this->testDirectory . '/' . self::TARGET . '/' . $folder . '/' . $fileName,
        );
    }

    public function testMoveFromSubFolder(): void
    {
        $fileName = 'file.txt';
        file_put_contents($this->testDirectory . '/' . self::SOURCE . '/' . $fileName, 'content');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'move';
        $_GET['path'] = self::SOURCE;
        $_POST['files'] = [$fileName];
        $_POST['target_storage'] = self::STORAGE_NAME;
        $_POST['target_path'] = self::TARGET;

        $this->getConnectorDefault()->handleRequest();

        $this->tester->assertFileNotExists($this->testDirectory . '/' . self::SOURCE . '/' . $fileName);
        $this->tester->assertFileExists($this->testDirectory . '/' . self::TARGET . '/' . $fileName);
    }

    public function testRenameInSubFolder(): void
    {
        file_put_contents($this->testDirectory . '/' . self::SOURCE . '/old.txt', '');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'rename';
        $_GET['path'] = self::SOURCE;
        $_POST['old'] = 'old.txt';
        $_POST['new'] = 'new.txt';

        $this->getConnectorDefault()->handleRequest();

        $this->tester->assertFileNotExists($this->testDirectory . '/' . self::SOURCE . '/old.txt');
        $this->tester->assertFileExists($this->testDirectory . '/' . self::SOURCE . '/new.txt');
    }

    protected function _before()
    {
        parent::_before();
        mkdir($this->testDirectory . '/' . self::SOURCE);
        mkdir($this->testDirectory . '/' . self::TARGET);
    }
}
