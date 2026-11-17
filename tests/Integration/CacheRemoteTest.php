<?php

declare(strict_types=1);

namespace Tests\Integration;

use Codeception\Test\Unit;
use Dosiero\Cache;
use Dosiero\File;
use Tests\Support\IntegrationTester;

class CacheRemoteTest extends Unit
{
    protected IntegrationTester $tester;

    private string $cacheDirectory;

    private string $dataDirectory;

    private string $rootDirectory;

    private string $workingDirectory;

    public function testDisabledCacheDoesNotSweepItsDirectory(): void
    {
        $entry = $this->cacheDirectory . '/' . sha1('x') . '.json';
        file_put_contents($entry, '{}');
        touch($entry, time() - 7201);

        (new Cache(false, $this->cacheDirectory))->cleanExpired();

        $this->tester->assertFileExists($entry);
    }

    public function testLocalModeStillWritesNextToTheData(): void
    {
        (new Cache())->save($this->dataDirectory, $this->files());

        $this->tester->assertFileExists($this->dataDirectory . '/' . Cache::FILE_NAME);
    }

    public function testRemoteModeExpiresByTtl(): void
    {
        $cache = new Cache(true, $this->cacheDirectory, 'ftp1', true);
        $cache->save('ftp://host:21/a', $this->files());
        touch(glob($this->cacheDirectory . '/*.json')[0], time() - 7201);

        $this->tester->assertNull($cache->load('ftp://host:21/a'));
    }

    public function testRemoteModeIgnoresMtimeOfLocalDirectoryWithTheSameName(): void
    {
        $cache = new Cache(true, $this->cacheDirectory, 'ftp1', true);
        $cache->save($this->dataDirectory, $this->files());
        // a local change that would invalidate the local mode
        touch($this->dataDirectory, time() + 60);

        $loaded = $cache->load($this->dataDirectory);

        $this->tester->assertNotNull($loaded);
        $this->tester->assertSame(['a.txt'], array_keys($loaded));
    }

    public function testRemoteModeLoadsWhatItSaved(): void
    {
        $cache = new Cache(true, $this->cacheDirectory, 'ftp1', true);
        $cache->save('ftp://host:21/a', $this->files());

        $loaded = $cache->load('ftp://host:21/a');

        $this->tester->assertNotNull($loaded);
        $this->tester->assertSame(5, $loaded['a.txt']->getSize());
        $this->tester->assertNull((new Cache(true, $this->cacheDirectory, 'ftp2', true))->load('ftp://host:21/a'));
    }

    public function testRemoteModeWithoutDirectoryWritesNothing(): void
    {
        $cache = new Cache(true, '', 'ftp1', true);
        $cache->save($this->dataDirectory, $this->files());

        $this->tester->assertFileDoesNotExist($this->dataDirectory . '/' . Cache::FILE_NAME);
        $this->tester->assertNull($cache->load($this->dataDirectory));
    }

    protected function _after()
    {
        chdir($this->workingDirectory);
        $this->tester->emptyDirRecursive($this->rootDirectory);
    }

    protected function _before()
    {
        $this->rootDirectory = codecept_data_dir('ftp-cache');
        $this->cacheDirectory = $this->rootDirectory . '/cache';
        $this->dataDirectory = $this->rootDirectory . '/data';
        if (!is_dir($this->rootDirectory)) {
            mkdir($this->rootDirectory, 0o777, true);
        }
        $this->tester->emptyDirRecursive($this->rootDirectory);
        mkdir($this->cacheDirectory);
        mkdir($this->dataDirectory);
        // a cache that lost its directory writes relative to the cwd, which is then this one
        $this->workingDirectory = (string)getcwd();
        chdir($this->rootDirectory);
    }

    /** @return array<string, File> */
    private function files(): array
    {
        $file = new File('a.txt', File::TYPE_FILE);
        $file->setSize(5);
        return ['a.txt' => $file];
    }
}
