<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Dosiero\Thumbnail;
use Tests\Support\UnitTester;

class ThumbnailTest extends Unit
{
    protected UnitTester $tester;

    public function testCreateThumbnailFromEmptyString(): void
    {
        $this->tester->assertEquals('', Thumbnail::createThumbnailFromString('', 'png'));
    }

    public function testCreateThumbnailFromFileGif(): void
    {
        $this->tester->assertStringContainsString(
            'data:image/gif;base64,',
            Thumbnail::createThumbnailFromFile(codecept_data_dir('phpunit.gif')),
        );
    }

    public function testCreateThumbnailFromFileIncorrectImages(): void
    {
        $this->tester->assertEquals('', Thumbnail::createThumbnailFromFile(codecept_data_dir('empty-image.png')));
        $this->tester->assertEquals('', Thumbnail::createThumbnailFromFile(codecept_data_dir('invalid-image.png')));
    }

    public function testCreateThumbnailFromFilePng(): void
    {
        $this->tester->assertStringContainsString(
            'data:image/png;base64,',
            Thumbnail::createThumbnailFromFile(codecept_data_dir('phpunit.png')),
        );
    }

    // the branch where nothing is scaled down, and the one where the image is taller than it is wide
    public function testCreateThumbnailKeepsSmallImageAndHandlesPortrait(): void
    {
        $small = imagecreatetruecolor(20, 10);
        ob_start();
        imagepng($small);
        $smallData = (string)ob_get_clean();

        $portrait = imagecreatetruecolor(40, 200);
        ob_start();
        imagepng($portrait);
        $portraitData = (string)ob_get_clean();

        foreach ([$smallData, $portraitData] as $data) {
            $thumbnail = Thumbnail::createThumbnailFromString($data, 'png');
            $this->tester->assertStringStartsWith('data:image/png;base64,', $thumbnail);
            $decoded = (string)base64_decode(substr($thumbnail, strlen('data:image/png;base64,')), true);
            $size = getimagesizefromstring($decoded);
            $this->tester->assertNotFalse($size);
            $this->tester->assertLessThanOrEqual(50, $size[0]);
            $this->tester->assertLessThanOrEqual(50, $size[1]);
        }
    }

    /**
     * The dimensions are read from the header, long before the pixels are - a small compressed file
     * can still ask for gigabytes once expanded, and a listing decodes every image in the folder.
     * The upload size limit bounds the bytes on disk, which says nothing about this.
     */
    public function testHugeDeclaredDimensionsAreRefusedBeforeDecoding(): void
    {
        $file = codecept_output_dir('huge.png');
        // a valid png header declaring 60000 x 60000, with no pixel data behind it
        file_put_contents($file, self::pngWithDeclaredSize(60000, 60000));

        $before = memory_get_usage();
        $this->tester->assertEquals('', Thumbnail::createThumbnailFromFile($file));
        // the refusal has to happen before the allocation, not after it
        $this->tester->assertLessThan(10 * 1024 * 1024, memory_get_usage() - $before);

        unlink($file);
    }

    public function testNormalImageStillGetsAThumbnail(): void
    {
        $this->tester->assertStringContainsString(
            'data:image/png;base64,',
            Thumbnail::createThumbnailFromFile(codecept_data_dir('phpunit.png')),
        );
    }

    private static function pngWithDeclaredSize(int $width, int $height): string
    {
        $ihdr = pack('NN', $width, $height) . pack('C5', 8, 2, 0, 0, 0);
        $chunk = pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
        return "PNG

" . $chunk;
    }
}
