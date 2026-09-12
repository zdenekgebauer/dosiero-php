<?php

declare(strict_types=1);

namespace Tests\Support\Helper;

use Codeception\Module;
use Codeception\TestInterface;
use Dosiero\Connector;

// here you can define custom actions
// all public methods declared in helper class will be available in $I
class Api extends Module
{
    /**
     * The connector refuses a request without the protocol header, and a real client sends it with
     * every request - so does this suite. Sec-Fetch-Site and Origin are deliberately left out,
     * which makes the suite the proof that those two fail open.
     */
    public function _before(TestInterface $test): void
    {
        $rest = $this->getModule('REST');
        $rest->haveHttpHeader(Connector::PROTOCOL_HEADER, Connector::PROTOCOL_VERSION);
    }
}
