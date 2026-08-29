<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\AccessForbiddenException;
use Dosiero\Config;
use Dosiero\Connector;
use Dosiero\Request;
use Tests\Support\IntegrationTester;

class AccessTest extends \Codeception\Test\Unit
{
    protected IntegrationTester $tester;

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
            },
        );

        $_SERVER['PHP_AUTH_USER'] = 'invalid';
        $_SERVER['PHP_AUTH_PW'] = 'invalid';
        $this->tester->expectThrowable(
            new AccessForbiddenException('invalid basic authentication'),
            static function () use ($connector) {
                $connector->handleRequest();
            },
        );

        // the password used to be compared against the user name, so supplying the
        // user name as the password passed and the real password was refused
        $_SERVER['PHP_AUTH_USER'] = 'user';
        $_SERVER['PHP_AUTH_PW'] = 'user';
        $this->tester->expectThrowable(
            new AccessForbiddenException('invalid basic authentication'),
            static function () use ($connector) {
                $connector->handleRequest();
            },
        );

        // correct credentials must get past access control; the request then fails
        // later on the unknown action, which is what we assert
        $_SERVER['PHP_AUTH_USER'] = 'user';
        $_SERVER['PHP_AUTH_PW'] = 'password';
        $this->tester->assertSame(
            'missing or invalid parameter "action"',
            $connector->handleRequest()->toStdClass()->msg,
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
            },
        );
    }

    public function testCheckSession(): void
    {
        $config = new Config();
        $config->requireSession('custom_session_name', 'custom_session_name');
        $connector = new Connector($config);
        $_GET['action'] = 'folders';

        // $_SESSION does not exist at all - the session was never started
        $this->tester->expectThrowable(
            new AccessForbiddenException('session is required but was not started'),
            static function () use ($connector) {
                $connector->handleRequest();
            },
        );

        // session exists but does not carry the required key
        $_SESSION = [];
        $this->tester->expectThrowable(
            new AccessForbiddenException('missing required session variable'),
            static function () use ($connector) {
                $connector->handleRequest();
            },
        );

        $_SESSION['custom_session_name'] = '';
        $this->tester->expectThrowable(
            new AccessForbiddenException('missing or invalid value of required session variable'),
            static function () use ($connector) {
                $connector->handleRequest();
            },
        );
    }

    protected function _after()
    {
        unset($_SESSION, $_SERVER);
    }

    protected function _before()
    {
        unset($_SESSION, $_SERVER);
    }

}
