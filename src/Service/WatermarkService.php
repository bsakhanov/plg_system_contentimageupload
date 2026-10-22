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
 * Stamps a watermark onto an image file (GD).
 *
 * @since  1.7.0
 */
final class WatermarkService
{
    /**
     * Allowed positions.
     *
     * @var    string[]
     * @since  1.7.0
     */
    public const POSITIONS = ['top_left', 'top_right', 'center', 'bottom_left', 'bottom_right'];

    /**
     * @param   string  $watermarkFile  Absolute path of the watermark image.
     * @param   string  $position       One of self::POSITIONS.
     * @param   int     $opacity        Opacity in percent.
     * @param   int     $scale          Watermark width in percent of the image width.
     * @param   int     $marginX        Horizontal margin in pixels.
     * @param   int     $marginY        Vertical margin in pixels.
     *
     * @since   1.7.0
     */
    public function __construct(
        private readonly string $watermarkFile,
        private readonly string $position = 'bottom_right',
        private readonly int $opacity = 80,
        private readonly int $scale = 20,
        private readonly int $marginX = 24,
        private readonly int $marginY = 24
    ) {
    }

    /**
     * Stamps the watermark onto a file in place (through a temporary file and an atomic rename).
     *
     * @param   string  $file            Absolute path of the image.
     * @param   string  $extension       Format to write, normally the file's own.
     * @param   int     $quality         JPEG, WebP and AVIF quality.
     * @param   int     $pngCompression  PNG compression level.
     *
     * @return  void
     *
     * @throws  \RuntimeException
     *
     * @since   1.7.0
     */
    public function applyToFile(string $file, string $extension, int $quality, int $pngCompression): void
    {
        $image = ImageTools::load($file);

        $this->stamp($image);

        $tmp = \dirname($file) . '/.ciu-' . bin2hex(random_bytes(8)) . '.tmp';

        try {
            ImageTools::save($image, $tmp, $extension, $quality, $pngCompression);

            if (!@rename($tmp, $file)) {
                throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_REPLACE'));
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        @chmod($file, 0644);
        clearstatcache(true, $file);
    }

    /**
     * Stamps the watermark onto a GD image.
     *
     * @param   \GdImage  $image  True colour image.
     *
     * @return  void
     *
     * @since   1.7.0
     */
    public function stamp(\GdImage $image): void
    {
        $mark        = ImageTools::load($this->watermarkFile);
        $imageWidth  = imagesx($image);
        $imageHeight = imagesy($image);

        [$width, $height] = $this->getSize($imageWidth, $imageHeight, imagesx($mark), imagesy($mark));

        $scaled = ImageTools::createCanvas($width, $height);

        imagecopyresampled($scaled, $mark, 0, 0, 0, 0, $width, $height, imagesx($mark), imagesy($mark));

        if ($this->opacity < 100) {
            ImageTools::fadeAlpha($scaled, $this->opacity);
        }

        [$x, $y] = $this->getCoordinates($imageWidth, $imageHeight, $width, $height);

        imagealphablending($image, true);
        imagecopy($image, $scaled, $x, $y, 0, 0, $width, $height);
    }

    /**
     * Width from the scale setting, height from the watermark's proportions, shrunk to fit.
     *
     * @return  int[]
     *
     * @since   1.7.0
     */
    private function getSize(int $imageWidth, int $imageHeight, int $markWidth, int $markHeight): array
    {
        $width   = max(1, (int) round($imageWidth * min(100, max(1, $this->scale)) / 100));
        $height  = max(1, (int) round($width * $markHeight / max(1, $markWidth)));
        $centred = $this->position === 'center';
        $roomX   = max(1, $imageWidth - ($centred ? 0 : $this->marginX));
        $roomY   = max(1, $imageHeight - ($centred ? 0 : $this->marginY));
        $ratio   = min(1.0, $roomX / $width, $roomY / $height);

        if ($ratio < 1.0) {
            $width  = max(1, (int) floor($width * $ratio));
            $height = max(1, (int) floor($height * $ratio));
        }

        return [$width, $height];
    }

    /**
     * Top left corner of the watermark.
     *
     * @return  int[]
     *
     * @since   1.7.0
     */
    private function getCoordinates(int $imageWidth, int $imageHeight, int $width, int $height): array
    {
        if ($this->position === 'center') {
            return [max(0, intdiv($imageWidth - $width, 2)), max(0, intdiv($imageHeight - $height, 2))];
        }

        $x = str_ends_with($this->position, '_right') ? $imageWidth - $width - $this->marginX : $this->marginX;
        $y = str_starts_with($this->position, 'bottom_') ? $imageHeight - $height - $this->marginY : $this->marginY;

        return [max(0, $x), max(0, $y)];
    }
}
