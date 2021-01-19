<?php

declare(strict_types=1);

namespace Dosiero;

class StorageTest extends \Codeception\Test\Unit
{

    /**
     * @var \UnitTester
     */
    protected $tester;

    public function testSetOptions(): void
    {
        $storage = new class('base') extends Storage {};
        $storage->setOption(Storage::OPTION_BASE_URL, 'https://example.org');
        $storage->setOption(Storage::OPTION_THUMBNAIL_SIZE, 50);
        $storage->setOption(Storage::OPTION_MODE_FILE, 0664);
        $storage->setOption(Storage::OPTION_MODE_DIRECTORY, 0755);
        $storage->setOption(Storage::OPTION_READ_ONLY, true);
        $storage->setOption(Storage::OPTION_OVERWRITE_FILES, true);
        $storage->setOption(Storage::OPTION_NORMALIZE_NAMES, true);

        $this->tester->expectThrowable(
            new \InvalidArgumentException('invalid option "invalid"'),
            static function () use ($storage) {
                $storage->setOption('invalid', 1);
            }
        );
    }
}



