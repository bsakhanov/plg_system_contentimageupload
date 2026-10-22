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

\defined('_JEXEC') or die;

/**
 * Contract for image processor backends.
 *
 * @since  1.0.0
 */
interface ImageProcessorBackendInterface
{
    /**
     * Returns backend identifier used by plugin parameters.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    public function getName(): string;

    /**
     * Checks whether the backend can be used in the current runtime.
     *
     * @return  bool
     *
     * @since   1.0.0
     */
    public function isAvailable(): bool;

    /**
     * Processes source image bytes.
     *
     * @param   string                $data             Source image bytes.
     * @param   string                $sourceExtension  Source file extension.
     * @param   array<string, mixed>  $options          Processing options.
     *
     * @return  array{data: string, extension: string}|null
     *
     * @since   1.0.0
     */
    public function process(string $data, string $sourceExtension, array $options): ?array;
}
