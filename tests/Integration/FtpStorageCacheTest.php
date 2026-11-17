<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\Cache;
use Dosiero\Ftp\FtpConnection;
use Dosiero\Ftp\FtpStorage;
use Dosiero\Storage;
use Dosiero\StorageException;

class FtpStorageCacheTest extends FtpStorageBase
{
    public function testCorruptImageIsListedWithoutThumbnail(): void
    {
        copy(codecept_data_dir('invalid-image.png'), $this->ftpDirectory . '/broken.png');
        $this->writeFixture('a.txt');

        $files = $this->createStorage()->getFiles('');

        $this->tester->assertSame(['a.txt', 'broken.png'], self::names($files));
        $this->tester->assertNull($files['broken.png']->getWidth());
        $this->tester->assertNull($files['broken.png']->getHeight());
        $this->tester->assertNull($files['broken.png']->getThumbnail());
    }

    public function testDisabledCacheReadsTheServerEachTime(): void
    {
        $this->writeFixture('a.txt');
        $storage = $this->createStorage([
            Storage::OPTION_CACHE_DIRECTORY => $this->cacheDirectory,
            Storage::OPTION_CACHE_ENABLED => false,
        ]);
        $storage->getFiles('');
        $this->writeFixture('b.txt');

        $this->tester->assertSame(['a.txt', 'b.txt'], self::names($storage->getFiles('')));
        $this->tester->assertSame([], glob($this->cacheDirectory . '/*'));
    }

    public function testImageDimensionsAndThumbnail(): void
    {
        copy(codecept_data_dir('phpunit.jpg'), $this->ftpDirectory . '/photo.jpg');

        $file = $this->createStorage([Storage::OPTION_THUMBNAIL_SIZE => 20])->getFiles('')['photo.jpg'];

        $this->tester->assertSame(94, $file->getWidth());
        $this->tester->assertSame(80, $file->getHeight());
        $this->tester->assertStringStartsWith('data:image/jpg;base64,', (string)$file->getThumbnail());
        $thumbnail = imagecreatefromstring(base64_decode(substr((string)$file->getThumbnail(), 22)));
        $this->tester->assertSame(20, imagesx($thumbnail));
        $this->tester->assertSame([], glob(sys_get_temp_dir() . '/dosiero-ftp-*'));
    }

    public function testImageLargerThanTheUploadLimitIsNotDownloaded(): void
    {
        copy(codecept_data_dir('phpunit.jpg'), $this->ftpDirectory . '/photo.jpg');
        $storage = $this->createCountingStorage([Storage::OPTION_MAX_FILE_SIZE => 1000]);

        $file = $storage->getFiles('')['photo.jpg'];

        $this->tester->assertNull($file->getThumbnail());
        $this->tester->assertSame(0, $storage->downloads);
    }

    public function testImageWithinTheUploadLimitIsDownloaded(): void
    {
        copy(codecept_data_dir('phpunit.jpg'), $this->ftpDirectory . '/photo.jpg');
        $size = (int)filesize($this->ftpDirectory . '/photo.jpg');
        $storage = $this->createCountingStorage([Storage::OPTION_MAX_FILE_SIZE => $size]);

        $this->tester->assertNotNull($storage->getFiles('')['photo.jpg']->getThumbnail());
        $this->tester->assertSame(1, $storage->downloads);
    }

    public function testListingIsServedFromTheCache(): void
    {
        $this->writeFixture('a.txt');
        $storage = $this->createStorage([Storage::OPTION_CACHE_DIRECTORY => $this->cacheDirectory]);
        $storage->getFiles('');

        $this->writeFixture('added-elsewhere.txt');

        $this->tester->assertSame(['a.txt'], self::names($storage->getFiles('')));
        $this->tester->assertSame(['a.txt', 'added-elsewhere.txt'], self::names($storage->getFiles('', true)));
        $this->tester->assertSame(['a.txt', 'added-elsewhere.txt'], self::names($storage->getFiles('')));
    }

    public function testNoCacheDirectoryWritesNothingLocally(): void
    {
        $this->writeFixture('sub/a.txt');

        $storage = $this->createStorage();
        $storage->getFiles('');
        $storage->getFiles('sub');

        $this->tester->assertFileDoesNotExist($this->ftpDirectory . '/' . Cache::FILE_NAME);
        $this->tester->assertFileDoesNotExist($this->ftpDirectory . '/sub/' . Cache::FILE_NAME);
        $this->tester->assertFileDoesNotExist('/sub/' . Cache::FILE_NAME);
        $this->tester->assertSame([], glob($this->cacheDirectory . '/*'));
    }

    public function testReloadReusesThumbnailsOfUnchangedImages(): void
    {
        copy(codecept_data_dir('phpunit.jpg'), $this->ftpDirectory . '/photo.jpg');
        copy(codecept_data_dir('phpunit.png'), $this->ftpDirectory . '/changed.png');
        $storage = $this->createCountingStorage([Storage::OPTION_CACHE_DIRECTORY => $this->cacheDirectory]);
        $thumbnail = $storage->getFiles('')['photo.jpg']->getThumbnail();
        $this->tester->assertSame(2, $storage->downloads);

        copy(codecept_data_dir('phpunit.gif'), $this->ftpDirectory . '/changed.png');
        $files = $storage->getFiles('', true);

        $this->tester->assertSame(3, $storage->downloads);
        $this->tester->assertSame($thumbnail, $files['photo.jpg']->getThumbnail());
        $this->tester->assertSame(94, $files['photo.jpg']->getWidth());
        $this->tester->assertSame(80, $files['photo.jpg']->getHeight());
    }

    public function testSameNameOfAnotherServerHasItsOwnCacheEntry(): void
    {
        $this->writeFixture('a.txt');
        $this->createStorage([Storage::OPTION_CACHE_DIRECTORY => $this->cacheDirectory])->getFiles('');

        $files = $this->createStorage([
            Storage::OPTION_CACHE_DIRECTORY => $this->cacheDirectory,
            FtpStorage::OPTION_HOST => 'localhost',
        ])->getFiles('');

        $this->tester->assertSame(['a.txt'], self::names($files));
        $this->tester->assertCount(2, glob($this->cacheDirectory . '/*.json'));
    }

    public function testUsersOfOneServerHaveTheirOwnCacheEntries(): void
    {
        $this->writeFixture('a.txt');
        $this->createStorage([Storage::OPTION_CACHE_DIRECTORY => $this->cacheDirectory])->getFiles('');

        $other = $this->createStorage([
            Storage::OPTION_CACHE_DIRECTORY => $this->cacheDirectory,
            FtpStorage::OPTION_LOGIN => 'someone-else',
        ]);

        // a listing served from the first user's cache entry would need no login at all
        $this->tester->expectThrowable(
            new StorageException('ftp login as "someone-else" failed'),
            static fn() => $other->getFiles(''),
        );
    }

    /** @param array<string, bool|int|string> $options */
    private function createCountingStorage(array $options): object
    {
        $storage = new class(self::STORAGE_NAME) extends FtpStorage {
            public int $downloads = 0;

            protected function createConnection(): FtpConnection
            {
                $connection = parent::createConnection();
                $storage = $this;
                return new class($connection, $storage) extends FtpConnection {
                    public function __construct(FtpConnection $settings, private readonly object $storage)
                    {
                        parent::__construct(
                            $settings->host,
                            $settings->port,
                            $settings->login,
                            (string)getenv('DOSIERO_FTP_PASSWORD') ?: 'dosiero',
                            $settings->ssl,
                            $settings->passive,
                            $settings->timeout,
                        );
                    }

                    public function download(string $remote, string $local): bool
                    {
                        $this->storage->downloads++;
                        return parent::download($remote, $local);
                    }
                };
            }
        };
        return $this->createStorage($options, $storage);
    }
}
