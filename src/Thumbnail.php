<?php

declare(strict_types=1);

namespace Dosiero;

use GdImage;

use function function_exists;

class Thumbnail
{

    /**
     * returns thumbnail as base64 data uri
     * @param string $file
     * @param int $maxSize
     * @return string
     */
    public static function createThumbnailFromFile(string $file, int $maxSize = 50): string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $ext = str_replace('jpeg', 'jpg', $ext);
        return self::createThumbnailFromString((string)file_get_contents($file), $ext, $maxSize);
    }

    private static function imageToData(GdImage $image, string $ext): string
    {
        $imageFunction = 'image' . str_replace('jpg', 'jpeg', $ext);
        if (!function_exists($imageFunction)) {
            return '';
        }
        ob_start();
        /** @var callable $imageFunction */
        $imageFunction($image);
        return (string)ob_get_clean();
    }

    public static function createThumbnailFromString(string $image, string $ext, int $maxSize = 50): string
    {
        if (empty($image)) {
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

        $imgThumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
        if (!$imgThumb) {
            return '';
        }

        if ($ext === 'gif') {
            $transparentIndex = (int)imagecolortransparent($imgOrig);
            if ($transparentIndex >= 0) {
                // get original image's transparent color's RGB values
                /**  @var array<string, int> $transparentColor */
                $transparentColor = imagecolorsforindex($imgOrig, $transparentIndex);
                // allocate the same color in the new image
                $transparentIndex = (int)imagecolorallocate(
                    $imgThumb,
                    $transparentColor['red'],
                    $transparentColor['green'],
                    $transparentColor['blue']
                );
                // fill the background of the new image with allocated color
                imagefill($imgThumb, 0, 0, $transparentIndex);
                // set the background color to transparent
                imagecolortransparent($imgThumb, $transparentIndex);
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
            $origHeight
        );

        $imageData = self::imageToData($imgThumb, $ext);
        imagedestroy($imgOrig);
        imagedestroy($imgThumb);
        $mimes = [
            'png' => 'image/png',
            'jpeg' => 'image/jpg',
            'jpg' => 'image/jpg',
            'gif' => 'image/gif',
        ];
        $mime = $mimes[$ext] ?? 'image/jpg';

        return $imageData === '' ? '' : 'data:' . $mime . ';base64,' . base64_encode($imageData);
    }
}
