<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\StorageException;

/**
 * absPath() canonicalizes the root of the request, but nothing re-checked the entries met on the way
 * down - so a link inside the storage was followed by the listing, by the recursive copy and, worst
 * of all, by the recursive delete, which emptied the target outside the storage.
 *
 * The API cannot create a link; one gets there by ftp, by an admin, or by another process. That is
 * enough to make it a real path, and the policy is simply not to follow them.
 */
class LocalStorageSymlinkTest extends LocalStorageBase
{
    private string $outsideDirectory = '';

    public function testCopyingAFolderDoesNotFollowALinkInside(): void
    {
        mkdir($this->testDirectory . '/source');
        file_put_contents($this->testDirectory . '/source/real.txt', 'real');
        if (!@symlink($this->outsideDirectory . '/secret.txt', $this->testDirectory . '/source/link.txt')) {
            $this->markTestSkipped('cannot create a symlink here');
        }
        mkdir($this->testDirectory . '/target');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'copy';
        $_POST['files'] = ['source'];
        $_POST['target_storage'] = self::STORAGE_NAME;
        $_POST['target_path'] = 'target';

        $responseJson = $this->getConnectorDefault()->handleRequest()->toStdClass();

        $this->tester->assertEmpty($responseJson->msg);
        $this->tester->assertFileExists($this->testDirectory . '/target/source/real.txt');
        // following it would have copied content from outside the storage under a name inside it
        $this->tester->assertFileDoesNotExist($this->testDirectory . '/target/source/link.txt');
    }

    public function testCopyingALinkIsRefused(): void
    {
        if (!@symlink($this->outsideDirectory . '/secret.txt', $this->testDirectory . '/link.txt')) {
            $this->markTestSkipped('cannot create a symlink here');
        }
        mkdir($this->testDirectory . '/target');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'copy';
        $_POST['files'] = ['link.txt'];
        $_POST['target_storage'] = self::STORAGE_NAME;
        $_POST['target_path'] = 'target';

        $connector = $this->getConnectorDefault();
        $this->tester->expectThrowable(StorageException::class, static function () use ($connector): void {
            $connector->handleRequest();
        });

        $this->tester->assertFileDoesNotExist($this->testDirectory . '/target/link.txt');
    }

    public function testDeletingALinkedFolderLeavesItsTargetAlone(): void
    {
        if (!@symlink($this->outsideDirectory, $this->testDirectory . '/link')) {
            $this->markTestSkipped('cannot create a symlink here');
        }

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'delete';
        $_POST['files'] = ['link'];

        $connector = $this->getConnectorDefault();
        $this->tester->expectThrowable(StorageException::class, static function () use ($connector): void {
            $connector->handleRequest();
        });

        // the point of the whole case: the data behind the link is still there
        $this->tester->assertFileExists($this->outsideDirectory . '/secret.txt');
    }

    public function testLinkedFileIsNotListed(): void
    {
        if (!@symlink($this->outsideDirectory . '/secret.txt', $this->testDirectory . '/link.txt')) {
            $this->markTestSkipped('cannot create a symlink here');
        }
        file_put_contents($this->testDirectory . '/real.txt', 'real');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'files';

        $responseJson = $this->getConnectorDefault()->handleRequest()->toStdClass();

        $names = array_column($responseJson->files, 'name');
        $this->tester->assertContains('real.txt', $names);
        $this->tester->assertNotContains('link.txt', $names);
    }

    public function testLinkedFolderIsNotOfferedInTheTree(): void
    {
        if (!@symlink($this->outsideDirectory, $this->testDirectory . '/link')) {
            $this->markTestSkipped('cannot create a symlink here');
        }
        mkdir($this->testDirectory . '/real');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'storages';

        $responseJson = $this->getConnectorDefault()->handleRequest()->toStdClass();

        $names = array_column($responseJson->storages[0]->folders, 'name');
        $this->tester->assertContains('real', $names);
        $this->tester->assertNotContains('link', $names);
    }

    protected function _after()
    {
        $this->tester->emptyDirRecursive($this->outsideDirectory);
        parent::_after();
    }

    protected function _before()
    {
        parent::_before();
        if (!function_exists('symlink')) {
            $this->markTestSkipped('symlink() is not available');
        }
        $this->outsideDirectory = codecept_output_dir('outside');
        if (!is_dir($this->outsideDirectory)) {
            mkdir($this->outsideDirectory, 0o777, true);
        }
        file_put_contents($this->outsideDirectory . '/secret.txt', 'secret');
    }
}
