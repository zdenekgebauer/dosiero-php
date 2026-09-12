<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Dosiero\Request;
use Tests\Support\UnitTester;

class RequestTest extends Unit
{
    protected UnitTester $tester;

    public function testNonScalarParametersBecomeEmptyStrings(): void
    {
        $_GET = ['action' => ['files'], 'storage' => ['local'], 'path' => ['sub']];
        $_POST = ['target_path' => ['sub'], 'target_storage' => ['local']];

        $request = new Request();

        $this->tester->assertSame('', $request->getAction());
        $this->tester->assertSame('', $request->getStorage());
        $this->tester->assertSame('', $request->getPath());
        $this->tester->assertSame('', $request->getTargetPath());
        $this->tester->assertSame('', $request->getTargetStorage());
    }

    public function testSelectedFilesWithoutParameter(): void
    {
        $_POST = [];
        $this->tester->assertSame([], (new Request())->getSelectedFiles());

        $_POST = ['files' => 'not-an-array'];
        $this->tester->assertSame([], (new Request())->getSelectedFiles());
    }

    // PHP fills in five scalar keys for a file input; anything else cannot be an upload
    public function testUploadedFilesDropsEntriesThatAreNotUploads(): void
    {
        $_FILES = [
            'good' => ['name' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/a', 'error' => 0, 'size' => 3],
            'notAnArray' => 'nonsense',
            'multiFile' => ['name' => ['a.txt', 'b.txt'], 'tmp_name' => ['/tmp/a', '/tmp/b'], 'error' => [0, 0]],
            'missingError' => ['name' => 'c.txt', 'tmp_name' => '/tmp/c'],
        ];

        $uploads = (new Request())->getUploadedFiles();

        $this->tester->assertSame(['good'], array_keys($uploads));
        $this->tester->assertSame('a.txt', $uploads['good']['name']);
        $this->tester->assertSame(3, $uploads['good']['size']);
    }

    // size and type are repaired rather than rejected: neither decides whether the upload is allowed
    public function testUploadedFilesFillsInMissingSizeAndType(): void
    {
        $_FILES = ['file' => ['name' => 'a.txt', 'tmp_name' => '/tmp/a', 'error' => 0]];

        $uploads = (new Request())->getUploadedFiles();

        $this->tester->assertSame(0, $uploads['file']['size']);
        $this->tester->assertSame('', $uploads['file']['type']);
    }

    protected function _after()
    {
        $_GET = [];
        $_POST = [];
        $_FILES = [];
    }

    protected function _before()
    {
        $_GET = [];
        $_POST = [];
        $_FILES = [];
    }
}
