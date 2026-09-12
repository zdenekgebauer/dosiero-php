<?php

declare(strict_types=1);

/**
 * Entry point for the Api suite, which drives the connector over real HTTP.
 *
 * The local playground entry point is `/index.php`, and that one is gitignored on purpose — it
 * carries whatever configuration the developer is experimenting with. A suite that runs in CI
 * cannot depend on a file nobody else has, so the two are separate.
 *
 * Serve it with the built-in server: php -S 127.0.0.1:8080 -t tests/Support/Endpoint
 */

namespace Dosiero;

use Dosiero\Local\LocalStorage;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

$config = new Config();
// the suite is about the protocol and the http layer, not about who may use it
$config->allowAnonymous();
// exercised by the cross-origin cases; a named origin, never a wildcard with credentials
$config->allowOrigin('https://app.example.org');

$storage = new LocalStorage('LOCAL1');
$storage->setOption(LocalStorage::OPTION_BASE_DIR, dirname(__DIR__) . '/Data/local');
$storage->setOption(LocalStorage::OPTION_BASE_URL, 'http://127.0.0.1:8080/data');
$storage->setOption(LocalStorage::OPTION_MODE_DIRECTORY, 0o775);
$storage->setOption(LocalStorage::OPTION_MODE_FILE, 0o664);

try {
    $connector = new Connector($config);
    $connector->addStorage($storage);
    $response = $connector->handleRequest();
} catch (AccessForbiddenException $exception) {
    $response = new Response(403, $exception->getMessage());
} catch (StorageException | InvalidRequestException $exception) {
    $response = new Response(400, $exception->getMessage());
} catch (\Throwable) {
    $response = new Response(500, 'unexpected problem');
}

$response->allowAccessFrom($config);
if (!$response->sendPreflight()) {
    $response->sendOutput();
}
