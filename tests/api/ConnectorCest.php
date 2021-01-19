<?php

use Codeception\Util\HttpCode;

class ConnectorCest
{

    protected const STORAGE_NAME = 'LOCAL1';

    protected string $testDirectory;

    protected function _before(\ApiTester $I)
    {
        unset($_GET, $_POST, $_FILES);
        $this->testDirectory = codecept_data_dir('local');
        $I->emptyDirRecursive($this->testDirectory);
    }

    protected function _after(\ApiTester $I)
    {
        unset($_GET, $_POST, $_FILES);
        $I->emptyDirRecursive($this->testDirectory);
    }

    public function testMissingAction(\ApiTester $I): void
    {
        $I->sendGET('/');
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson(['msg' => 'missing or invalid parameter "action"']);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
    }

    public function testStorages(\ApiTester $I): void
    {
        $I->sendGET('/?action=storages');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseIsJson();
        $json = json_decode($I->grabResponse());
        $I->assertEquals('', $json->msg);
        $I->assertCount(1, $json->storages);
    }

    public function testFilesWithInvalidStorage(\ApiTester $I): void
    {
        $I->sendGET('/?action=files&storage=unknown');
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson(['msg' => 'not found storage "unknown"']);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
    }

    /**
     * @before _before
     * @after _after
     */
    public function testFiles(\ApiTester $I): void
    {
        $I->sendGET('/?action=files&storage=' . self::STORAGE_NAME);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson(['msg' => '', 'files' => []]);
        $I->seeResponseCodeIs(HttpCode::OK);
    }

    /**
     * @before _before
     * @after _after
     */
    public function testMkDir(\ApiTester $I): void
    {
        $post = ['folder' => 'newfolder'];
        $I->sendPOST('/?action=mkdir&storage=' . self::STORAGE_NAME, $post);
        $I->seeResponseIsJson();

        $json = json_decode($I->grabResponse());
        $I->assertEquals('', $json->msg);
        $I->assertCount(1, $json->files);
        $I->assertEquals('newfolder', $json->files[0]->name);
        $I->assertEquals(self::STORAGE_NAME, $json->storage->name);

        $I->seeResponseCodeIs(HttpCode::OK);

        // invalid folder name
        $post = ['folder' => '*'];
        $I->sendPOST('/?action=mkdir&storage=' . self::STORAGE_NAME, $post);
        $I->seeResponseIsJson();

        $I->seeResponseContainsJson(['msg' => 'invalid folder name "*"']);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
    }

    /**
     * @before _before
     * @after _after
     */
    public function testUpload(\ApiTester $I): void
    {

        $post = [];
        $I->sendPOST('/?action=upload&storage=' . self::STORAGE_NAME, $post, [
            'file' => codecept_data_dir('phpunit.jpg')
        ]);
        $I->seeResponseIsJson();

        $json = json_decode($I->grabResponse());
        $I->assertEquals('', $json->msg);
        $I->assertCount(1, $json->files);
        $I->assertEquals('phpunit.jpg', $json->files[0]->name);
        $I->assertStringContainsString('data:image/jpg;base64', $json->files[0]->thumbnail);
        $I->seeResponseCodeIs(HttpCode::OK);
    }

    /**
     * @before _before
     * @after _after
     */
    public function testRename(\ApiTester $I): void
    {
        file_put_contents($this->testDirectory .'/phpunit.jpg', '');
        $post = ['old' => 'phpunit.jpg', 'new' => 'new.jpg'];
        $I->sendPOST('/?action=rename&storage=' . self::STORAGE_NAME, $post);
        $I->seeResponseIsJson();

        $json = json_decode($I->grabResponse());
        $I->assertEquals('', $json->msg);
        $I->assertCount(1, $json->files);
        $I->assertEquals('new.jpg', $json->files[0]->name);
        //$I->assertEquals(self::STORAGE_NAME, $json->storage->name);

        $I->seeResponseCodeIs(HttpCode::OK);

        // invalid file name
        $post = ['old' => 'not-exists.jpg', 'new' => 'new.jpg'];
        $I->sendPOST('/?action=rename&storage=' . self::STORAGE_NAME, $post);
        $I->seeResponseIsJson();

        $I->seeResponseContainsJson(['msg' => 'cannot rename "not-exists.jpg" to "new.jpg"']);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
    }

    /**
     * @before _before
     * @after _after
     */
    public function testDelete(\ApiTester $I): void
    {
        file_put_contents($this->testDirectory .'/phpunit.jpg', '');
        $post = ['files' => ['phpunit.jpg']];
        $I->sendPOST('/?action=delete&storage=' . self::STORAGE_NAME, $post);
        $I->seeResponseIsJson();

        $json = json_decode($I->grabResponse());
        $I->assertEquals('', $json->msg);
        $I->assertCount(0, $json->files);

        $I->seeResponseCodeIs(HttpCode::OK);

        // invalid file name
        $post = ['files' => ['not-exists.jpg']];
        $I->sendPOST('/?action=delete&storage=' . self::STORAGE_NAME, $post);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson(['msg' => 'cannot delete "not-exists.jpg"']);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
    }

    /**
     * @before _before
     * @after _after
     */
    public function testCopy(\ApiTester $I): void
    {
        file_put_contents($this->testDirectory .'/phpunit.jpg', '');
        mkdir($this->testDirectory .'/target');
        $post = ['files' => ['phpunit.jpg'],
                'target_path' => '/target',
                'target_storage' => self::STORAGE_NAME
        ];
        $I->sendPOST('/?action=copy&storage=' . self::STORAGE_NAME, $post);
        $I->seeResponseIsJson();

        $json = json_decode($I->grabResponse());
        $I->assertEquals('', $json->msg);
        $I->assertCount(2, $json->files);

        $I->seeResponseCodeIs(HttpCode::OK);

        // invalid file name
        $post['files'] = ['not-exists.jpg'];
        $I->sendPOST('/?action=copy&storage=' . self::STORAGE_NAME, $post);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson(['msg' => 'cannot copy "not-exists.jpg"']);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
    }

    /**
     * @before _before
     * @after _after
     */
    public function testMove(\ApiTester $I): void
    {
        file_put_contents($this->testDirectory .'/phpunit.jpg', '');
        mkdir($this->testDirectory .'/target');
        $post = ['files' => ['phpunit.jpg'],
            'target_path' => '/target',
            'target_storage' => self::STORAGE_NAME
        ];
        $I->sendPOST('/?action=move&storage=' . self::STORAGE_NAME, $post);
        $I->seeResponseIsJson();

        $json = json_decode($I->grabResponse());

        $I->assertEquals('', $json->msg);
        $I->assertCount(1, $json->files);

        $I->seeResponseCodeIs(HttpCode::OK);

        // invalid file name
        $post['files'] = ['not-exists.jpg'];
        $I->sendPOST('/?action=move&storage=' . self::STORAGE_NAME, $post);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson(['msg' => 'cannot move "not-exists.jpg"']);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
    }
}
