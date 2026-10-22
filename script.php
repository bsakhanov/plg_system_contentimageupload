<?php

/**
 * @package     Webmarka.Plugin
 * @subpackage  System.contentimageupload
 *
 * @copyright   (C) 2026 Beibit Sakhanov <https://webmarka.kz>
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the installer script.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   1.7.0
     */
    public function register(Container $container): void
    {
        $container->set(
            InstallerScriptInterface::class,
            new class () implements InstallerScriptInterface, DatabaseAwareInterface {
                use DatabaseAwareTrait;

                /**
                 * Minimum versions.
                 *
                 * @since  1.7.0
                 */
                private const MINIMUM_PHP    = '8.3.0';
                private const MINIMUM_JOOMLA = '6.0.0';

                /**
                 * Application, set by the installer.
                 *
                 * @var    CMSApplicationInterface|null
                 * @since  1.7.0
                 */
                private ?CMSApplicationInterface $application = null;

                /**
                 * @param   CMSApplicationInterface  $application  The application.
                 *
                 * @return  void
                 *
                 * @since   1.7.0
                 */
                public function setApplication(CMSApplicationInterface $application): void
                {
                    $this->application = $application;
                }

                public function install(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function update(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function uninstall(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                /**
                 * Stops the installation on an unsupported PHP or Joomla version.
                 *
                 * @param   string            $type     install, discover_install, update or uninstall.
                 * @param   InstallerAdapter  $adapter  The adapter.
                 *
                 * @return  bool
                 *
                 * @since   1.7.0
                 */
                public function preflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type === 'uninstall') {
                        return true;
                    }

                    if (version_compare(PHP_VERSION, self::MINIMUM_PHP, '<')) {
                        $this->application?->enqueueMessage(Text::sprintf('JLIB_INSTALLER_MINIMUM_PHP', self::MINIMUM_PHP), 'error');

                        return false;
                    }

                    if (version_compare(JVERSION, self::MINIMUM_JOOMLA, '<')) {
                        $this->application?->enqueueMessage(Text::sprintf('JLIB_INSTALLER_MINIMUM_JOOMLA', self::MINIMUM_JOOMLA), 'error');

                        return false;
                    }

                    return true;
                }

                /**
                 * Fills settings introduced after 1.4 from older keys; never overwrites existing values.
                 *
                 * @param   string            $type     install, discover_install, update or uninstall.
                 * @param   InstallerAdapter  $adapter  The adapter.
                 *
                 * @return  bool
                 *
                 * @since   1.7.0
                 */
                public function postflight(string $type, InstallerAdapter $adapter): bool
                {
                    if (!\in_array($type, ['install', 'discover_install', 'update'], true)) {
                        return true;
                    }

                    try {
                        $this->migrateSettings();
                    } catch (\Throwable $exception) {
                        $this->application?->enqueueMessage('Content Image Upload: ' . $exception->getMessage(), 'warning');
                    }

                    return true;
                }

                /**
                 * @return  void
                 *
                 * @since   1.7.0
                 */
                private function migrateSettings(): void
                {
                    $db      = $this->getDatabase();
                    $element = 'contentimageupload';
                    $folder  = 'system';
                    $query   = $db->getQuery(true)
                        ->select($db->quoteName(['extension_id', 'params']))
                        ->from($db->quoteName('#__extensions'))
                        ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                        ->where($db->quoteName('element') . ' = :element')
                        ->where($db->quoteName('folder') . ' = :folder')
                        ->bind(':element', $element)
                        ->bind(':folder', $folder);

                    $plugin = $db->setQuery($query)->loadObject();

                    if (!$plugin) {
                        return;
                    }

                    $params  = json_decode((string) $plugin->params, true);
                    $params  = \is_array($params) ? $params : [];
                    $current = $params;

                    if (trim((string) ($params['folder_format'] ?? '')) === '' && isset($params['base_folder'])) {
                        $base    = trim((string) $params['base_folder'], '/') ?: 'images';
                        $pattern = [
                            'd/m/Y' => '{day}/{month}/{year}',
                            'Y/m'   => '{year}/{month}',
                        ][(string) ($params['date_format'] ?? '')] ?? '{year}/{month}/{day}';

                        $params['folder_format'] = $base . '/' . $pattern;
                    }

                    if (!\array_key_exists('processing_enabled', $params) && (isset($params['resize_enabled']) || isset($params['convert_webp']))) {
                        $resize = (int) ($params['resize_enabled'] ?? 0) === 1;
                        $width  = (int) ($params['max_width'] ?? 0);
                        $height = (int) ($params['max_height'] ?? 0);

                        $params['processing_enabled'] = (int) ($resize || (int) ($params['convert_webp'] ?? 0) === 1);
                        $params['resize_mode']        = !$resize ? 'none' : ($width > 0 && $height > 0 ? 'fit' : ($width > 0 ? 'width' : 'height'));
                        $params['output_format']      = (int) ($params['convert_webp'] ?? 0) === 1 ? 'webp' : 'keep';
                        $params['quality']            = (int) ($params['webp_quality'] ?? $params['jpg_quality'] ?? 82);
                    }

                    foreach (['watermark_margin_x', 'watermark_margin_y'] as $key) {
                        if (!\array_key_exists($key, $params) && isset($params['watermark_margin'])) {
                            $params[$key] = (int) $params['watermark_margin'];
                        }
                    }

                    if ($params === $current) {
                        return;
                    }

                    $json   = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    $id     = (int) $plugin->extension_id;
                    $update = $db->getQuery(true)
                        ->update($db->quoteName('#__extensions'))
                        ->set($db->quoteName('params') . ' = :params')
                        ->where($db->quoteName('extension_id') . ' = :id')
                        ->bind(':params', $json)
                        ->bind(':id', $id, ParameterType::INTEGER);

                    $db->setQuery($update)->execute();
                }
            }
        );
    }
};
