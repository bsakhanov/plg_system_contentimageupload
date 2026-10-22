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
 * Keeps the originals of watermarked images, so a watermark can be taken off.
 *
 * A kept original is also the mark «this file carries our watermark». Originals live in
 * images/.contentimageupload: the Media Manager hides dot folders, the cache cleaner does
 * not reach them, and the file names are HMAC hashes of the image path with the site secret,
 * so nobody can compute the address of a clean original from the public address of an image.
 *
 * @since  1.7.0
 */
final class OriginalStore
{
    /**
     * Store folder relative to the site root.
     *
     * @var    string
     * @since  1.7.0
     */
    public const FOLDER = 'images/.contentimageupload';

    /**
     * @param   string  $siteRoot      JPATH_ROOT.
     * @param   string  $secret        Site secret from the global configuration.
     * @param   string  $legacyFolder  Folder used by versions up to 1.6 (administrator cache).
     *
     * @since   1.7.0
     */
    public function __construct(
        private readonly string $siteRoot,
        private readonly string $secret,
        private readonly string $legacyFolder = ''
    ) {
    }

    /**
     * Whether the image carries a watermark put by this plugin.
     *
     * @param   string  $relativePath  Image path relative to the site root.
     *
     * @return  bool
     *
     * @since   1.7.0
     */
    public function has(string $relativePath): bool
    {
        $relativePath = self::normalise($relativePath);
        $original     = $this->locate($relativePath);

        if ($original === null) {
            return false;
        }

        $image = $this->siteRoot . '/' . $relativePath;

        // A kept copy equal to the current file means an interrupted run, not a watermark.
        if (is_file($image) && self::isSameContent($original, $image)) {
            @unlink($original);

            return false;
        }

        return true;
    }

    /**
     * Keeps the original before the first watermark; an existing copy is never overwritten.
     *
     * @param   string  $relativePath  Image path relative to the site root.
     * @param   string  $sourceFile    Absolute path of the image as it is now.
     *
     * @return  bool  True when a copy was made by this call.
     *
     * @throws  \RuntimeException
     *
     * @since   1.7.0
     */
    public function save(string $relativePath, string $sourceFile): bool
    {
        $relativePath = self::normalise($relativePath);

        if ($this->locate($relativePath) !== null) {
            return false;
        }

        $original = $this->getOriginalFile($relativePath);

        $this->prepareFolder(\dirname($original));

        $tmp = $original . '.' . bin2hex(random_bytes(6)) . '.tmp';

        try {
            if (!@copy($sourceFile, $tmp) || !@rename($tmp, $original)) {
                throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_ORIGINAL_SAVE'));
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        @chmod($original, 0644);

        return true;
    }

    /**
     * Puts the original back in place of the watermarked file and forgets the copy.
     *
     * @param   string  $relativePath  Image path relative to the site root.
     * @param   string  $targetFile    Absolute path of the image.
     *
     * @return  void
     *
     * @throws  \RuntimeException
     *
     * @since   1.7.0
     */
    public function restore(string $relativePath, string $targetFile): void
    {
        $original = $this->locate(self::normalise($relativePath));

        if ($original === null) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_ORIGINAL_MISSING'));
        }

        $tmp = \dirname($targetFile) . '/.ciu-' . bin2hex(random_bytes(8)) . '.tmp';

        try {
            if (!@copy($original, $tmp) || !@rename($tmp, $targetFile)) {
                throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_REPLACE'));
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        @chmod($targetFile, 0644);
        clearstatcache(true, $targetFile);
        @unlink($original);
    }

    /**
     * Drops a copy made for a watermark that failed.
     *
     * @param   string  $relativePath  Image path relative to the site root.
     *
     * @return  void
     *
     * @since   1.7.0
     */
    public function discard(string $relativePath): void
    {
        $original = $this->getOriginalFile(self::normalise($relativePath));

        if (is_file($original)) {
            @unlink($original);
        }
    }

    /**
     * Finds the kept original, adopting a copy left by version 1.6 in the administrator cache.
     *
     * @param   string  $relativePath  Normalised image path.
     *
     * @return  string|null
     *
     * @since   1.7.0
     */
    private function locate(string $relativePath): ?string
    {
        $original = $this->getOriginalFile($relativePath);

        if (is_file($original)) {
            return $original;
        }

        if ($this->legacyFolder === '') {
            return null;
        }

        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $legacy    = $this->legacyFolder . '/' . hash('sha256', $relativePath) . ($extension !== '' ? '.' . $extension : '');

        if (!is_file($legacy)) {
            return null;
        }

        try {
            $this->prepareFolder(\dirname($original));
        } catch (\RuntimeException) {
            return $legacy;
        }

        if (!@rename($legacy, $original)) {
            return $legacy;
        }

        @chmod($original, 0644);

        return $original;
    }

    /**
     * Path of the kept original: the name is an HMAC of the image path, sharded by its first two characters.
     *
     * @param   string  $relativePath  Normalised image path.
     *
     * @return  string
     *
     * @since   1.7.0
     */
    private function getOriginalFile(string $relativePath): string
    {
        $hash = hash_hmac('sha256', $relativePath, $this->secret);

        return $this->siteRoot . '/' . self::FOLDER . '/' . substr($hash, 0, 2) . '/' . $hash . '.bin';
    }

    /**
     * Creates the store folders with files that deny web access.
     *
     * @param   string  $folder  Shard folder.
     *
     * @return  void
     *
     * @throws  \RuntimeException
     *
     * @since   1.7.0
     */
    private function prepareFolder(string $folder): void
    {
        $root = $this->siteRoot . '/' . self::FOLDER;

        foreach ([$root, $folder] as $directory) {
            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_ORIGINAL_SAVE'));
            }
        }

        $index  = "<!DOCTYPE html><title></title>\n";
        $guards = [
            'index.html' => $index,
            '.htaccess'  => "# Content Image Upload: originals of watermarked images. No web access.\n"
                . "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<security>\n"
                . "\t\t\t<requestFiltering>\n\t\t\t\t<fileExtensions allowUnlisted=\"false\" />\n\t\t\t</requestFiltering>\n"
                . "\t\t</security>\n\t</system.webServer>\n</configuration>\n",
        ];

        foreach ($guards as $name => $content) {
            if (!is_file($root . '/' . $name)) {
                @file_put_contents($root . '/' . $name, $content);
            }
        }

        if (!is_file($folder . '/index.html')) {
            @file_put_contents($folder . '/index.html', $index);
        }
    }

    /**
     * @param   string  $path  Path with any slashes.
     *
     * @return  string
     *
     * @since   1.7.0
     */
    private static function normalise(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * @param   string  $first   File.
     * @param   string  $second  File.
     *
     * @return  bool
     *
     * @since   1.7.0
     */
    private static function isSameContent(string $first, string $second): bool
    {
        clearstatcache(true, $first);
        clearstatcache(true, $second);

        return filesize($first) === filesize($second) && hash_file('sha256', $first) === hash_file('sha256', $second);
    }
}
