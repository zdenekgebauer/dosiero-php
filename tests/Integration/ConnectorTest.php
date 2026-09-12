<?php

declare(strict_types=1);

namespace Tests\Integration;

use Codeception\Test\Unit;
use Dosiero\Config;
use Dosiero\Connector;
use Dosiero\InvalidRequestException;
use Tests\Support\IntegrationTester;

class ConnectorTest extends Unit
{
    protected IntegrationTester $tester;

    public function testGetInvalidStorage(): void
    {
        $connector = new Connector($this->createConfig());
        $_GET['storage'] = 'invalid';
        $_GET['action'] = 'files';

        $this->tester->expectThrowable(
            new InvalidRequestException('not found storage "invalid"'),
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );
    }

    public function testGetStorages(): void
    {
        $connector = new Connector($this->createConfig());
        $_GET['action'] = 'storages';
        $response = $connector->handleRequest();
        $responseJson = $response->toStdClass();
        $this->tester->assertEmpty($responseJson->msg);
        $this->tester->assertFalse(property_exists($responseJson, 'storages'));
    }

    public function testMissingAction(): void
    {
        $connector = new Connector($this->createConfig());
        $response = $connector->handleRequest();
        $responseJson = $response->toStdClass();
        $this->tester->assertEquals('missing or invalid parameter "action"', $responseJson->msg);
    }

    protected function _after()
    {
        unset($_GET, $_POST, $_SERVER['HTTP_X_DOSIERO_PROTOCOL']);
    }

    protected function _before()
    {
        unset($_GET, $_POST);
        $_SERVER['HTTP_X_DOSIERO_PROTOCOL'] = Connector::PROTOCOL_VERSION;
    }

    private function createConfig(): Config
    {
        $config = new Config();
        $config->allowAnonymous();
        return $config;
    }

}
