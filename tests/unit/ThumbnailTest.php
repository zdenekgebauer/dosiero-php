<?php

declare(strict_types=1);

namespace Dosiero;

class ThumbnailTest extends \Codeception\Test\Unit
{

    /**
     * @var \UnitTester
     */
    protected $tester;

    public function testCreateThumbnailFromFileIncorrectImages(): void
    {
        $this->tester->assertEquals('', Thumbnail::createThumbnailFromFile(codecept_data_dir('empty-image.png')));
        $this->tester->assertEquals('', Thumbnail::createThumbnailFromFile(codecept_data_dir('invalid-image.png')));
    }

    public function testCreateThumbnailFromFilePng(): void
    {
        $this->tester->assertStringContainsString(
            'data:image/png;base64,',
            Thumbnail::createThumbnailFromFile(codecept_data_dir('phpunit.png'))
        );
    }

    public function testCreateThumbnailFromFileGif(): void
    {
        $this->tester->assertStringContainsString(
            'data:image/gif;base64,',
            Thumbnail::createThumbnailFromFile(codecept_data_dir('phpunit.gif'))
        );
    }
}
