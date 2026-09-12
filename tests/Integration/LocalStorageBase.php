<?php

declare(strict_types=1);

namespace Tests\Integration;

use Codeception\Test\Unit;
use Dosiero\Config;
use Dosiero\Connector;
use Dosiero\Local\LocalStorage;
use Dosiero\Storage;
use Tests\Support\IntegrationTester;

class LocalStorageBase extends Unit
{
    protected const string STORAGE_NAME = 'local1';

    protected string $testDirectory;

    protected IntegrationTester $tester;

    protected string $testUrl = 'http://example.org/';

    protected function _after()
    {
        unset($_GET, $_POST, $_FILES, $_SERVER['HTTP_X_DOSIERO_PROTOCOL']);
        $this->tester->emptyDirRecursive($this->testDirectory);
    }

    protected function _before()
    {
        unset($_GET, $_POST, $_FILES);
        //  connector require this header
        $_SERVER['HTTP_X_DOSIERO_PROTOCOL'] = Connector::PROTOCOL_VERSION;
        $this->testDirectory = codecept_data_dir('local');
        if (!is_dir($this->testDirectory)) {
            mkdir($this->testDirectory, 0o777, true);
        }
        $this->tester->emptyDirRecursive($this->testDirectory);
    }

    protected function createConfig(): Config
    {
        $config = new Config();
        $config->allowAnonymous();
        return $config;
    }

    protected function getCached(string $file)
    {
        return json_decode(file_get_contents($file), true)['files'];
    }

    protected function getConnectorDefault(): Connector
    {
        $storage = $this->createStorage();

        $connector = new Connector($this->createConfig());
        $connector->addStorage($storage);
        return $connector;
    }

    protected function getConnectorNoOverwrite(): Connector
    {
        $storage = $this->createStorage();
        $storage->setOption(Storage::OPTION_OVERWRITE_FILES, false);

        $connector = new Connector($this->createConfig());
        $connector->addStorage($storage);
        return $connector;
    }

    protected function getConnectorReadOnly(): Connector
    {
        $storage = $this->createStorage();
        $storage->setOption(Storage::OPTION_READ_ONLY, true);

        $connector = new Connector($this->createConfig());
        $connector->addStorage($storage);
        return $connector;
    }

    private function createStorage(): LocalStorage
    {
        $storage = new LocalStorage(self::STORAGE_NAME);
        $storage->setOption(LocalStorage::OPTION_BASE_DIR, codecept_data_dir('local'));
        $storage->setOption(Storage::OPTION_MODE_DIRECTORY, 0o755);
        $storage->setOption(Storage::OPTION_MODE_FILE, 0o644);
        $storage->setOption(Storage::OPTION_BASE_URL, $this->testUrl);
        return $storage;
    }
}
