<?php

declare(strict_types=1);

namespace Dosiero;

class ConfigTest extends \Codeception\Test\Unit
{

    /**
     * @var \UnitTester
     */
    protected $tester;

    public function testDefaultValues(): void
    {
        $config = new Config();
        $this->tester->assertEquals('', $config->getSessionName());
        $this->tester->assertEquals('', $config->getSessionValue());
        $this->tester->assertEquals([], $config->getAllowedIp());
    }

    public function testSetters(): void
    {
        $config = new Config();
        $config->requireSession('name', 'value');
        $config->setAllowedIp([' 127.0.0.1 ', ' ', ' 10.10.10.10']);

        $this->tester->assertEquals('name', $config->getSessionName());
        $this->tester->assertEquals('value', $config->getSessionValue());
        $this->tester->assertEquals(['127.0.0.1', '10.10.10.10'], $config->getAllowedIp());
    }
}
