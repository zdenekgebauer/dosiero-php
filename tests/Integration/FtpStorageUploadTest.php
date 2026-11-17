<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\Storage;
use Dosiero\StorageException;

class FtpStorageUploadTest extends FtpStorageBase
{
    public function testDoubleExtensionIsRefused(): void
    {
        $storage = $this->createStorage();
        $files = $this->uploadedFiles('phpunit.jpg=shell.php.jpg');

        $this->tester->expectThrowable(StorageException::class, static fn() => $storage->upload('', $files));
        $this->tester->assertSame([], glob($this->ftpDirectory . '/*'));
    }

    public function testFailedUploadIsReported(): void
    {
        $files = $this->uploadedFiles('phpunit.jpg');
        $files['file0']['error'] = UPLOAD_ERR_INI_SIZE;
        $storage = $this->createStorage();

        $this->tester->expectThrowable(
            new StorageException('upload "phpunit.jpg" failed', UPLOAD_ERR_INI_SIZE),
            static fn() => $storage->upload('', $files),
        );
    }

    public function testForbiddenExtensionIsRefused(): void
    {
        $storage = $this->createStorage();
        $files = $this->uploadedFiles('phpunit.jpg=script.php');

        $this->tester->expectThrowable(StorageException::class, static fn() => $storage->upload('', $files));
        $this->tester->assertSame([], glob($this->ftpDirectory . '/*'));
    }

    public function testInvalidNameWithoutNormalization(): void
    {
        $storage = $this->createStorage([Storage::OPTION_NORMALIZE_NAMES => false]);
        $files = $this->uploadedFiles('phpunit.jpg=a:b.jpg');

        $this->tester->expectThrowable(
            new StorageException('invalid file name "a:b.jpg"'),
            static fn() => $storage->upload('', $files),
        );
    }

    public function testMissingTargetDirectory(): void
    {
        $storage = $this->createStorage();
        $files = $this->uploadedFiles('phpunit.jpg');

        $this->tester->expectThrowable(
            new StorageException('cannot store uploaded file "phpunit.jpg"'),
            static fn() => $storage->upload('missing', $files),
        );
    }

    public function testNameIsKeptWithoutNormalization(): void
    {
        $this->createStorage([Storage::OPTION_NORMALIZE_NAMES => false])
            ->upload('', $this->uploadedFiles('phpunit.jpg=Žluťoučký kůň.jpg'));

        $this->tester->assertFileExists($this->ftpDirectory . '/Žluťoučký kůň.jpg');
    }

    public function testNameIsNormalized(): void
    {
        $this->createStorage()->upload('', $this->uploadedFiles('phpunit.jpg=Žluťoučký kůň.jpg'));

        $this->tester->assertFileExists($this->ftpDirectory . '/Zlutoucky-kun.jpg');
    }

    public function testNoOverwriteSkipsExistingAndStoresTheRest(): void
    {
        $this->writeFixture('phpunit.jpg', 'old');
        $storage = $this->createStorage([Storage::OPTION_OVERWRITE_FILES => false]);
        $files = $this->uploadedFiles('phpunit.jpg', 'phpunit.png');

        $this->tester->expectThrowable(
            new StorageException('files were not overwritten: phpunit.jpg'),
            static fn() => $storage->upload('', $files),
        );
        $this->tester->assertSame('old', file_get_contents($this->ftpDirectory . '/phpunit.jpg'));
        $this->tester->assertFileExists($this->ftpDirectory . '/phpunit.png');
    }

    public function testOverwrite(): void
    {
        $this->writeFixture('phpunit.jpg', 'old');

        $this->createStorage()->upload('', $this->uploadedFiles('phpunit.jpg'));

        $this->tester->assertFileEquals(codecept_data_dir('phpunit.jpg'), $this->ftpDirectory . '/phpunit.jpg');
    }

    public function testUploadIntoCachedSubfolder(): void
    {
        mkdir($this->ftpDirectory . '/a');
        $storage = $this->createStorage([
            Storage::OPTION_CACHE_DIRECTORY => $this->cacheDirectory,
            Storage::OPTION_MODE_FILE => 0o600,
        ]);
        $storage->getFiles('a');

        $storage->upload('a', $this->uploadedFiles('phpunit.jpg'));

        $this->tester->assertFileEquals(codecept_data_dir('phpunit.jpg'), $this->ftpDirectory . '/a/phpunit.jpg');
        $this->tester->assertSame(0o600, fileperms($this->ftpDirectory . '/a/phpunit.jpg') & 0o777);
        $files = $storage->getFiles('a');
        $this->tester->assertSame(['phpunit.jpg'], self::names($files));
        $this->tester->assertSame(94, $files['phpunit.jpg']->getWidth());
    }

    public function testUploadToRoot(): void
    {
        $this->createStorage()->upload('', $this->uploadedFiles('phpunit.jpg'));

        $this->tester->assertFileEquals(codecept_data_dir('phpunit.jpg'), $this->ftpDirectory . '/phpunit.jpg');
    }
}
