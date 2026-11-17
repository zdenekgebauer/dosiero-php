<?php

declare(strict_types=1);

namespace Tests\Integration;

use Codeception\Test\Unit;
use Dosiero\Connector;
use Dosiero\FileInterface;
use Dosiero\FolderInterface;
use Dosiero\Ftp\FtpConnection;
use Dosiero\Ftp\FtpStorage;
use Dosiero\Storage;
use Dosiero\StorageInterface;
use Tests\Support\IntegrationTester;

/**
 * Needs the daemon from build/ftp-daemon.sh, whose root is tests/Support/Data/ftp: fixtures are
 * written there through the filesystem and read back over ftp, and the other way round.
 *
 * @phpstan-import-type UploadedFile from StorageInterface
 */
class FtpStorageBase extends Unit
{
    protected const string STORAGE_NAME = 'ftp1';

    protected string $cacheDirectory;

    protected string $ftpDirectory;

    protected IntegrationTester $tester;

    protected string $testUrl = 'http://example.org/';

    protected function _after()
    {
        unset($_GET, $_POST, $_FILES, $_SERVER['HTTP_X_DOSIERO_PROTOCOL']);
        $this->tester->emptyDirRecursive($this->ftpDirectory);
        $this->tester->emptyDirRecursive($this->cacheDirectory);
    }

    protected function _before()
    {
        unset($_GET, $_POST, $_FILES);
        $_SERVER['HTTP_X_DOSIERO_PROTOCOL'] = Connector::PROTOCOL_VERSION;
        $this->ftpDirectory = rtrim(codecept_data_dir('ftp'), '/');
        $this->cacheDirectory = rtrim(codecept_data_dir('ftp-cache'), '/');
        foreach ([$this->ftpDirectory, $this->cacheDirectory] as $directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0o777, true);
            }
            $this->tester->emptyDirRecursive($directory);
        }
    }

    protected function createConnection(bool $ssl = false, string $password = ''): FtpConnection
    {
        return new FtpConnection(
            self::env('HOST', '127.0.0.1'),
            (int)self::env('PORT', '2121'),
            self::env('LOGIN', 'dosiero'),
            $password === '' ? self::env('PASSWORD', 'dosiero') : $password,
            $ssl,
            true,
            10,
        );
    }

    /** @param array<string, bool|int|string> $options applied after the defaults */
    protected function createStorage(array $options = [], ?FtpStorage $storage = null): FtpStorage
    {
        $storage ??= new FtpStorage(self::STORAGE_NAME);
        $storage->setOption(FtpStorage::OPTION_HOST, self::env('HOST', '127.0.0.1'));
        $storage->setOption(FtpStorage::OPTION_PORT, (int)self::env('PORT', '2121'));
        $storage->setOption(FtpStorage::OPTION_LOGIN, self::env('LOGIN', 'dosiero'));
        $storage->setOption(FtpStorage::OPTION_PASSWORD, self::env('PASSWORD', 'dosiero'));
        $storage->setOption(FtpStorage::OPTION_TIMEOUT, 10);
        $storage->setOption(Storage::OPTION_BASE_URL, $this->testUrl);
        $storage->setOption(Storage::OPTION_MODE_DIRECTORY, 0o755);
        $storage->setOption(Storage::OPTION_MODE_FILE, 0o644);
        foreach ($options as $name => $value) {
            $storage->setOption($name, $value);
        }
        return $storage;
    }

    protected static function env(string $name, string $default): string
    {
        $value = getenv('DOSIERO_FTP_' . $name);
        return $value === false || $value === '' ? $default : $value;
    }

    /**
     * @param iterable<FileInterface|FolderInterface> $items
     * @return array<string>
     */
    protected static function names(iterable $items): array
    {
        $names = [];
        foreach ($items as $item) {
            $names[] = $item->getName();
        }
        return $names;
    }

    /** @return array<string, UploadedFile> */
    protected function uploadedFiles(string ...$fixtures): array
    {
        $files = [];
        foreach ($fixtures as $index => $fixture) {
            [$source, $name] = str_contains($fixture, '=') ? explode('=', $fixture, 2) : [$fixture, $fixture];
            $tmpName = (string)tempnam(sys_get_temp_dir(), 'upload');
            copy(codecept_data_dir($source), $tmpName);
            $files['file' . $index] = [
                'error' => UPLOAD_ERR_OK,
                'name' => $name,
                'size' => (int)filesize($tmpName),
                'tmp_name' => $tmpName,
                'type' => '',
            ];
        }
        return $files;
    }

    protected function writeFixture(string $path, string $content = 'content'): void
    {
        $fullPath = $this->ftpDirectory . '/' . $path;
        if (!is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0o777, true);
        }
        file_put_contents($fullPath, $content);
    }
}
