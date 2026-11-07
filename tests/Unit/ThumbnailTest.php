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
        foreach ([[20, 10, 20, 10], [40, 200, 10, 50]] as [$width, $height, $thumbWidth, $thumbHeight]) {
            $thumbnail = self::decode(
                Thumbnail::createThumbnailFromString(self::png(imagecreatetruecolor($width, $height)), 'png'),
                'png',
            );
            $this->tester->assertSame([$thumbWidth, $thumbHeight], [imagesx($thumbnail), imagesy($thumbnail)]);
        }
    }

    // the transparent colour of a palette image is often index 0, the first one allocated
    public function testGifKeepsItsTransparentColour(): void
    {
        $image = imagecreate(100, 100);
        $transparent = (int)imagecolorallocate($image, 255, 0, 255);
        imagecolortransparent($image, $transparent);
        imagefilledrectangle($image, 40, 40, 60, 60, (int)imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagegif($image);
        $data = (string)ob_get_clean();

        $thumbnail = self::decode(Thumbnail::createThumbnailFromString($data, 'gif'), 'gif');

        $thumbTransparent = imagecolortransparent($thumbnail);
        $this->tester->assertGreaterThanOrEqual(0, $thumbTransparent);
        $this->tester->assertSame($thumbTransparent, imagecolorat($thumbnail, 0, 0));
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

    public function testPngKeepsItsAlphaChannel(): void
    {
        $image = imagecreatetruecolor(100, 100);
        imagealphablending($image, false);
        imagefilledrectangle($image, 0, 0, 99, 99, (int)imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 40, 40, 60, 60, (int)imagecolorallocate($image, 255, 0, 0));
        imagesavealpha($image, true);

        $thumbnail = self::decode(Thumbnail::createThumbnailFromString(self::png($image), 'png'), 'png');

        $this->tester->assertSame(127, (imagecolorat($thumbnail, 0, 0) >> 24) & 0x7F);
    }

    // decodable, and cheap to decode, but beyond the dimension a thumbnail is ever made from
    public function testTooWideImageIsRefused(): void
    {
        $file = codecept_output_dir('wide.png');
        file_put_contents($file, self::png(imagecreatetruecolor(50_001, 1)));

        $this->tester->assertSame('', Thumbnail::createThumbnailFromFile($file));

        unlink($file);
    }

    private static function decode(string $thumbnail, string $ext): \GdImage
    {
        $prefix = 'data:image/' . $ext . ';base64,';
        self::assertStringStartsWith($prefix, $thumbnail);
        $image = imagecreatefromstring((string)base64_decode(substr($thumbnail, strlen($prefix)), true));
        self::assertNotFalse($image);
        return $image;
    }

    private static function png(\GdImage $image): string
    {
        ob_start();
        imagepng($image);
        return (string)ob_get_clean();
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
