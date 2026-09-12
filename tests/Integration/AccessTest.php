<?php

declare(strict_types=1);

namespace Tests\Integration;

use Codeception\Test\Unit;
use Dosiero\AccessForbiddenException;
use Dosiero\Config;
use Dosiero\Connector;
use Tests\Support\IntegrationTester;

class AccessTest extends Unit
{
    protected IntegrationTester $tester;

    public function testAllowAnonymousServesEverybody(): void
    {
        $config = new Config();
        $config->allowAnonymous();
        $connector = new Connector($config);
        $_GET['action'] = 'storages';

        $this->tester->assertEmpty($connector->handleRequest()->toStdClass()->msg);
    }

    public function testBasicAuth(): void
    {
        $config = new Config();
        $config->requireBasicAuth('user', 'password');
        $connector = new Connector($config);
        $_GET['action'] = 'folders';
        $this->tester->expectThrowable(
            new AccessForbiddenException('access denied'),
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );

        $_SERVER['PHP_AUTH_USER'] = 'invalid';
        $_SERVER['PHP_AUTH_PW'] = 'invalid';
        $this->tester->expectThrowable(
            new AccessForbiddenException('access denied'),
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );

        // the password used to be compared against the user name, so supplying the
        // user name as the password passed and the real password was refused
        $_SERVER['PHP_AUTH_USER'] = 'user';
        $_SERVER['PHP_AUTH_PW'] = 'user';
        $this->tester->expectThrowable(
            new AccessForbiddenException('access denied'),
            static function () use ($connector): void {
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
        // a valid address the caller does not have; an unparseable one is now refused at set time
        $config->setAllowedIp(['10.0.0.1']);
        $connector = new Connector($config);
        $_GET['action'] = 'folders';
        $this->tester->expectThrowable(
            new AccessForbiddenException('access denied'),
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );
    }

    public function testCheckIpRange(): void
    {
        $config = new Config();
        $config->setAllowedIp(['127.0.0.0/8']);
        $connector = new Connector($config);
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_GET['action'] = 'storages';

        $this->tester->assertEmpty($connector->handleRequest()->toStdClass()->msg);
    }

    public function testCheckSession(): void
    {
        $config = new Config();
        $config->requireSession('custom_session_name', 'custom_session_name');
        $connector = new Connector($config);
        $_GET['action'] = 'folders';

        // $_SESSION does not exist at all - the session was never started
        $this->tester->expectThrowable(
            new AccessForbiddenException('access denied'),
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );

        // session exists but does not carry the required key
        $_SESSION = [];
        $this->tester->expectThrowable(
            new AccessForbiddenException('access denied'),
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );

        $_SESSION['custom_session_name'] = '';
        $this->tester->expectThrowable(
            new AccessForbiddenException('access denied'),
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );
    }

    public function testUnconfiguredConnectorRefusesEverything(): void
    {
        $connector = new Connector(new Config());
        $_GET['action'] = 'storages';

        $this->tester->expectThrowable(
            AccessForbiddenException::class,
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );
    }

    protected function _after()
    {
        unset($_SESSION, $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_X_DOSIERO_PROTOCOL']);
    }

    protected function _before()
    {
        unset($_SESSION, $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
        // these cases are about the access checks, so the request has to get past the header first
        $_SERVER['HTTP_X_DOSIERO_PROTOCOL'] = Connector::PROTOCOL_VERSION;
    }

}
