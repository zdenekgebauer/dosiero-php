<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\Connector;
use Dosiero\Local\LocalStorage;
use Dosiero\Storage;

/**
 * Uploads must not be able to place something executable into a directory that is
 * served over HTTP - BASE_DIR normally lives under the web root because BASE_URL
 * has to be public.
 */
class LocalStorageUploadTypeTest extends LocalStorageBase
{
    /** @return array<string, array{string}> */
    public function providerRefusedNames(): array
    {
        return [
            'php' => ['shell.php'],
            'phtml' => ['shell.phtml'],
            'phar' => ['payload.phar'],
            'double extension' => ['invoice.php.jpg'],
            'htaccess' => ['.htaccess'],
            'dot file' => ['.env'],
            'no extension' => ['README'],
            'unknown type' => ['macro.docm'],
        ];
    }

    public function testAllowedExtensionsCanBeConfigured(): void
    {
        $storage = new LocalStorage(self::STORAGE_NAME);
        $storage->setOption(LocalStorage::OPTION_BASE_DIR, codecept_data_dir('local'));
        $storage->setOption(Storage::OPTION_BASE_URL, $this->testUrl);
        $storage->setOption(Storage::OPTION_ALLOWED_EXTENSIONS, 'txt');

        $connector = new Connector($this->createConfig());
        $connector->addStorage($storage);

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'upload';
        $_FILES = [$this->uploadField('photo.jpg')];

        $this->tester->assertNotSame('', $connector->handleRequest()->toStdClass()->msg);
        $this->tester->assertFileNotExists($this->testDirectory . '/photo.jpg');
    }

    public function testAllowedUploadStillWorks(): void
    {
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'upload';
        $_FILES = [$this->uploadField('photo.jpg')];

        $this->getConnectorDefault()->handleRequest();

        $this->tester->assertFileExists($this->testDirectory . '/photo.jpg');
    }

    public function testContentNotMatchingTheExtensionIsRefused(): void
    {
        $fake = codecept_data_dir('not-a-pdf.pdf');
        file_put_contents($fake, "MZ\x90\x00\x03");

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'upload';
        $_FILES = [
            [
                'name' => 'invoice.pdf',
                'type' => 'application/pdf',
                'size' => filesize($fake),
                'tmp_name' => $fake,
                'error' => 0,
            ],
        ];

        $response = $this->getConnectorDefault()->handleRequest()->toStdClass();

        $this->tester->assertNotSame('', $response->msg);
        $this->tester->assertFileNotExists($this->testDirectory . '/invoice.pdf');
        unlink($fake);
    }

    public function testExtensionMasqueradingAsImageIsRefused(): void
    {
        $notAnImage = codecept_data_dir('not-an-image.jpg');
        file_put_contents($notAnImage, '<?php echo "hello"; ?>');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'upload';
        $_FILES = [
            [
                'name' => 'photo.jpg',
                'type' => 'image/jpeg',
                'size' => filesize($notAnImage),
                'tmp_name' => $notAnImage,
                'error' => 0,
            ],
        ];

        $response = $this->getConnectorDefault()->handleRequest()->toStdClass();

        $this->tester->assertNotSame('', $response->msg);
        $this->tester->assertFileNotExists($this->testDirectory . '/photo.jpg');
        unlink($notAnImage);
    }

    public function testFileOverTheLimitIsRefused(): void
    {
        $storage = new LocalStorage(self::STORAGE_NAME);
        $storage->setOption(LocalStorage::OPTION_BASE_DIR, codecept_data_dir('local'));
        $storage->setOption(Storage::OPTION_BASE_URL, $this->testUrl);
        $storage->setOption(Storage::OPTION_MAX_FILE_SIZE, 10);

        $connector = new Connector($this->createConfig());
        $connector->addStorage($storage);

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'upload';
        $_FILES = [$this->uploadField('photo.jpg')];

        $response = $connector->handleRequest()->toStdClass();

        $this->tester->assertStringContainsString('larger than the allowed', $response->msg);
        $this->tester->assertFileNotExists($this->testDirectory . '/photo.jpg');
    }

    public function testLimitsAreReportedToTheClient(): void
    {
        $storage = new LocalStorage(self::STORAGE_NAME);
        $storage->setOption(LocalStorage::OPTION_BASE_DIR, codecept_data_dir('local'));
        $storage->setOption(Storage::OPTION_BASE_URL, $this->testUrl);
        $storage->setOption(Storage::OPTION_MAX_FILE_SIZE, 2048);
        $storage->setOption(Storage::OPTION_ALLOWED_EXTENSIONS, 'jpg,png');

        $connector = new Connector($this->createConfig());
        $connector->addStorage($storage);

        $_GET['action'] = 'storages';
        $described = $connector->handleRequest()->toStdClass()->storages[0];

        $this->tester->assertSame(2048, $described->max_file_size);
        $this->tester->assertSame(['jpg', 'png'], $described->allowed_extensions);
    }

    /** @dataProvider providerRefusedNames */
    public function testRefusedUpload(string $fileName): void
    {
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'upload';
        $_FILES = [$this->uploadField($fileName)];

        $connector = $this->getConnectorDefault();
        $response = $connector->handleRequest()->toStdClass();

        $this->tester->assertNotSame('', $response->msg, 'upload of "' . $fileName . '" must be refused');
        $this->tester->assertFileNotExists($this->testDirectory . '/' . $fileName);
    }

    /** @return array<string, mixed> */
    private function uploadField(string $fileName): array
    {
        return [
            'name' => $fileName,
            'type' => 'application/octet-stream',
            'size' => 100,
            'tmp_name' => codecept_data_dir('phpunit.jpg'),
            'error' => 0,
        ];
    }
}
