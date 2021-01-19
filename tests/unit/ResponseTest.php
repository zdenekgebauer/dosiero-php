<?php

declare(strict_types=1);

namespace Dosiero;

class ResponseTest extends \Codeception\Test\Unit
{

    /**
     * @var \UnitTester
     */
    protected $tester;

    public function testAllowAccess(): void
    {
        $response = new Response();

        $response->allowAccessFromDomain('*');
        $response->allowAccessFromDomain('http://example.org');
        $response->allowAccessFromDomain('https://example.org');

        $this->tester->expectThrowable(
            new \InvalidArgumentException('expected domain including protocol or *'),
            static function () use ($response) {
                $response->allowAccessFromDomain('example.org');
            }
        );
    }
}
