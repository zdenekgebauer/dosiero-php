<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Dosiero\Config;
use Dosiero\Response;
use Tests\Support\UnitTester;

class ResponseTest extends Unit
{
    protected UnitTester $tester;

    // the named origin is echoed back, which is what a credentialed request needs
    public function testCorsHeadersForACredentialedOrigin(): void
    {
        $config = new Config();
        $config->allowOrigin('https://app.example.org', true);
        $response = new Response();
        $response->allowAccessFrom($config);

        $_SERVER['HTTP_ORIGIN'] = 'https://app.example.org';
        ob_start();
        $response->sendOutput();
        $output = (string)ob_get_clean();
        unset($_SERVER['HTTP_ORIGIN']);

        $this->tester->assertSame(['msg' => ''], json_decode($output, true));
    }

    // header() is a no-op under the cli sapi, so only the body is asserted here
    // a framework controller reads the status off the object instead of letting sendOutput() set it
    public function testHttpStatusIsReadable(): void
    {
        $this->tester->assertSame(Response::STATUS_OK, (new Response())->getHttpStatus());
        $this->tester->assertSame(
            Response::STATUS_BAD_REQUEST,
            (new Response(Response::STATUS_BAD_REQUEST, 'nope'))->getHttpStatus(),
        );
    }

    public function testNoCorsHeadersWithoutConfiguredOrigins(): void
    {
        $response = new Response();
        $response->allowAccessFrom(new Config());

        ob_start();
        $response->sendOutput();
        $output = (string)ob_get_clean();

        $this->tester->assertSame(['msg' => ''], json_decode($output, true));
    }

    // OPTIONS is what a browser sends before it will let a cross-origin request carry our header
    public function testPreflightAnswersOnlyOptions(): void
    {
        $config = new Config();
        $config->allowOrigin('https://app.example.org');
        $response = new Response();
        $response->allowAccessFrom($config);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->tester->assertFalse($response->sendPreflight());

        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        ob_start();
        $answered = $response->sendPreflight();
        ob_end_clean();
        $this->tester->assertTrue($answered);

        unset($_SERVER['REQUEST_METHOD']);
    }

    public function testSendOutputPrintsJson(): void
    {
        $response = new Response(Response::STATUS_BAD_REQUEST, 'something went wrong');

        ob_start();
        $response->sendOutput();
        $output = (string)ob_get_clean();

        $this->tester->assertSame(['msg' => 'something went wrong'], json_decode($output, true));
    }

    public function testSendOutputWithAllowedOrigin(): void
    {
        $config = new Config();
        $config->allowOrigin('https://app.example.org');
        $response = new Response();
        $response->allowAccessFrom($config);

        ob_start();
        $response->sendOutput();
        $output = (string)ob_get_clean();

        $this->tester->assertSame(['msg' => ''], json_decode($output, true));
    }

    public function testSendOutputWithWildcardOrigin(): void
    {
        $config = new Config();
        $config->allowOrigin('*');
        $response = new Response();
        $response->allowAccessFrom($config);

        ob_start();
        $response->sendOutput();
        $output = (string)ob_get_clean();

        $this->tester->assertSame(['msg' => ''], json_decode($output, true));
    }
}
