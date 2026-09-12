<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\AccessForbiddenException;
use Dosiero\Config;
use Dosiero\Connector;
use Dosiero\Local\LocalStorage;
use Dosiero\Storage;

class ConnectorOriginTest extends LocalStorageBase
{
    public function testConfiguredForeignOriginIsAccepted(): void
    {
        $this->prepareDelete();
        $_SERVER['HTTP_ORIGIN'] = 'https://app.example.org';
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';

        $config = $this->createConfig();
        $config->allowOrigin('https://app.example.org');
        $this->connectorWith($config)->handleRequest();

        $this->tester->assertFileNotExists($this->testDirectory . '/file1.txt');
    }

    public function testCrossSiteMutationIsRefused(): void
    {
        $this->prepareDelete();
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';

        $this->expectDenied($this->getConnectorDefault());
        $this->tester->assertFileExists($this->testDirectory . '/file1.txt');
    }

    public function testForeignOriginIsRefused(): void
    {
        $this->prepareDelete();
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';

        $this->expectDenied($this->getConnectorDefault());
        $this->tester->assertFileExists($this->testDirectory . '/file1.txt');
    }

    // do not block an old browser or a "not bowser" client
    public function testMissingHeadersAreNotTreatedAsAnAttack(): void
    {
        $this->prepareDelete();

        $this->getConnectorDefault()->handleRequest();

        $this->tester->assertFileNotExists($this->testDirectory . '/file1.txt');
    }

    // the endpoint's own page is not a foreign origin, whatever Sec-Fetch-Site says
    public function testOwnOriginIsAccepted(): void
    {
        $this->prepareDelete();
        $_SERVER['HTTP_HOST'] = 'dosiero.example';
        $_SERVER['HTTP_ORIGIN'] = 'http://dosiero.example';

        $this->getConnectorDefault()->handleRequest();

        $this->tester->assertFileNotExists($this->testDirectory . '/file1.txt');
    }

    public function testReadingFromForeignOriginIsAllowed(): void
    {
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'files';
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';

        $responseJson = $this->getConnectorDefault()->handleRequest()->toStdClass();

        $this->tester->assertEmpty($responseJson->msg);
    }

    public function testSameOriginMutationIsAccepted(): void
    {
        $this->prepareDelete();
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';

        $this->getConnectorDefault()->handleRequest();

        $this->tester->assertFileNotExists($this->testDirectory . '/file1.txt');
    }

    public function testSecFetchSiteNoneIsRefused(): void
    {
        $this->prepareDelete();
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'none';

        $this->expectDenied($this->getConnectorDefault());
        $this->tester->assertFileExists($this->testDirectory . '/file1.txt');
    }

    protected function _after()
    {
        unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_HOST']);
        parent::_after();
    }

    private function connectorWith(Config $config): Connector
    {
        $storage = new LocalStorage(self::STORAGE_NAME);
        $storage->setOption(LocalStorage::OPTION_BASE_DIR, codecept_data_dir('local'));
        $storage->setOption(Storage::OPTION_BASE_URL, $this->testUrl);

        $connector = new Connector($config);
        $connector->addStorage($storage);
        return $connector;
    }

    private function expectDenied(Connector $connector): void
    {
        $this->tester->expectThrowable(
            new AccessForbiddenException('access denied'),
            static function () use ($connector): void {
                $connector->handleRequest();
            },
        );
    }

    private function prepareDelete(): void
    {
        file_put_contents($this->testDirectory . '/file1.txt', '');
        $_GET['storage'] = self::STORAGE_NAME;
        $_GET['action'] = 'delete';
        $_POST['files'] = ['file1.txt'];
    }
}
