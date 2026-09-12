<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\InvalidRequestException;

/**
 * A data directory can hold .htaccess, .gitignore, etc.
 */
class LocalStorageHiddenFilesTest extends LocalStorageBase
{
    /** @return array<string, array{string}> */
    public function providerHiddenNames(): array
    {
        return [
            'htaccess' => ['.htaccess'],
            'env' => ['.env'],
            'gitignore' => ['.gitignore'],
            'listing cache' => ['.htdircache'],
        ];
    }

    /** @dataProvider providerHiddenNames */
    public function testHiddenFileCannotBeDeleted(string $fileName): void
    {
        $fullPath = $this->testDirectory . '/' . $fileName;
        file_put_contents($fullPath, 'secret');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'delete';
        $_POST['files'] = [$fileName];

        $this->tester->expectThrowable(
            InvalidRequestException::class,
            fn() => $this->getConnectorDefault()->handleRequest(),
        );
        $this->tester->assertFileExists($fullPath);
    }

    public function testHiddenFileCannotBeRenamed(): void
    {
        $fullPath = $this->testDirectory . '/.htaccess';
        file_put_contents($fullPath, 'secret');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'rename';
        $_POST['old'] = '.htaccess';
        $_POST['new'] = 'htaccess.txt';

        $this->tester->expectThrowable(
            InvalidRequestException::class,
            fn() => $this->getConnectorDefault()->handleRequest(),
        );
        $this->tester->assertFileExists($fullPath);
    }

    /** @dataProvider providerHiddenNames */
    public function testHiddenFileIsNotListed(string $fileName): void
    {
        file_put_contents($this->testDirectory . '/' . $fileName, 'secret');
        file_put_contents($this->testDirectory . '/visible.txt', 'shown');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'files';

        $listed = array_column(
            (array)$this->getConnectorDefault()->handleRequest()->toStdClass()->files,
            'name',
        );

        $this->tester->assertContains('visible.txt', $listed);
        $this->tester->assertNotContains($fileName, $listed);
    }

    public function testHiddenFolderIsNotOfferedInTheTree(): void
    {
        mkdir($this->testDirectory . '/.hidden');
        mkdir($this->testDirectory . '/shown');

        $_GET['action'] = 'storages';

        $storage = $this->getConnectorDefault()->handleRequest()->toStdClass()->storages[0];
        $folders = array_column((array)$storage->folders, 'name');

        $this->tester->assertContains('shown', $folders);
        $this->tester->assertNotContains('.hidden', $folders);
    }

    public function testNothingHiddenCanBeCreatedEither(): void
    {
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'mkdir';
        $_POST['folder'] = '.hidden';

        $this->tester->expectThrowable(
            InvalidRequestException::class,
            fn() => $this->getConnectorDefault()->handleRequest(),
        );
        $this->tester->assertDirectoryDoesNotExist($this->testDirectory . '/.hidden');
    }
}
