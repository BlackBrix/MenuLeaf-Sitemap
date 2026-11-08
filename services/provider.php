<?php
/**
 * @package     MenuLeaf Sitemap
 * @license     GNU General Public License version 2 or later
 */
defined('_JEXEC') or die;

use MenuLeaf\Plugin\System\Menuleafsitemap\Extension\Menuleafsitemap;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, static function (Container $container): PluginInterface {
            $plugin = new Menuleafsitemap(
                $container->get(DispatcherInterface::class),
                (array) PluginHelper::getPlugin('system', 'menuleafsitemap')
            );
            $plugin->setApplication(Factory::getApplication());
            return $plugin;
        });
    }
};
