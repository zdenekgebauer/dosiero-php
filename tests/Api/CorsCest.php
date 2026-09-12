<?php

declare(strict_types=1);

namespace Tests\Api;

use Codeception\Util\HttpCode;
use Dosiero\Connector;
use Tests\Support\ApiTester;

/**
 * The CORS and preflight headers exist only on a real response, so no in-process test can see them.
 * This is also where the protocol header earns its keep: it is what makes a browser preflight a
 * cross-origin request at all, and a plain form submission can never carry it.
 */
class CorsCest
{
    public function testConfiguredOriginIsEchoedBack(ApiTester $I): void
    {
        $I->haveHttpHeader('Origin', 'https://app.example.org');
        $I->sendGET('/?action=storages');

        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeHttpHeader('Access-Control-Allow-Origin', 'https://app.example.org');
        $I->seeHttpHeader('Vary', 'Origin');
        // the endpoint grants no credentials to it, and the header must not appear on its own
        $I->dontSeeHttpHeader('Access-Control-Allow-Credentials');
    }

    /** A mutation from another site is refused even though reading from one is configured. */
    public function testCrossSiteMutationIsRefused(ApiTester $I): void
    {
        $I->haveHttpHeader('Origin', 'https://attacker.example');
        $I->sendPOST('/?action=mkdir&storage=LOCAL1', ['folder' => 'from-attacker']);

        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['msg' => 'access denied']);
    }

    public function testEveryResponseCarriesNosniff(ApiTester $I): void
    {
        $I->sendGET('/?action=storages');

        $I->seeHttpHeader('X-Content-Type-Options', 'nosniff');
    }

    public function testForeignOriginGetsNoCorsHeader(ApiTester $I): void
    {
        $I->haveHttpHeader('Origin', 'https://attacker.example');
        $I->sendGET('/?action=storages');

        $I->dontSeeHttpHeader('Access-Control-Allow-Origin');
    }
    public function testPreflightIsAnswered(ApiTester $I): void
    {
        $I->haveHttpHeader('Origin', 'https://app.example.org');
        $I->haveHttpHeader('Access-Control-Request-Method', 'POST');
        $I->haveHttpHeader('Access-Control-Request-Headers', Connector::PROTOCOL_HEADER);
        $I->sendOPTIONS('/?action=storages');

        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);
        $I->seeHttpHeader('Access-Control-Allow-Origin', 'https://app.example.org');
        $I->seeHttpHeaderOnce('Access-Control-Allow-Origin');
        $I->seeHttpHeader('Access-Control-Allow-Headers', Connector::PROTOCOL_HEADER);
    }

    public function testSecFetchSiteCrossSiteIsRefused(ApiTester $I): void
    {
        $I->haveHttpHeader('Sec-Fetch-Site', 'cross-site');
        $I->sendPOST('/?action=mkdir&storage=LOCAL1', ['folder' => 'from-elsewhere']);

        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
    }
}
