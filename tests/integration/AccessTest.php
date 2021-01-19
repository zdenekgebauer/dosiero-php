<?php

namespace Dosiero;

class AccessTest extends \Codeception\Test\Unit
{

    /**
     * @var \IntegrationTester
     */
    protected $tester;

    protected function _before()
    {
        unset($_SESSION, $_SERVER);
    }

    protected function _after()
    {
        unset($_SESSION, $_SERVER);
    }

    public function testCheckSession(): void
    {
        $config = new Config();
        $config->requireSession('custom_session_name', 'custom_session_name');
        $connector = new Connector($config);
        $_GET['action'] = 'folders';
        $this->tester->expectThrowable(
            new AccessForbiddenException('missing required session variable'),
            static function () use ($connector) {
                $connector->handleRequest();
            }
        );

        $_SESSION['custom_session_name'] = '';
        $this->tester->expectThrowable(
            new AccessForbiddenException('missing or invalid value of required session variable'),
            static function () use ($connector) {
                $connector->handleRequest();
            }
        );
    }

    public function testCheckIp(): void
    {
        $config = new Config();
        $config->setAllowedIp(['123.456.123.456']);
        $connector = new Connector($config);
        $_GET['action'] = 'folders';
        $this->tester->expectThrowable(
            new AccessForbiddenException('access from your IP is not allowed'),
            static function () use ($connector) {
                $connector->handleRequest();
            }
        );
    }

    public function testBasicAuth(): void
    {
        $config = new Config();
        $config->requireBasicAuth('user', 'password');
        $connector = new Connector($config);
        $_GET['action'] = 'folders';
        $this->tester->expectThrowable(
            new AccessForbiddenException('missing required basic auth'),
            static function () use ($connector) {
                $connector->handleRequest();
            }
        );

        $_SERVER['PHP_AUTH_USER'] = 'invalid';
        $_SERVER['PHP_AUTH_PW'] = 'invalid';
        $this->tester->expectThrowable(
            new AccessForbiddenException('invalid basic authentication'),
            static function () use ($connector) {
                $connector->handleRequest();
            }
        );
    }

}
