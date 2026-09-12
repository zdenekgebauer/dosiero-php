<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\AccessForbiddenException;
use Dosiero\Connector;

class ConnectorCsrfTest extends LocalStorageBase
{
    // an empty value is as good as no header at all
    public function testEmptyProtocolHeaderIsRefused(): void
    {
        $_SERVER['HTTP_X_DOSIERO_PROTOCOL'] = '';
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'files';

        $connector = $this->getConnectorDefault();
        $this->tester->expectThrowable(
            AccessForbiddenException::class,
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );
    }

    // the header name the client sends and the $_SERVER key the connector reads must stay in sync
    public function testHeaderNameMatchesTheServerKey(): void
    {
        $this->tester->assertSame(
            'HTTP_X_DOSIERO_PROTOCOL',
            'HTTP_' . str_replace('-', '_', strtoupper(Connector::PROTOCOL_HEADER)),
        );
    }

    public function testRequestWithoutProtocolHeaderIsRefused(): void
    {
        file_put_contents($this->testDirectory . '/file1.txt', '');

        unset($_SERVER['HTTP_X_DOSIERO_PROTOCOL']);
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'delete';
        $_POST['files'] = ['file1.txt'];

        $connector = $this->getConnectorDefault();
        $this->tester->expectThrowable(
            AccessForbiddenException::class,
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );
        $this->tester->assertFileExists($this->testDirectory . '/file1.txt');
    }

    public function testRequestWithProtocolHeaderIsAccepted(): void
    {
        file_put_contents($this->testDirectory . '/file1.txt', '');

        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'delete';
        $_POST['files'] = ['file1.txt'];

        $responseJson = $this->getConnectorDefault()->handleRequest()->toStdClass();

        $this->tester->assertEmpty($responseJson->msg);
        $this->tester->assertFileNotExists($this->testDirectory . '/file1.txt');
    }
}
