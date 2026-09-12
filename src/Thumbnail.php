<?php

declare(strict_types=1);

namespace Dosiero;

use function function_exists;

class Thumbnail
{
    // a single absurd dimension is refused even when the total would pass, e.g. 1 x 500_000_000
    private const int MAX_DIMENSION = 50_000;

    /** Roughly a 100 Mpx image; a thumbnail is not worth more memory than that. */
    private const int MAX_PIXELS = 100_000_000;

    /** @return string thumbnail as base64 data uri */
    public static function createThumbnailFromFile(string $file, int $maxSize = 50): string
    {
        if (!self::isWithinPixelLimit($file)) {
            return '';
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $ext = str_replace('jpeg', 'jpg', $ext);
        return self::createThumbnailFromString((string)file_get_contents($file), $ext, $maxSize);
    }

    public static function createThumbnailFromString(string $image, string $ext, int $maxSize = 50): string
    {
        if ($image === '') {
            return '';
        }
        $imgOrig = @imagecreatefromstring($image);
        if ($imgOrig === false) {
            return '';
        }

        $origTop = 0;
        $origLeft = 0;
        $origWidth = imagesx($imgOrig);
        $origHeight = imagesy($imgOrig);

        $thumbTop = 0;
        $thumbLeft = 0;
        if ($origWidth <= $maxSize && $origHeight <= $maxSize) {
            $thumbWidth = $origWidth;
            $thumbHeight = $origHeight;
        } else {
            $thumbWidth = $maxSize;
            $thumbHeight = $maxSize;

            $ratio = $origWidth / $origHeight;
            if ($ratio < 1) {
                $thumbWidth = min($thumbWidth, (int)($thumbHeight * $ratio));
            } else {
                $thumbHeight = min($thumbHeight, (int)ceil($thumbWidth / $ratio));
            }
        }

        // imagecreatetruecolor() refuses a zero dimension, which imagesx()/imagesy() can report for
        // an image GD accepted but could not size
        if ($thumbWidth < 1 || $thumbHeight < 1) {
            return '';
        }
        $imgThumb = imagecreatetruecolor($thumbWidth, $thumbHeight);

        if ($ext === 'gif') {
            $transparentIndex = imagecolortransparent($imgOrig);
            if ($transparentIndex >= 0) {
                // get original image's transparent color's RGB values
                $transparentColor = imagecolorsforindex($imgOrig, $transparentIndex);
                // allocate the same color in the new image
                $allocated = imagecolorallocate(
                    $imgThumb,
                    $transparentColor['red'],
                    $transparentColor['green'],
                    $transparentColor['blue'],
                );
                if ($allocated !== false) {
                    // fill the background of the new image with allocated color
                    imagefill($imgThumb, 0, 0, $allocated);
                    // set the background color to transparent
                    imagecolortransparent($imgThumb, $allocated);
                }
            }
        }
        if ($ext === 'png') {
            // temporarily turn off transparency blending
            imagealphablending($imgThumb, false);
            imagesavealpha($imgThumb, true);
            // create a new transparent color for image
            $transparent = (int)imagecolorallocatealpha($imgThumb, 0, 0, 0, 127);
            // fill the background of the new image with allocated color
            imagefilledrectangle($imgThumb, 0, 0, $thumbWidth, $thumbHeight, $transparent);
            // restore transparency blending
            imagesavealpha($imgThumb, true);
        }

        imagecopyresampled(
            $imgThumb,
            $imgOrig,
            $origTop,
            $origLeft,
            $thumbTop,
            $thumbLeft,
            $thumbWidth,
            $thumbHeight,
            $origWidth,
            $origHeight,
        );

        $imageData = self::imageToData($imgThumb, $ext);
        $mimes = [
            'png' => 'image/png',
            'jpeg' => 'image/jpg',
            'jpg' => 'image/jpg',
            'gif' => 'image/gif',
        ];
        $mime = $mimes[$ext] ?? 'image/jpg';

        return $imageData === '' ? '' : 'data:' . $mime . ';base64,' . base64_encode($imageData);
    }

    private static function imageToData(\GdImage $image, string $ext): string
    {
        $imageFunction = 'image' . str_replace('jpg', 'jpeg', $ext);
        if (!function_exists($imageFunction)) {
            return '';
        }
        ob_start();
        $imageFunction($image);
        return (string)ob_get_clean();
    }

    private static function isWithinPixelLimit(string $file): bool
    {
        $size = @getimagesize($file);
        if (!is_array($size)) {
            return false;
        }
        [$width, $height] = $size;
        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            return false;
        }
        return $width * $height <= self::MAX_PIXELS;
    }
}
