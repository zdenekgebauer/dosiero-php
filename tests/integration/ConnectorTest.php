<?php

declare(strict_types=1);

namespace Dosiero;

class ConnectorTest extends \Codeception\Test\Unit
{

    /**
     * @var \IntegrationTester
     */
    protected $tester;

    protected function _before()
    {
        unset($_GET, $_POST);
    }

    protected function _after()
    {
        unset($_GET, $_POST);
    }

    public function testMissingAction(): void
    {
        $connector = new Connector(new Config());
        $response = $connector->handleRequest();
        $responseJson = $response->toStdClass();
        $this->tester->assertEquals('missing or invalid parameter "action"', $responseJson->msg);
    }

    public function testGetStorages(): void
    {
        $connector = new Connector(new Config());
        $_GET['action'] = 'storages';
        $response = $connector->handleRequest();
        $responseJson = $response->toStdClass();
        $this->tester->assertEmpty($responseJson->msg);
        $this->tester->assertFalse(property_exists($responseJson, 'storages'));
    }

    public function testGetInvalidStorage(): void
    {
        $connector = new Connector(new Config());
        $_GET['storage'] = 'invalid';
        $_GET['action'] = 'files';

        $this->tester->expectThrowable(
            new InvalidRequestException('not found storage "invalid"'),
            static function () use ($connector) {
                $connector->handleRequest();
            }
        );
    }

}
