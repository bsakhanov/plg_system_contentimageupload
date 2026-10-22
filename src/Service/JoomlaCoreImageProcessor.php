<?php

/**
 * @package     Webmarka.Plugin
 * @subpackage  System.contentimageupload
 *
 * @copyright   (C) 2026 Beibit Sakhanov <https://webmarka.kz>
 * @copyright   Based on Content Image Upload 1.6, (C) 2026 Хабар
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Webmarka\Plugin\System\ContentImageUpload\Service;

use Joomla\CMS\Image\Image;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Resize and format conversion on upload, through the Joomla core Image class (GD).
 * The watermark lives in WatermarkService.
 *
 * @since  1.0.0
 */
final class JoomlaCoreImageProcessor implements ImageProcessorBackendInterface
{
    /**
     * Returns backend identifier used by plugin parameters.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    public function getName(): string
    {
        return 'joomla-core';
    }

    /**
     * Checks whether the backend can be used in the current runtime.
     *
     * @return  bool
     *
     * @since   1.0.0
     */
    public function isAvailable(): bool
    {
        return class_exists(Image::class) && extension_loaded('gd');
    }

    /**
     * Processes source image bytes.
     *
     * @param   string                $data             Source image bytes.
     * @param   string                $sourceExtension  Source file extension.
     * @param   array<string, mixed>  $options          Processing options.
     *
     * @return  array{data: string, extension: string}|null
     *
     * @throws  \RuntimeException
     *
     * @since   1.0.0
     */
    public function process(string $data, string $sourceExtension, array $options): ?array
    {
        $sourceExtension = $this->normalizeExtension($sourceExtension);

        if (!$this->canDecode($sourceExtension)) {
            return null;
        }

        $targetExtension = $this->normalizeExtension((string) ($options['target_format'] ?? 'keep'));
        $targetExtension = $targetExtension === 'keep' ? $sourceExtension : $targetExtension;

        if (!$this->canEncode($targetExtension)) {
            return null;
        }

        $size = @getimagesizefromstring($data);

        if (\is_array($size)) {
            ImageTools::assertMemory((int) $size[0], (int) $size[1], 2);
        }

        $source = @imagecreatefromstring($data);

        if (!$source instanceof \GdImage) {
            return null;
        }

        // Re-encoding drops EXIF, so phone photos must be turned now or they stay sideways.
        if ($sourceExtension === 'jpg' && !empty($options['source_file'])) {
            $source = ImageTools::applyOrientation($source, ImageTools::readOrientation((string) $options['source_file']));
        }

        $sourceImage = new Image($source);

        try {
            $image = $this->resizeImage($sourceImage, $options);

            return [
                'data' => $this->encode($image, $targetExtension, $options),
                'extension' => $targetExtension,
            ];
        } finally {
            $sourceImage->destroy();

            if (isset($image) && $image instanceof Image && $image !== $sourceImage) {
                $image->destroy();
            }
        }
    }

    /**
     * Normalizes supported extensions.
     *
     * @param   string  $extension  File extension.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function normalizeExtension(string $extension): string
    {
        $extension = strtolower(trim($extension, ". \t\n\r\x00\x0B"));

        return $extension === 'jpeg' ? 'jpg' : $extension;
    }

    /**
     * Checks whether GD can decode the source format.
     *
     * @param   string  $extension  Source extension.
     *
     * @return  bool
     *
     * @since   1.0.0
     */
    private function canDecode(string $extension): bool
    {
        return match ($extension) {
            'gif' => function_exists('imagecreatefromgif'),
            'jpg' => function_exists('imagecreatefromjpeg'),
            'png' => function_exists('imagecreatefrompng'),
            'webp' => function_exists('imagecreatefromwebp'),
            'avif' => function_exists('imagecreatefromavif'),
            'bmp' => function_exists('imagecreatefrombmp'),
            default => false,
        };
    }

    /**
     * Checks whether GD can encode the target format.
     *
     * @param   string  $extension  Target extension.
     *
     * @return  bool
     *
     * @since   1.0.0
     */
    private function canEncode(string $extension): bool
    {
        return match ($extension) {
            'gif' => function_exists('imagegif'),
            'jpg' => function_exists('imagejpeg'),
            'png' => function_exists('imagepng'),
            'webp' => function_exists('imagewebp'),
            'avif' => function_exists('imageavif'),
            'bmp' => function_exists('imagebmp'),
            default => false,
        };
    }

    /**
     * Resizes the source image according to options.
     *
     * @param   Image                 $source   Source image.
     * @param   array<string, mixed>  $options  Processing options.
     *
     * @return  Image
     *
     * @since   1.0.0
     */
    private function resizeImage(Image $source, array $options): Image
    {
        $sourceWidth = $source->getWidth();
        $sourceHeight = $source->getHeight();
        $maxWidth = max(0, (int) ($options['max_width'] ?? 0));
        $maxHeight = max(0, (int) ($options['max_height'] ?? 0));
        $allowUpscale = (bool) ($options['allow_upscale'] ?? false);
        $fillColor = $this->normalizeFillColor((string) ($options['image_fill_color'] ?? '#ffffff'));
        $mode = (string) ($options['mode'] ?? 'none');

        if ($mode === 'none') {
            return $source;
        }

        if ($mode === 'crop' && $maxWidth > 0 && $maxHeight > 0) {
            if (!$allowUpscale) {
                $maxWidth = min($maxWidth, $sourceWidth);
                $maxHeight = min($maxHeight, $sourceHeight);
            }

            if ($maxWidth === $sourceWidth && $maxHeight === $sourceHeight) {
                return $source;
            }

            return $source->cropResize($maxWidth, $maxHeight);
        }

        if ($mode === 'fit' && $maxWidth > 0 && $maxHeight > 0) {
            if (!$allowUpscale) {
                $maxWidth = min($maxWidth, $sourceWidth);
                $maxHeight = min($maxHeight, $sourceHeight);
            }

            if ($maxWidth === $sourceWidth && $maxHeight === $sourceHeight) {
                return $source;
            }

            return $this->fitOnCanvas($source, $maxWidth, $maxHeight, $fillColor);
        }

        [$targetWidth, $targetHeight] = $this->calculateFitSize(
            $sourceWidth,
            $sourceHeight,
            $maxWidth,
            $maxHeight,
            $mode,
            $allowUpscale
        );

        if ($targetWidth === $sourceWidth && $targetHeight === $sourceHeight) {
            return $source;
        }

        return $source->resize($targetWidth, $targetHeight, true, Image::SCALE_FILL);
    }

    /**
     * Fits the source image onto a filled canvas while preserving proportions.
     *
     * @since   1.0.0
     */
    private function fitOnCanvas(Image $source, int $canvasWidth, int $canvasHeight, string $fillColor): Image
    {
        [$imageWidth, $imageHeight] = $this->calculateFitSize(
            $source->getWidth(),
            $source->getHeight(),
            $canvasWidth,
            $canvasHeight,
            'fit',
            true
        );

        $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);

        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);
        imagefilledrectangle(
            $canvas,
            0,
            0,
            $canvasWidth,
            $canvasHeight,
            $this->allocateFillColor($canvas, $fillColor)
        );

        imagecopyresampled(
            $canvas,
            $source->getHandle(),
            (int)round(($canvasWidth - $imageWidth) / 2),
            (int)round(($canvasHeight - $imageHeight) / 2),
            0,
            0,
            $imageWidth,
            $imageHeight,
            $source->getWidth(),
            $source->getHeight()
        );

        return new Image($canvas);
    }

    /**
     * Allocates the configured fill color on a GD image.
     *
     * @since   1.0.0
     */
    private function allocateFillColor(\GdImage $image, string $color): int
    {
        $hex = ltrim($color, '#');
        $alpha = strlen($hex) === 8 ? (int)floor((255 - hexdec(substr($hex, 6, 2))) / 2) : 0;

        $color = imagecolorallocatealpha(
            $image,
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
            min(127, max(0, $alpha))
        );

        return $color === false ? 0 : $color;
    }

    /**
     * Normalizes the configured fill color for GD allocation.
     *
     * @since   1.0.0
     */
    private function normalizeFillColor(string $color): string
    {
        $color = trim($color);

        if (preg_match('/^#[0-9a-f]{6}([0-9a-f]{2})?$/i', $color)) {
            return $color;
        }

        if (preg_match('/^[0-9a-f]{6}([0-9a-f]{2})?$/i', $color)) {
            return '#' . $color;
        }

        return '#ffffff';
    }

    /**
     * Calculates proportional target size.
     *
     * @return  array{0: int, 1: int}
     *
     * @since   1.0.0
     */
    private function calculateFitSize(
        int $sourceWidth,
        int $sourceHeight,
        int $maxWidth,
        int $maxHeight,
        string $mode,
        bool $allowUpscale
    ): array {
        if ($maxWidth <= 0 && $maxHeight <= 0) {
            return [$sourceWidth, $sourceHeight];
        }

        $ratio = match ($mode) {
            'width' => $maxWidth > 0 ? $maxWidth / $sourceWidth : 1.0,
            'height' => $maxHeight > 0 ? $maxHeight / $sourceHeight : 1.0,
            default => min(
                $maxWidth > 0 ? $maxWidth / $sourceWidth : PHP_FLOAT_MAX,
                $maxHeight > 0 ? $maxHeight / $sourceHeight : PHP_FLOAT_MAX
            ),
        };

        if (!$allowUpscale) {
            $ratio = min(1.0, $ratio);
        }

        return [
            max(1, (int)round($sourceWidth * $ratio)),
            max(1, (int)round($sourceHeight * $ratio)),
        ];
    }

    /**
     * Encodes the image to target format.
     *
     * @param   Image                 $image      Target image.
     * @param   string                $extension  Target extension.
     * @param   array<string, mixed>  $options    Processing options.
     *
     * @return  string
     *
     * @throws  \RuntimeException|\Throwable
     *
     * @since   1.0.0
     */
    private function encode(Image $image, string $extension, array $options): string
    {
        $outputImage = $extension === 'jpg' ? $this->flattenForJpeg($image) : $image;
        $quality = $extension === 'png'
            ? min(9, max(0, (int) ($options['png_compression'] ?? 6)))
            : min(100, max(1, (int) ($options['quality'] ?? 82)));

        ob_start();

        try {
            $success = $outputImage->toFile(null, $this->imageTypeFromExtension($extension), ['quality' => $quality]);
            $data = ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();

            throw $exception;
        } finally {
            if ($outputImage !== $image) {
                $outputImage->destroy();
            }
        }

        if (!$success || !\is_string($data) || $data === '') {
            throw new \RuntimeException('Could not encode processed image.');
        }

        return $data;
    }

    /**
     * Maps a normalized extension to the GD image type expected by Joomla Image.
     *
     * @since   1.0.0
     */
    private function imageTypeFromExtension(string $extension): int
    {
        return match ($extension) {
            'avif' => IMAGETYPE_AVIF,
            'gif' => IMAGETYPE_GIF,
            'png' => IMAGETYPE_PNG,
            'webp' => IMAGETYPE_WEBP,
            'bmp' => IMAGETYPE_BMP,
            default => IMAGETYPE_JPEG,
        };
    }

    /**
     * Flattens alpha onto a white background for JPEG output.
     *
     * @since   1.0.0
     */
    private function flattenForJpeg(Image $image): Image
    {
        $flattened = $image->resize($image->getWidth(), $image->getHeight(), true, Image::SCALE_FILL);
        $flattened->filter('backgroundfill', ['color' => '#FFFFFFFF']);

        return $flattened;
    }
}
