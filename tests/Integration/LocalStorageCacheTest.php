<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\Cache;
use Dosiero\Connector;
use Dosiero\Local\LocalStorage;
use Dosiero\Storage;

class LocalStorageCacheTest extends LocalStorageBase
{
    private string $cacheDirectory;

    public function testCacheDirectoryKeepsDataDirectoryClean(): void
    {
        file_put_contents($this->testDirectory . '/file.txt', '');

        $this->listFiles($this->getConnector([Storage::OPTION_CACHE_DIRECTORY => $this->cacheDirectory]));

        $this->tester->assertFileDoesNotExist($this->testDirectory . '/' . Cache::FILE_NAME);
        $this->tester->assertCount(1, glob($this->cacheDirectory . '/*.json'));
    }

    public function testCacheIsReusedAndInvalidatedByForeignChange(): void
    {
        file_put_contents($this->testDirectory . '/file.txt', '');
        $connector = $this->getConnector([Storage::OPTION_CACHE_DIRECTORY => $this->cacheDirectory]);

        $this->tester->assertCount(1, $this->listFiles($connector));

        // editing the cache file leaves the directory mtime alone, so a listing
        // that reports the ghost proves it came from the cache
        $cacheFile = glob($this->cacheDirectory . '/*.json')[0];
        $cache = json_decode(file_get_contents($cacheFile), true);
        $cache['files']['ghost.txt'] = $cache['files']['file.txt'];
        $cache['files']['ghost.txt']['name'] = 'ghost.txt';
        file_put_contents($cacheFile, json_encode($cache));

        $this->tester->assertCount(2, $this->listFiles($connector));

        // a change made by someone else, without going through Dosiero
        sleep(1);
        file_put_contents($this->testDirectory . '/other.txt', '');

        $files = $this->listFiles($connector);
        $this->tester->assertCount(2, $files);
        $this->tester->assertEquals(['file.txt', 'other.txt'], array_column($files, 'name'));
    }

    public function testDisabledCacheWritesNothing(): void
    {
        file_put_contents($this->testDirectory . '/file.txt', '');

        $files = $this->listFiles($this->getConnector([Storage::OPTION_CACHE_ENABLED => false]));

        $this->tester->assertCount(1, $files);
        $this->tester->assertFileDoesNotExist($this->testDirectory . '/' . Cache::FILE_NAME);
    }

    public function testExpiredEntriesAreRemoved(): void
    {
        $expired = $this->cacheDirectory . '/' . sha1('expired') . '.json';
        $fresh = $this->cacheDirectory . '/' . sha1('fresh') . '.json';
        file_put_contents($expired, '{}');
        file_put_contents($fresh, '{}');
        touch($expired, time() - 7201);

        (new Cache(true, $this->cacheDirectory))->cleanExpired();

        $this->tester->assertFileDoesNotExist($expired);
        $this->tester->assertFileExists($fresh);
    }

    public function testOnlyOwnEntriesAreRemoved(): void
    {
        $foreign = $this->cacheDirectory . '/unrelated.txt';
        $foreignJson = $this->cacheDirectory . '/settings.json';
        $almostOwn = $this->cacheDirectory . '/' . sha1('own') . '.json.bak';
        $own = $this->cacheDirectory . '/' . sha1('own') . '.json';
        foreach ([$foreign, $foreignJson, $almostOwn, $own] as $file) {
            file_put_contents($file, '{}');
            touch($file, time() - 7201);
        }

        (new Cache(true, $this->cacheDirectory))->cleanExpired();

        $this->tester->assertFileExists($foreign);
        $this->tester->assertFileExists($foreignJson);
        $this->tester->assertFileExists($almostOwn);
        $this->tester->assertFileDoesNotExist($own);
    }

    public function testSimilarPathsDoNotShareCacheFile(): void
    {
        mkdir($this->testDirectory . '/a/b', 0o777, true);
        mkdir($this->testDirectory . '/a_b', 0o777, true);
        file_put_contents($this->testDirectory . '/a/b/inner.txt', '');
        $connector = $this->getConnector([Storage::OPTION_CACHE_DIRECTORY => $this->cacheDirectory]);

        $this->tester->assertCount(1, $this->listFiles($connector, 'a/b'));
        $this->tester->assertCount(0, $this->listFiles($connector, 'a_b'));
        $this->tester->assertCount(2, glob($this->cacheDirectory . '/*.json'));
    }

    public function testUnwritableDirectoryIsNotFatal(): void
    {
        file_put_contents($this->testDirectory . '/file.txt', '');
        chmod($this->testDirectory, 0o555);

        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            $files = $this->listFiles($this->getConnectorDefault());
        } finally {
            restore_error_handler();
            chmod($this->testDirectory, 0o777);
        }

        $this->tester->assertEmpty($warnings);
        $this->tester->assertCount(1, $files);
        $this->tester->assertFileDoesNotExist($this->testDirectory . '/' . Cache::FILE_NAME);
    }

    protected function _after()
    {
        $this->tester->emptyDirRecursive($this->cacheDirectory);
        parent::_after();
    }

    protected function _before()
    {
        parent::_before();
        $this->cacheDirectory = codecept_output_dir('cache');
        if (!is_dir($this->cacheDirectory)) {
            mkdir($this->cacheDirectory, 0o777, true);
        }
        $this->tester->emptyDirRecursive($this->cacheDirectory);
    }

    /** @param array<string, bool|int|string> $options */
    private function getConnector(array $options): Connector
    {
        $storage = new LocalStorage(self::STORAGE_NAME);
        $storage->setOption(LocalStorage::OPTION_BASE_DIR, codecept_data_dir('local'));
        $storage->setOption(Storage::OPTION_BASE_URL, $this->testUrl);
        foreach ($options as $name => $value) {
            $storage->setOption($name, $value);
        }

        $connector = new Connector($this->createConfig());
        $connector->addStorage($storage);
        return $connector;
    }

    /** @return array<int, array<string, mixed>> */
    private function listFiles(Connector $connector, string $path = ''): array
    {
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'files';
        $_GET['path'] = $path;

        return $connector->handleRequest()->toStdClass()->files;
    }
}
