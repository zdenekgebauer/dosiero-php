<?php

declare(strict_types=1);

namespace Tests\Unit;

use Dosiero\Storage;
use Tests\Support\UnitTester;

class StorageTest extends \Codeception\Test\Unit
{
    protected UnitTester $tester;

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
            static function () use ($storage) {
                $storage->setOption('invalid', 1);
            },
        );
    }
}
