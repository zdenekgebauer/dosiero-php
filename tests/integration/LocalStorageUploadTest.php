<?php

declare(strict_types=1);

namespace Dosiero;

class LocalStorageUploadTest extends LocalStorageBase
{
    public function testUpload(): void
    {
        $fileName = 'phpunit.jpg';
        $tempFile = sys_get_temp_dir() . '/' . $fileName;
        copy(codecept_data_dir() . '/phpunit.jpg', $tempFile);
        $filesize = filesize($tempFile);

        $_FILES = [
            'files' => [
                'name' => $fileName,
                'type' => 'image/jpeg',
                'tmp_name' => $tempFile,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tempFile),
            ],
        ];

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'upload';

        $response = $this->getConnectorDefault()->handleRequest();
        $responseJson = $response->toStdClass();

        $this->tester->assertEmpty($responseJson->msg);

        $files = $responseJson->files;
        $itemFile = array_filter(
            $files,
            static function (array $file) use ($fileName) {
                return $file['name'] === $fileName;
            }
        );
        $itemFile = reset($itemFile);
        $this->tester->assertEquals('file', $itemFile['type']);
        $this->tester->assertEquals($this->testUrl . $fileName, $itemFile['url']);
        $this->tester->assertEquals($filesize, $itemFile['size']);

        $this->tester->assertFalse(property_exists($responseJson, 'storages'));

        $this->tester->assertFileExists($this->testDirectory . '/' . $fileName);
    }

    public function testUploadNoOverwrite(): void
    {
        $fileName = 'phpunit.jpg';
        $tempFile = sys_get_temp_dir() . '/' . $fileName;
        copy(codecept_data_dir() . '/phpunit.jpg', $tempFile);
        file_put_contents($this->testDirectory. '/'. $fileName, '');

        $_FILES = [
            'files' => [
                'name' => $fileName,
                'type' => 'image/jpeg',
                'tmp_name' => $tempFile,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tempFile),
            ],
        ];

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'upload';

        //$connector = $this->getConnectorNoOverwrite();

//
//        $this->tester->expectThrowable(
////            new StorageException('files were not overwritten: ' . $fileName ),
//            new \Exception('files were not overwritten: ' . $fileName ),
//            static function () use ($connector) {
//                $connector->handleRequest();
//            }
//        );
        $response = $this->getConnectorNoOverwrite()->handleRequest();
        $responseJson = $response->toStdClass();
        $this->tester->assertEquals('files were not overwritten: ' . $fileName, $responseJson->msg);

        $this->tester->assertFileExists($this->testDirectory . '/' . $fileName);
        $this->tester->assertEquals(0, filesize($this->testDirectory . '/' . $fileName));
    }

    public function testUploadFail(): void
    {
        $fileName = 'file1.txt';

        $_FILES = [
            'files' => [
                'name' => $fileName,
                'type' => 'text/plain',
                'tmp_name' => 'temp',
                'error' => UPLOAD_ERR_INI_SIZE,
                'size' => 12345678,
            ],
        ];

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'upload';

        $response = $this->getConnectorDefault()->handleRequest();
        $responseJson = $response->toStdClass();

        $this->tester->assertEquals('upload "' . $fileName . '" failed', $responseJson->msg);

        $this->tester->assertFalse(property_exists($responseJson, 'storages'));

        $this->tester->assertFileNotExists($this->testDirectory . '/' . $fileName);
    }


    public function testUploadReadOnlyStorage(): void
    {
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'upload';

        $connector = $this->getConnectorReadOnly();

        $this->tester->expectThrowable(
            new AccessForbiddenException('storage "local1" is read only'),
            static function () use ($connector) {
                $connector->handleRequest();
            }
        );
    }
}

function move_uploaded_file(string $filename, string $destination): bool
{
    return rename($filename, $destination);
}
