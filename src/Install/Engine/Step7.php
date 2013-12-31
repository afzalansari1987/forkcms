<?php

namespace Install\Engine;

/*
 * This file is part of Fork CMS.
 *
 * For the full copyright and license information, please view the license
 * file that was distributed with this source code.
 */

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Finder\Finder;

/**
 * Step 7 of the Fork installer
 *
 * @author Davy Hellemans <davy@netlash.com>
 * @author Tijs Verkoyen <tijs@sumocoders.be>
 * @author Matthias Mullie <forkcms@mullie.eu>
 * @author Dieter Vanden Eynde <dieter@netlash.com>
 * @author Annelies Van Extergem <annelies.vanextergem@netlash.com>
 */
class Step7 extends Step
{
    /**
     * Build the language files
     *
     * @param SpoonDatabase $db          The database connection instance.
     * @param string        $language    The language to build the locale-file for.
     * @param string        $application The application to build the locale-file for.
     */
    public function buildCache(SpoonDatabase $db, $language, $application)
    {
        // get types
        $types = $db->getEnumValues('locale', 'type');

        // get locale for backend
        $locale = (array) $db->getRecords(
            'SELECT type, module, name, value
                                                        FROM locale
                                                        WHERE language = ? AND application = ?
                                                        ORDER BY type ASC, name ASC, module ASC',
            array((string) $language, (string) $application)
        );

        // start generating PHP
        $value = '<?php' . "\n";
        $value .= '/**' . "\n";
        $value .= ' *' . "\n";
        $value .= ' * This file is generated by the Installer, it contains' . "\n";
        $value .= ' * more information about the locale. Do NOT edit.' . "\n";
        $value .= ' * ' . "\n";
        $value .= ' * @author Installer' . "\n";
        $value .= ' * @generated	' . date('Y-m-d H:i:s') . "\n";
        $value .= ' */' . "\n";
        $value .= "\n";

        // loop types
        foreach ($types as $type) {
            // default module
            $modules = array('core');

            // continue output
            $value .= "\n";
            $value .= '// init var' . "\n";
            $value .= '$' . $type . ' = array();' . "\n";
            $value .= '$' . $type . '[\'core\'] = array();' . "\n";

            // loop locale
            foreach ($locale as $i => $item) {
                // types match
                if ($item['type'] == $type) {
                    // new module
                    if (!in_array($item['module'], $modules)) {
                        $value .= '$' . $type . '[\'' . $item['module'] . '\'] = array();' . "\n";
                        $modules[] = $item['module'];
                    }

                    // parse
                    if ($application == 'backend') {
                        $value .= '$' . $type . '[\'' . $item['module'] . '\'][\'' . $item['name'] . '\'] = \'' .
                                  str_replace('\"', '"', addslashes($item['value'])) . '\';' . "\n";
                    } else {
                        $value .= '$' . $type . '[\'' . $item['name'] . '\'] = \'' .
                                  str_replace('\"', '"', addslashes($item['value'])) . '\';' . "\n";
                    }

                    // unset
                    unset($locale[$i]);
                }
            }
        }

        $value .= "\n";
        $value .= '?>';

        // store
        $fs = new Filesystem();
        $fs->dumpFile(
            PATH_WWW . '/' . $application . '/cache/locale/' . $language . '.php',
            $value
        );
    }

    /**
     * Create locale cache files
     */
    private function createLocaleFiles()
    {
        // all available languages
        $languages = array_unique(
            array_merge(SpoonSession::get('languages'), SpoonSession::get('interface_languages'))
        );

        // loop all the languages
        foreach ($languages as $language) {
            // get applications
            $applications = $this->getContainer()->get('database')->getColumn(
                'SELECT DISTINCT application
                 FROM locale
                 WHERE language = ?',
                array((string) $language)
            );

            // loop applications
            foreach ((array) $applications as $application) {
                // build application locale cache
                $this->buildCache($this->getContainer()->get('database'), $language, $application);
            }
        }
    }

    /**
     * Define paths also used in frontend/backend, to be used in installer.
     */
    private function definePaths()
    {
        // general paths
        define('BACKEND_PATH', PATH_WWW . '/backend');
        define('BACKEND_CACHE_PATH', BACKEND_PATH . '/cache');
        define('BACKEND_CORE_PATH', BACKEND_PATH . '/core');
        define('BACKEND_MODULES_PATH', BACKEND_PATH . '/modules');

        define('FRONTEND_PATH', PATH_WWW . '/frontend');
        define('FRONTEND_CACHE_PATH', FRONTEND_PATH . '/cache');
        define('FRONTEND_CORE_PATH', FRONTEND_PATH . '/core');
        define('FRONTEND_MODULES_PATH', FRONTEND_PATH . '/modules');
        define('FRONTEND_FILES_PATH', FRONTEND_PATH . '/files');
    }

    /**
     * Delete the cached data
     */
    private function deleteCachedData()
    {
        $finder = new Finder();
        $fs = new Filesystem();
        foreach ($finder->files()->in(PATH_WWW . '/backend/cache')->in(PATH_WWW . '/frontend/cache') as $file) {
            $fs->remove($file->getRealPath());
        }
    }

    /**
     * Executes this step.
     */
    public function execute()
    {
        // extend execution limit
        set_time_limit(0);

        // validate all previous steps
        if (!$this->validateForm()) {
            SpoonHTTP::redirect('index.php?step=1');
        }

        // delete cached data
        $this->deleteCachedData();

        // define paths
        $this->definePaths();

        // install modules
        $this->installModules();

        // create locale cache
        $this->createLocaleFiles();

        // already installed
        $fs = new Filesystem();
        $fs->dumpFile(
            dirname(__FILE__) . '/../cache/installed.txt',
            date('Y-m-d H:i:s')
        );

        // show success message
        $this->showSuccess();

        // clear session
        SpoonSession::destroy();
    }

    /**
     * Installs the required and optional modules
     */
    private function installModules()
    {
        // The default extras to add to every page after installation of all
        // modules and to add to the default templates.
        $defaultExtras = array();

        // init var
        $warnings = array();

        /**
         * First we need to install the core. All the linked modules, settings and sql tables are
         * being installed.
         */
        require_once PATH_WWW . '/backend/core/installer/installer.php';

        // create the core installer
        $installer = new CoreInstaller(
            $this->getContainer()->get('database'),
            SpoonSession::get('languages'),
            SpoonSession::get('interface_languages'),
            SpoonSession::get('example_data'),
            array(
                 'default_language' => SpoonSession::get('default_language'),
                 'default_interface_language' => SpoonSession::get('default_interface_language'),
                 'spoon_debug_email' => SpoonSession::get('email'),
                 'api_email' => SpoonSession::get('email'),
                 'site_domain' => (isset($_SERVER['HTTP_HOST'])) ? $_SERVER['HTTP_HOST'] : 'fork.local',
                 'site_title' => 'Fork CMS',
                 'smtp_server' => '',
                 'smtp_port' => '',
                 'smtp_username' => '',
                 'smtp_password' => ''
            )
        );

        // install the core
        $installer->install();

        // add the warnings
        $moduleWarnings = $installer->getWarnings();
        if (!empty($moduleWarnings)) {
            $warnings[] = array('module' => 'core', 'warnings' => $moduleWarnings);
        }

        // add the default extras
        $moduleDefaultExtras = $installer->getDefaultExtras();
        if (!empty($moduleDefaultExtras)) {
            array_merge($defaultExtras, $moduleDefaultExtras);
        }

        // variables passed to module installers
        $variables = array();
        $variables['email'] = SpoonSession::get('email');
        $variables['default_interface_language'] = SpoonSession::get('default_interface_language');

        // modules to install (required + selected)
        $modules = array_unique(array_merge($this->modules['required'], SpoonSession::get('modules')));

        // loop required modules
        foreach ($modules as $module) {
            // install exists
            if (is_file(PATH_WWW . '/backend/modules/' . $module . '/installer/installer.php')) {
                // users module needs custom variables
                if ($module == 'users') {
                    $variables['password'] = SpoonSession::get('password');
                }

                // load installer file
                require_once PATH_WWW . '/backend/modules/' . $module . '/installer/installer.php';

                // build installer class name
                $class = SpoonFilter::toCamelCase($module) . 'Installer';

                // create installer
                $installer = new $class(
                    $this->getContainer()->get('database'),
                    SpoonSession::get('languages'),
                    SpoonSession::get('interface_languages'),
                    SpoonSession::get('example_data'),
                    $variables
                );

                // install the module
                $installer->install();

                // add the warnings
                $moduleWarnings = $installer->getWarnings();
                if (!empty($moduleWarnings)) {
                    $warnings[] = array('module' => $module, 'warnings' => $moduleWarnings);
                }

                // add the default extras
                $moduleDefaultExtras = $installer->getDefaultExtras();
                if (!empty($moduleDefaultExtras)) {
                    $defaultExtras = array_merge($defaultExtras, $moduleDefaultExtras);
                }
            }
        }

        // loop default extras
        foreach ($defaultExtras as $extra) {
            // get pages without this extra
            $revisionIds = $this->getContainer()->get('database')->getColumn(
                'SELECT i.revision_id
                 FROM pages AS i
                 WHERE i.revision_id NOT IN (
                     SELECT DISTINCT b.revision_id
                     FROM pages_blocks AS b
                     WHERE b.extra_id = ?
                    GROUP BY b.revision_id
                 )',
                array($extra['id'])
            );

            // build insert array for this extra
            $insertExtras = array();
            foreach ($revisionIds as $revisionId) {
                $insertExtras[] = array(
                    'revision_id' => $revisionId,
                    'position' => $extra['position'],
                    'extra_id' => $extra['id'],
                    'created_on' => gmdate('Y-m-d H:i:s'),
                    'edited_on' => gmdate('Y-m-d H:i:s'),
                    'visible' => 'Y'
                );
            }

            // insert block
            $this->getContainer()->get('database')->insert('pages_blocks', $insertExtras);
        }

        // parse the warnings
        $this->tpl->assign('warnings', $warnings);
    }

    /**
     * Is this step allowed.
     *
     * @return bool
     */
    public static function isAllowed()
    {
        return InstallerStep6::isAllowed() && isset($_SESSION['email']) && isset($_SESSION['password']);
    }

    /**
     * Show the success message
     */
    private function showSuccess()
    {
        // assign variables
        $this->tpl->assign('url', (isset($_SERVER['HTTP_HOST'])) ? $_SERVER['HTTP_HOST'] : 'fork.local');
        $this->tpl->assign('email', SpoonSession::get('email'));
        $this->tpl->assign('password', SpoonSession::get('password'));
    }

    /**
     * Validates the previous steps
     */
    private function validateForm()
    {
        return InstallerStep6::isAllowed();
    }
}
