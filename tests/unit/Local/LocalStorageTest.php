<?php

declare(strict_types=1);

namespace Dosiero;

use Dosiero\Local\LocalStorage;

class LocalStorageTest extends \Codeception\Test\Unit
{

    /**
     * @var \UnitTester
     */
    protected $tester;

    public function testSetOptions(): void
    {
        $storage = new LocalStorage('name');
        $storage->setOption(LocalStorage::OPTION_BASE_DIR, __DIR__);
//        $storage->setOption(Storage::OPTION_BASE_URL, 'https://example.org');
//        $storage->setOption(LocalStorage::OPTION_THUMBNAIL_SIZE, 50);
//        $storage->setOption(LocalStorage::OPTION_MODE_FILE, 0664);
//        $storage->setOption(LocalStorage::OPTION_MODE_DIRECTORY, 0755);

        $this->tester->expectThrowable(
            new \InvalidArgumentException('directory "notexiststs" not found'),
            static function () use ($storage) {
                $storage->setOption(LocalStorage::OPTION_BASE_DIR, 'notexiststs');
            }
        );
    }
}
