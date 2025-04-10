<?php

/**
 * @package     Brambring.Plugin
 * @subpackage  System.Ubeeo
 * @since 25.44.7240
 * @version    24.02.01
 * @copyright  2025 Bram Brambring
 * @license    GNU General Public License version 3 or later;
 */

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

// phpcs:disable PSR12.Classes.AnonClassDeclaration
return new class () implements
    ServiceProviderInterface {
    // phpcs:enable PSR12.Classes.AnonClassDeclaration
    public function register(Container $container)
    {
        $container->set(
            InstallerScriptInterface::class,
            // phpcs:disable PSR12.Classes.AnonClassDeclaration
            new class () implements
                InstallerScriptInterface {
                // phpcs:enable PSR12.Classes.AnonClassDeclaration
                private readonly CMSApplicationInterface $app;
                private readonly DatabaseInterface $db;
                private string $minimumJoomlaVersion = '4.4';
                public function __construct()
                {
                    $this->db  = Factory::getContainer()->get(DatabaseInterface::class);
                    $this->app = Factory::getApplication();
                }
                /**
                 * @since 25.44.7240
                 */

                public function install(InstallerAdapter $adapter): bool
                {
                    $query = $this->db->getquery(true);
                    $query->update($this->db->quoteName('#__extensions'))
                        ->set($this->db->quoteName('enabled') . ' = 1')
                        ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
                        ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote($adapter->group))
                        ->where($this->db->quoteName('element') . ' = ' . $this->db->quote($adapter->element));
                    $this->db->setQuery($query)->execute();
                    return true;
                }

                /**
                 * @since 25.44.7240
                 */

                public function update(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                /**
                 * @since 25.44.7240
                 */

                public function uninstall(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                /**
                 * @since 25.44.7240
                 */

                public function preflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type == 'uninstall') {
                        return true;
                    }

                    $driver = strtolower($this->db->name);
                    if (!str_contains($driver, 'mysql')) {
                        Log::add(
                            Text::sprintf('JLIB_HTML_ERROR_NOTSUPPORTED', 'Database', $driver),
                            Log::ERROR,
                            'jerror'
                        );
                        return false;
                    }

                    if (version_compare(JVERSION, $this->minimumJoomlaVersion, '<')) {
                        Log::add(
                            Text::sprintf('JLIB_INSTALLER_MINIMUM_JOOMLA', $this->minimumJoomlaVersion),
                            Log::ERROR,
                            'jerror'
                        );
                        return false;
                    }
                    return true;
                }
                public function postflight(string $type, InstallerAdapter $adapter): bool
                {
                    return true;
                }

                /**
                 * return the version if the extension is installed , false otherwise
                 *
                 * @since  25.44.7240
                 */

                private function checkextension(string $name): bool | string
                {
                    $query = $this->db->getQuery(true);
                    $query->select($this->db->quoteName('manifest_cache'))
                        ->where($this->db->quoteName('element') . ' = :name')
                        ->bind(':name', $name)
                        ->from($this->db->quoteName('#__extensions'));
                    $this->db->setQuery($query);
                    $item     = $this->db->loadResult();
                    $manifest = json_decode($item ?? '{}');
                    return  $manifest->version ?? false;
                }

                /**
                 * Reloads the language from the installation package
                 *
                 * @since  25.44.7240
                 */
                private function loadLanguage(InstallerAdapter $adapter): void
                {

                    //There is a $adapter->loadLanguage();
                    //but why is that the sys file. That one is loaded always and everytime.

                    $folder    = $adapter->group;
                    $name      = $adapter->element;
                    $extension = strtolower('plg_' . $folder . '_' . $name);


                    $source    = $adapter->parent->getPath('source');
                    $lang      = $this->app->getLanguage();
                    $lang->load($extension, $source, reload: true) ||
                        $lang->load($extension, JPATH_ADMINISTRATOR, reload: true) ||
                        $lang->load($extension, JPATH_PLUGINS . '/' . $folder . '/' . $name, reload: true);
                }
            }
        );
    }
};
