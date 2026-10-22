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

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Webmarka\Plugin\System\ContentImageUpload\Extension\ContentImageUpload;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the plugin with the DI container, the way Joomla 6 core plugins do.
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
            PluginInterface::class,
            $container->lazy(ContentImageUpload::class, function (Container $container) {
                $plugin = new ContentImageUpload((array) PluginHelper::getPlugin('system', 'contentimageupload'));
                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($container->get(DatabaseInterface::class));

                return $plugin;
            })
        );
    }
};
