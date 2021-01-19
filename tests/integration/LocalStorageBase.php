<?php

declare(strict_types=1);

namespace Dosiero;

use Dosiero\Local\LocalStorage;

class LocalStorageBase extends \Codeception\Test\Unit
{

    /**
     * @var \IntegrationTester
     */
    protected $tester;

    protected const STORAGE_NAME = 'local1';

    /**
     * @var string
     */
    protected $testDirectory;

    /**
     * @var string
     */
    protected $testUrl = 'http://example.org/';

    protected function _before()
    {
        unset($_GET, $_POST, $_FILES);
        $this->testDirectory = codecept_data_dir('local');
        $this->tester->emptyDirRecursive($this->testDirectory);
    }

    protected function _after()
    {
        unset($_GET, $_POST, $_FILES);
        $this->tester->emptyDirRecursive($this->testDirectory);
    }

    protected function getConnectorDefault(): Connector
    {
        $storage = $this->createStorage();

        $connector = new Connector(new Config());
        $connector->addStorage($storage);
        return $connector;
    }

    protected function getConnectorReadOnly(): Connector
    {
        $storage = $this->createStorage();
        $storage->setOption(Storage::OPTION_READ_ONLY, true);

        $connector = new Connector(new Config());
        $connector->addStorage($storage);
        return $connector;
    }

    protected function getConnectorNoOverwrite(): Connector
    {
        $storage = $this->createStorage();
        $storage->setOption(Storage::OPTION_OVERWRITE_FILES, false);

        $connector = new Connector(new Config());
        $connector->addStorage($storage);
        return $connector;
    }

    private function createStorage(): LocalStorage
    {
        $storage = new LocalStorage(self::STORAGE_NAME);
        $storage->setOption(LocalStorage::OPTION_BASE_DIR, codecept_data_dir('local'));
        $storage->setOption(Storage::OPTION_MODE_DIRECTORY, 0755);
        $storage->setOption(Storage::OPTION_MODE_FILE, 0644);
        $storage->setOption(Storage::OPTION_BASE_URL, $this->testUrl);
        return $storage;
    }

    protected function getCached(string $file)
    {
        return json_decode(file_get_contents($file), true);
    }
}
