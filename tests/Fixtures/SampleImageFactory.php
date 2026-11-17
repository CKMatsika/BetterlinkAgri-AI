<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Generates real binary JPEG sample images for testing MediaIntakeService quality gates
 * and end-to-end photo diagnosis flows.
 */
final class SampleImageFactory
{
    /**
     * Generate a sharp, well-lit daylight leaf photo with high Laplacian edge variance (>200)
     * and normal brightness (~125).
     */
    public static function createSharpLeafImage(int $width = 800, int $height = 600): string
    {
        $img = imagecreatetruecolor($width, $height);
        $greenBg = imagecolorallocate($img, 60, 145, 55);
        $veinLight = imagecolorallocate($img, 210, 235, 180);
        $lesionDark = imagecolorallocate($img, 45, 35, 25);

        imagefill($img, 0, 0, $greenBg);

        // Draw high-contrast leaf veins and checkerboard feeding lesions to ensure sharp edges
        for ($y = 20; $y < $height - 20; $y += 8) {
            for ($x = 20; $x < $width - 20; $x += 8) {
                $color = (($x + $y) % 16 === 0) ? $veinLight : $lesionDark;
                imagefilledrectangle($img, $x, $y, $x + 3, $y + 3, $color);
            }
        }

        ob_start();
        imagejpeg($img, null, 92);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /**
     * Generate a blurry / flat-gradient image with near-zero Laplacian variance (<5.0).
     */
    public static function createBlurryLeafImage(int $width = 800, int $height = 600): string
    {
        $img = imagecreatetruecolor($width, $height);
        $flatGreen = imagecolorallocate($img, 95, 130, 85);
        imagefill($img, 0, 0, $flatGreen);

        ob_start();
        imagejpeg($img, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /**
     * Generate a pitch-dark image with mean luminance < 35.0.
     */
    public static function createDarkNightImage(int $width = 800, int $height = 600): string
    {
        $img = imagecreatetruecolor($width, $height);
        $darkColor = imagecolorallocate($img, 8, 12, 8);
        imagefill($img, 0, 0, $darkColor);

        ob_start();
        imagejpeg($img, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /**
     * Generate a tiny low-resolution image (<400px minimum dimension).
     */
    public static function createTinyLowResImage(int $width = 220, int $height = 180): string
    {
        return self::createSharpLeafImage($width, $height);
    }
}
