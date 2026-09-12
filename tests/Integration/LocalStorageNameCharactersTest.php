<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\Connector;
use Dosiero\InvalidRequestException;
use Dosiero\Local\LocalStorage;
use Dosiero\Storage;

/**
 * Names that an operating system refuses, or that would break the resulting URL,
 * must never reach the file system - either normalized away or refused outright.
 */
class LocalStorageNameCharactersTest extends LocalStorageBase
{
    /** @return array<string, array{string}> */
    public function providerHostileNewNames(): array
    {
        return [
            'colon' => ['faktura:2026.txt'],
            'asterisk' => ['a*b.txt'],
            'question mark' => ['a?b.txt'],
            'pipe' => ['a|b.txt'],
            'trailing dot' => ['name.'],
            'reserved device name' => ['CON.txt'],
        ];
    }

    /**
     * An item that is already on disk under a name this connector would not create
     * still has to be removable.
     */
    public function testExistingFileWithHostileNameCanBeDeleted(): void
    {
        $hostile = 'legacy name.txt';
        file_put_contents($this->testDirectory . '/' . $hostile, '');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'delete';
        $_POST['files'] = [$hostile];

        $this->getConnectorDefault()->handleRequest();

        $this->tester->assertFileNotExists($this->testDirectory . '/' . $hostile);
    }

    /** @dataProvider providerHostileNewNames */
    public function testMkDirWithHostileNameIsRefused(string $newName): void
    {
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'mkdir';
        $_POST['folder'] = $newName;

        $connector = $this->getConnectorDefault();

        $this->tester->expectThrowable(
            InvalidRequestException::class,
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );
    }

    /** @dataProvider providerHostileNewNames */
    public function testRenameToHostileNameIsRefused(string $newName): void
    {
        file_put_contents($this->testDirectory . '/source.txt', '');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'rename';
        $_POST['old'] = 'source.txt';
        $_POST['new'] = $newName;

        $connector = $this->getConnectorDefault();

        $this->tester->expectThrowable(
            InvalidRequestException::class,
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );

        $this->tester->assertFileExists($this->testDirectory . '/source.txt');
    }

    public function testRenameToNameWithDiacriticsIsAllowed(): void
    {
        file_put_contents($this->testDirectory . '/source.txt', '');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'rename';
        $_POST['old'] = 'source.txt';
        $_POST['new'] = 'žluťoučký kůň.txt';

        $this->getConnectorDefault()->handleRequest();

        $this->tester->assertFileExists($this->testDirectory . '/žluťoučký kůň.txt');
    }

    public function testUploadNormalizesHostileCharacters(): void
    {
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'upload';
        $_FILES = [
            [
                'name' => 'faktura:2026 *nová*.jpg',
                'type' => 'image/jpeg',
                'size' => 100,
                'tmp_name' => codecept_data_dir('phpunit.jpg'),
                'error' => 0,
            ],
        ];

        $this->getConnectorDefault()->handleRequest();

        $this->tester->assertFileExists($this->testDirectory . '/faktura-2026-nova.jpg');
    }

    public function testUploadRefusesHiddenName(): void
    {
        $storage = new LocalStorage(self::STORAGE_NAME);
        $storage->setOption(LocalStorage::OPTION_BASE_DIR, codecept_data_dir('local'));
        $storage->setOption(Storage::OPTION_BASE_URL, $this->testUrl);
        $storage->setOption(Storage::OPTION_NORMALIZE_NAMES, false);

        $connector = new Connector($this->createConfig());
        $connector->addStorage($storage);

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'upload';
        $_FILES = [
            [
                'name' => '.htaccess',
                'type' => 'text/plain',
                'size' => 100,
                'tmp_name' => codecept_data_dir('phpunit.jpg'),
                'error' => 0,
            ],
        ];

        $this->tester->assertNotSame('', $connector->handleRequest()->toStdClass()->msg);
        $this->tester->assertFileNotExists($this->testDirectory . '/.htaccess');
    }

    public function testUploadWithoutNormalizationRefusesHostileCharacters(): void
    {
        $storage = new LocalStorage(self::STORAGE_NAME);
        $storage->setOption(LocalStorage::OPTION_BASE_DIR, codecept_data_dir('local'));
        $storage->setOption(Storage::OPTION_BASE_URL, $this->testUrl);
        $storage->setOption(Storage::OPTION_NORMALIZE_NAMES, false);

        $connector = new Connector($this->createConfig());
        $connector->addStorage($storage);

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'upload';
        $_FILES = [
            [
                'name' => 'faktura:2026.jpg',
                'type' => 'image/jpeg',
                'size' => 100,
                'tmp_name' => codecept_data_dir('phpunit.jpg'),
                'error' => 0,
            ],
        ];

        $this->tester->assertNotSame('', $connector->handleRequest()->toStdClass()->msg);
        $this->tester->assertFileNotExists($this->testDirectory . '/faktura:2026.jpg');
    }
}
