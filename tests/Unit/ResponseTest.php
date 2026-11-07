<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Dosiero\Config;
use Dosiero\Connector;
use Dosiero\Response;
use Tests\Support\UnitTester;

class ResponseTest extends Unit
{
    private const array JSON_HEADERS = [
        'Content-type: application/json; charset=utf-8',
        'X-Content-Type-Options: nosniff',
    ];

    private const array PREFLIGHT_HEADERS = [
        'Access-Control-Allow-Methods: GET, POST, OPTIONS',
        'Access-Control-Allow-Headers: ' . Connector::PROTOCOL_HEADER,
        'Access-Control-Max-Age: 600',
    ];

    protected UnitTester $tester;

    // the named origin is echoed back, which is what a credentialed request needs
    public function testCorsHeadersForACredentialedOrigin(): void
    {
        $response = self::responseFor(['https://app.example.org' => true]);
        $_SERVER['HTTP_ORIGIN'] = 'https://app.example.org/';

        $this->tester->assertSame(
            [
                'Access-Control-Allow-Origin: https://app.example.org',
                'Vary: Origin',
                'Access-Control-Allow-Credentials: true',
                ...self::JSON_HEADERS,
            ],
            $response->getHeaders(),
        );
    }

    public function testCorsHeadersForANamedOrigin(): void
    {
        $response = self::responseFor(['https://app.example.org' => false]);
        $_SERVER['HTTP_ORIGIN'] = 'https://app.example.org';

        $this->tester->assertSame(
            ['Access-Control-Allow-Origin: https://app.example.org', 'Vary: Origin', ...self::JSON_HEADERS],
            $response->getHeaders(),
        );
    }

    public function testCorsHeadersForAWildcard(): void
    {
        $response = self::responseFor(['*' => false]);

        $this->tester->assertSame(
            ['Access-Control-Allow-Origin: *', 'Vary: Origin', ...self::JSON_HEADERS],
            $response->getHeaders(),
        );

        // a wildcard still names the caller, so that the answer is the same shape as for a named origin
        $_SERVER['HTTP_ORIGIN'] = 'https://anywhere.example';
        $this->tester->assertSame(
            ['Access-Control-Allow-Origin: https://anywhere.example', 'Vary: Origin', ...self::JSON_HEADERS],
            $response->getHeaders(),
        );
    }

    public function testHttpStatusIsReadable(): void
    {
        $this->tester->assertSame(Response::STATUS_OK, (new Response())->getHttpStatus());
        $this->tester->assertSame(
            Response::STATUS_BAD_REQUEST,
            (new Response(Response::STATUS_BAD_REQUEST, 'nope'))->getHttpStatus(),
        );
    }

    public function testNoCorsHeadersForAnOriginNotAllowed(): void
    {
        $response = self::responseFor(['https://app.example.org' => true]);
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';

        $this->tester->assertSame(self::JSON_HEADERS, $response->getHeaders());
    }

    public function testNoCorsHeadersWithoutConfiguredOrigins(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://app.example.org';

        $this->tester->assertSame(self::JSON_HEADERS, (new Response())->getHeaders());
        $this->tester->assertSame(self::JSON_HEADERS, self::responseFor([])->getHeaders());
    }

    // OPTIONS is what a browser sends before it will let a cross-origin request carry our header
    public function testPreflightAnswersOnlyOptions(): void
    {
        $response = self::responseFor(['https://app.example.org' => false]);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->tester->assertFalse($response->sendPreflight());

        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        http_response_code(Response::STATUS_OK);
        ob_start();
        $answered = $response->sendPreflight();
        $output = (string)ob_get_clean();

        $this->tester->assertTrue($answered);
        $this->tester->assertSame(204, http_response_code());
        $this->tester->assertSame('', $output);
    }

    public function testPreflightHeaders(): void
    {
        $response = self::responseFor(['https://app.example.org' => false]);
        $_SERVER['HTTP_ORIGIN'] = 'https://app.example.org';

        $this->tester->assertSame(
            ['Access-Control-Allow-Origin: https://app.example.org', 'Vary: Origin', ...self::PREFLIGHT_HEADERS],
            $response->getPreflightHeaders(),
        );
    }

    public function testSendOutputPrintsJson(): void
    {
        $response = new Response(Response::STATUS_BAD_REQUEST, 'something went wrong');

        http_response_code(Response::STATUS_OK);
        ob_start();
        $response->sendOutput();
        $output = (string)ob_get_clean();

        $this->tester->assertSame(['msg' => 'something went wrong'], json_decode($output, true));
        $this->tester->assertSame(Response::STATUS_BAD_REQUEST, http_response_code());
    }

    protected function _after()
    {
        unset($_SERVER['HTTP_ORIGIN'], $_SERVER['REQUEST_METHOD']);
    }

    protected function _before()
    {
        unset($_SERVER['HTTP_ORIGIN'], $_SERVER['REQUEST_METHOD']);
    }

    /** @param array<string, bool> $origins origin => with credentials */
    private static function responseFor(array $origins): Response
    {
        $config = new Config();
        foreach ($origins as $origin => $withCredentials) {
            $config->allowOrigin($origin, $withCredentials);
        }
        $response = new Response();
        $response->allowAccessFrom($config);
        return $response;
    }
}
