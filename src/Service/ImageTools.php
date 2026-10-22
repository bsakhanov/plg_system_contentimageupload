<?php

/**
 * @package     Webmarka.Plugin
 * @subpackage  System.contentimageupload
 *
 * @copyright   (C) 2026 Beibit Sakhanov <https://webmarka.kz>
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Webmarka\Plugin\System\ContentImageUpload\Service;

use Joomla\CMS\Language\Text;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Small GD helpers shared by the upload processor and the watermark service.
 *
 * @since  1.7.0
 */
final class ImageTools
{
    /**
     * Decodes an image file into a true colour GD image with alpha and EXIF orientation applied.
     *
     * @param   string  $file  Absolute path of the image.
     *
     * @return  \GdImage
     *
     * @throws  \RuntimeException
     *
     * @since   1.7.0
     */
    public static function load(string $file): \GdImage
    {
        $info = @getimagesize($file);

        if ($info === false || empty($info[0]) || empty($info[1])) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_NOT_IMAGE'));
        }

        $orientation = ($info[2] ?? 0) === IMAGETYPE_JPEG ? self::readOrientation($file) : 1;

        self::assertMemory((int) $info[0], (int) $info[1], $orientation > 1 ? 2 : 1);

        $data  = @file_get_contents($file);
        $image = $data === false ? false : @imagecreatefromstring($data);

        unset($data);

        if (!$image instanceof \GdImage) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_NOT_IMAGE'));
        }

        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        $image = self::applyOrientation($image, $orientation);

        imagealphablending($image, true);
        imagesavealpha($image, true);

        return $image;
    }

    /**
     * Encodes a GD image into a file of the given format.
     *
     * @param   \GdImage  $image           The image.
     * @param   string    $file            Target file.
     * @param   string    $extension       Target format by extension.
     * @param   int       $quality         JPEG, WebP and AVIF quality (1–100).
     * @param   int       $pngCompression  PNG compression level (0–9).
     *
     * @return  void
     *
     * @throws  \RuntimeException
     *
     * @since   1.7.0
     */
    public static function save(\GdImage $image, string $file, string $extension, int $quality, int $pngCompression): void
    {
        $quality = min(100, max(1, $quality));

        $saved = match (strtolower($extension)) {
            'jpg', 'jpeg' => imagejpeg($image, $file, $quality),
            'png'         => imagepng($image, $file, min(9, max(0, $pngCompression))),
            'gif'         => imagegif($image, $file),
            'webp'        => \function_exists('imagewebp') && imagewebp($image, $file, $quality),
            'bmp'         => \function_exists('imagebmp') && imagebmp($image, $file),
            'avif'        => \function_exists('imageavif') && imageavif($image, $file, $quality),
            default       => false,
        };

        clearstatcache(true, $file);

        if (!$saved || !is_file($file) || filesize($file) === 0) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_PROCESSING'));
        }
    }

    /**
     * Creates a fully transparent true colour canvas.
     *
     * @param   int  $width   Width in pixels.
     * @param   int  $height  Height in pixels.
     *
     * @return  \GdImage
     *
     * @since   1.7.0
     */
    public static function createCanvas(int $width, int $height): \GdImage
    {
        $canvas = imagecreatetruecolor(max(1, $width), max(1, $height));

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        return $canvas;
    }

    /**
     * Multiplies the alpha of every pixel by the given opacity, keeping soft edges soft.
     *
     * @param   \GdImage  $image    True colour image with alpha.
     * @param   int       $opacity  Opacity in percent.
     *
     * @return  void
     *
     * @since   1.7.0
     */
    public static function fadeAlpha(\GdImage $image, int $opacity): void
    {
        $factor = min(100, max(0, $opacity)) / 100;
        $map    = [];

        for ($alpha = 0; $alpha <= 127; $alpha++) {
            $map[$alpha] = 127 - (int) round((127 - $alpha) * $factor);
        }

        $width  = imagesx($image);
        $height = imagesy($image);

        imagealphablending($image, false);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($image, $x, $y);
                $alpha = ($color >> 24) & 0x7F;

                if ($alpha === 127) {
                    continue;
                }

                imagesetpixel($image, $x, $y, ($map[$alpha] << 24) | ($color & 0xFFFFFF));
            }
        }

        imagesavealpha($image, true);
    }

    /**
     * Reads the EXIF orientation of a JPEG file.
     *
     * @param   string  $file  Absolute path of the file.
     *
     * @return  int  1–8, where 1 means «as stored».
     *
     * @since   1.7.0
     */
    public static function readOrientation(string $file): int
    {
        if (!\function_exists('exif_read_data')) {
            return 1;
        }

        $exif  = @exif_read_data($file, 'IFD0');
        $value = \is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $value >= 1 && $value <= 8 ? $value : 1;
    }

    /**
     * Turns the pixels the way the EXIF orientation says, because re-encoding drops EXIF.
     *
     * @param   \GdImage  $image        The image.
     * @param   int       $orientation  EXIF orientation 1–8.
     *
     * @return  \GdImage
     *
     * @since   1.7.0
     */
    public static function applyOrientation(\GdImage $image, int $orientation): \GdImage
    {
        if ($orientation === 2) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        } elseif ($orientation >= 3 && $orientation <= 8) {
            $angle   = match ($orientation) {
                3       => 180,
                5, 8    => 90,
                default => -90,
            };
            $rotated = imagerotate($image, $angle, 0);

            if ($rotated instanceof \GdImage) {
                $image = $rotated;

                if ($orientation === 5 || $orientation === 7) {
                    imageflip($image, IMG_FLIP_VERTICAL);
                }
            }
        }

        return $image;
    }

    /**
     * Stops before GD runs out of memory: a fatal error cannot be caught, an exception can.
     *
     * @param   int  $width   Image width.
     * @param   int  $height  Image height.
     * @param   int  $copies  How many full-size copies will exist at once.
     *
     * @return  void
     *
     * @throws  \RuntimeException
     *
     * @since   1.7.0
     */
    public static function assertMemory(int $width, int $height, int $copies = 1): void
    {
        $limit = self::getMemoryLimit();

        if ($limit <= 0) {
            return;
        }

        $needed = $width * $height * 5 * max(1, $copies) + 8 * 1024 * 1024;

        if (memory_get_usage(true) + $needed > $limit) {
            throw new \RuntimeException(Text::sprintf('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_MEMORY', $width, $height));
        }
    }

    /**
     * Returns the PHP memory limit in bytes, or -1 when unlimited.
     *
     * @return  int
     *
     * @since   1.7.0
     */
    private static function getMemoryLimit(): int
    {
        $value = trim((string) \ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g'     => $number * 1024 ** 3,
            'm'     => $number * 1024 ** 2,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}
