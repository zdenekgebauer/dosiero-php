<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\InvalidRequestException;
use Dosiero\Utils;

class ConnectorRequestBodyTest extends LocalStorageBase
{
    public function testOversizedBodyIsReported(): void
    {
        $postMaxSize = Utils::iniBytes('post_max_size');
        $this->tester->assertGreaterThan(0, $postMaxSize, 'post_max_size has to be set for this test');

        // when the body exceeds post_max_size
        $_POST = [];
        $_FILES = [];
        $_SERVER['CONTENT_LENGTH'] = (string)($postMaxSize + 1);
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'upload';

        $connector = $this->getConnectorDefault();
        $this->tester->expectThrowable(
            InvalidRequestException::class,
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );
    }

    public function testUploadWithinLimitIsNotReported(): void
    {
        $fileName = 'phpunit.jpg';
        $tempFile = sys_get_temp_dir() . '/' . $fileName;
        copy(codecept_data_dir($fileName), $tempFile);

        $_SERVER['CONTENT_LENGTH'] = (string)filesize($tempFile);
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'upload';
        $_FILES = [
            'files' => [
                'name' => $fileName,
                'type' => 'image/jpeg',
                'tmp_name' => $tempFile,
                'error' => 0,
                'size' => (int)filesize($tempFile),
            ],
        ];

        $responseJson = $this->getConnectorDefault()->handleRequest()->toStdClass();

        $this->tester->assertEmpty($responseJson->msg);
        $this->tester->assertFileExists($this->testDirectory . '/' . $fileName);
    }

    protected function _after()
    {
        unset($_SERVER['CONTENT_LENGTH']);
        parent::_after();
    }
}
