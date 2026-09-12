<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Dosiero\Config;
use Tests\Support\UnitTester;

class ConfigTest extends Unit
{
    protected UnitTester $tester;

    public function testAllowedIpAcceptsRanges(): void
    {
        $config = new Config();
        $config->setAllowedIp(['10.0.0.0/8', '2001:db8::/32']);

        $this->tester->assertEquals(['10.0.0.0/8', '2001:db8::/32'], $config->getAllowedIp());
    }

    // inet_pton() normalizes the notation, so the long and the short form end up identical
    public function testAllowedIpNormalizesIpv6Notation(): void
    {
        $config = new Config();
        $config->setAllowedIp(['0:0:0:0:0:0:0:1']);

        $this->tester->assertEquals(['::1/128'], $config->getAllowedIp());
    }

    public function testAllowedIpRefusesWhatItCannotParse(): void
    {
        $config = new Config();

        $this->tester->expectThrowable(
            new \InvalidArgumentException('invalid IP address "123.456.123.456"'),
            static function () use ($config): void {
                $config->setAllowedIp(['123.456.123.456']);
            },
        );
        $this->tester->expectThrowable(
            \InvalidArgumentException::class,
            static function () use ($config): void {
                $config->setAllowedIp(['10.0.0.0/99']);
            },
        );
    }

    public function testAllowOriginRequiresProtocol(): void
    {
        $config = new Config();
        $config->allowOrigin('https://app.example.org');
        $config->allowOrigin('*');

        $this->tester->assertTrue($config->isOriginAllowed('https://anything.example'));
        $this->tester->expectThrowable(
            new \InvalidArgumentException('expected origin including protocol or *'),
            static function () use ($config): void {
                $config->allowOrigin('app.example.org');
            },
        );
    }

    // a browser refuses Access-Control-Allow-Origin: * for a request carrying credentials
    public function testCredentialsRequireANamedOrigin(): void
    {
        $config = new Config();

        $this->tester->expectThrowable(
            new \InvalidArgumentException('credentials require a named origin, not "*"'),
            static function () use ($config): void {
                $config->allowOrigin('*', true);
            },
        );

        $config->allowOrigin('https://app.example.org', true);
        $this->tester->assertTrue($config->isCredentialsAllowed('https://app.example.org'));
        $this->tester->assertFalse($config->isCredentialsAllowed('https://other.example.org'));
    }

    public function testDefaultValues(): void
    {
        $config = new Config();
        $this->tester->assertEquals('', $config->getSessionName());
        $this->tester->assertEquals('', $config->getSessionValue());
        $this->tester->assertEquals([], $config->getAllowedIp());
    }

    public function testIsConfigured(): void
    {
        $this->tester->assertFalse((new Config())->isConfigured());

        $anonymous = new Config();
        $anonymous->allowAnonymous();
        $this->tester->assertTrue($anonymous->isConfigured());

        $withCallback = new Config();
        $withCallback->requireCallback(static fn(string $action, string $storage): bool => true);
        $this->tester->assertTrue($withCallback->isConfigured());
    }

    public function testSetters(): void
    {
        $config = new Config();
        $config->requireSession('name', 'value');
        $config->setAllowedIp([' 127.0.0.1 ', ' ', ' 10.10.10.10']);

        $this->tester->assertEquals('name', $config->getSessionName());
        $this->tester->assertEquals('value', $config->getSessionValue());
        // a single address is stored as a full-width prefix, so comparison has one shape
        $this->tester->assertEquals(['127.0.0.1/32', '10.10.10.10/32'], $config->getAllowedIp());
    }

    /**
     * Credentials used to be a single flag over the whole configuration, so a wildcard and a named
     * origin with credentials added up to credentialed access for anybody - in either order.
     */
    public function testWildcardNeverGrantsCredentials(): void
    {
        $wildcardFirst = new Config();
        $wildcardFirst->allowOrigin('*');
        $wildcardFirst->allowOrigin('https://trusted.example', true);

        $namedFirst = new Config();
        $namedFirst->allowOrigin('https://trusted.example', true);
        $namedFirst->allowOrigin('*');

        foreach ([$wildcardFirst, $namedFirst] as $config) {
            // the wildcard still lets anybody read, which is what it was asked to do
            $this->tester->assertTrue($config->isOriginAllowed('https://attacker.example'));
            // but only the origin that was named gets to send credentials
            $this->tester->assertTrue($config->isCredentialsAllowed('https://trusted.example'));
            $this->tester->assertFalse($config->isCredentialsAllowed('https://attacker.example'));
            $this->tester->assertFalse($config->isCredentialsAllowed('*'));
        }
    }
}
