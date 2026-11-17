<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Dosiero\Storage;
use Dosiero\Utils;
use Tests\Support\UnitTester;

class StorageTest extends Unit
{
    protected UnitTester $tester;

    public function testAllowedExtensionsAreParsedFromAList(): void
    {
        $storage = new class('base') extends Storage {};

        $storage->setOption(Storage::OPTION_ALLOWED_EXTENSIONS, ' JPG, ,png ,');

        $this->tester->assertSame(['jpg', 'png'], $storage->getAllowedExtensions());
    }

    public function testCacheDirectoryHasToExist(): void
    {
        $storage = new class('base') extends Storage {};

        $storage->setOption(Storage::OPTION_CACHE_DIRECTORY, '');
        $storage->setOption(Storage::OPTION_CACHE_DIRECTORY, sys_get_temp_dir());

        $this->tester->expectThrowable(
            new \InvalidArgumentException('directory "/no/such/directory" not found'),
            static function () use ($storage): void {
                $storage->setOption(Storage::OPTION_CACHE_DIRECTORY, '/no/such/directory');
            },
        );
    }

    public function testFlagsAcceptIntegers(): void
    {
        $storage = new class('base') extends Storage {};

        $storage->setOption(Storage::OPTION_CACHE_ENABLED, 0);
        $storage->setOption(Storage::OPTION_NORMALIZE_NAMES, 0);
        $storage->setOption(Storage::OPTION_READ_ONLY, 1);

        $this->tester->assertTrue($storage->isReadOnly());
    }

    public function testMaxFileSizeDefaultsToFiveMegabytes(): void
    {
        $storage = new class('base') extends Storage {};

        $iniLimit = min(Utils::iniBytes('upload_max_filesize'), Utils::iniBytes('post_max_size'));
        $this->tester->assertSame(min(5 * 1024 * 1024, $iniLimit), $storage->getMaxFileSize());
    }

    public function testMaxFileSizeIsConfigurable(): void
    {
        $storage = new class('base') extends Storage {};

        $storage->setOption(Storage::OPTION_MAX_FILE_SIZE, 1024);

        $this->tester->assertSame(1024, $storage->getMaxFileSize());
    }

    public function testMaxFileSizeNeverExceedsTheIniCeiling(): void
    {
        $storage = new class('base') extends Storage {};
        $iniLimit = min(Utils::iniBytes('upload_max_filesize'), Utils::iniBytes('post_max_size'));

        $storage->setOption(Storage::OPTION_MAX_FILE_SIZE, $iniLimit * 10);

        $this->tester->assertSame($iniLimit, $storage->getMaxFileSize());
    }

    public function testSetOptions(): void
    {
        $storage = new class('base') extends Storage {};
        $storage->setOption(Storage::OPTION_BASE_URL, 'https://example.org');
        $storage->setOption(Storage::OPTION_THUMBNAIL_SIZE, 50);
        $storage->setOption(Storage::OPTION_MODE_FILE, 0o664);
        $storage->setOption(Storage::OPTION_MODE_DIRECTORY, 0o755);
        $storage->setOption(Storage::OPTION_READ_ONLY, true);
        $storage->setOption(Storage::OPTION_OVERWRITE_FILES, true);
        $storage->setOption(Storage::OPTION_NORMALIZE_NAMES, true);

        $this->tester->expectThrowable(
            new \InvalidArgumentException('invalid option "invalid"'),
            static function () use ($storage): void {
                $storage->setOption('invalid', 1);
            },
        );
    }

    // setOption() takes the size as a string as well, so it must end up an int
    public function testThumbnailSizeHasAFloor(): void
    {
        $storage = new class('base') extends Storage {
            public function thumbnailSize(): int
            {
                return $this->thumbnailSize;
            }
        };

        foreach ([[5, 10], [9, 10], [10, 10], [11, 11], ['80', 80]] as [$value, $expected]) {
            $storage->setOption(Storage::OPTION_THUMBNAIL_SIZE, $value);
            $this->tester->assertSame($expected, $storage->thumbnailSize());
        }
    }
}
