<?php

/*
 -------------------------------------------------------------------------
 GDPR Records of Processing Activities plugin for GLPI
 Copyright © 2020-2025 by Yild.

 https://github.com/yild/gdprropa
 -------------------------------------------------------------------------

 LICENSE

 This file is part of GDPR Records of Processing Activities.

 GDPR Records of Processing Activities is free software; you can
 redistribute it and/or modify it under the terms of the
 GNU General Public License as published by the Free Software
 Foundation; either version 3 of the License, or (at your option)
 any later version.

 GDPR Records of Processing Activities is distributed in the hope that
 it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 See the GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with GDPR Records of Processing Activities.
 If not, see <https://www.gnu.org/licenses/>.

 Based on DPO Register plugin, by Karhel Tmarr.

 --------------------------------------------------------------------------

  @package   gdprropa
  @author    Yild
  @copyright Copyright © 2020-2025 by Yild
  @license   GPLv3+
             https://www.gnu.org/licenses/gpl.txt
  @link      https://github.com/yild/gdprropa
  @since     1.0.0
 --------------------------------------------------------------------------
 */

use GlpiPlugin\Gdprropa\ControllerInfo;
use GlpiPlugin\Gdprropa\Profile as GdprropaProfile;
use GlpiPlugin\Gdprropa\Record;
use GlpiPlugin\Gdprropa\Menu;

// TODO try to move this to Config class and use it from there, atm GLPI (tested on 10.0.11) can't find
//      specific class when using namespaces
define('GDPRROPA_PLUGIN_VERSION', '1.0.3');

// Minimal GLPI version, inclusive
define('GDPRROPA_PLUGIN_MIN_GLPI_VERSION', '10.0.0');
// Maximum GLPI version, exclusive
define('GDPRROPA_PLUGIN_MAX_GLPI_VERSION', '12.0');

function plugin_init_gdprropa()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['gdprropa'] = true;

    if (Session::getLoginUserID()) {
        Plugin::registerClass(GdprropaProfile::class, ['addtabon' => Profile::class]);
        Plugin::registerClass(Record::class);

        $PLUGIN_HOOKS['change_profile']['gdprropa'] = [GdprropaProfile::class, 'initProfile'];

        $plugin = new Plugin();
        if (
            $plugin->isActivated('gdprropa') &&
            Session::haveRight('plugin_gdprropa_record', READ)
        ) {
            $PLUGIN_HOOKS['menu_toadd']['gdprropa'] = ['management' => Menu::class];
        }

        if (
            Session::haveRight('plugin_gdprropa_record', UPDATE) ||
            Session::haveRight('config', UPDATE)
        ) {
            $PLUGIN_HOOKS['config_page']['gdprropa'] = 'front/config.form.php';
        }

        Plugin::registerClass(ControllerInfo::class, ['addtabon' => Entity::class]);

        $PLUGIN_HOOKS['post_init']['gdprropa'] = 'plugin_gdprropa_postinit';

        $PLUGIN_HOOKS['dashboard_cards']['gdprropa'] = [Record::class, 'dashboardCards'];
    }
}

function plugin_version_gdprropa()
{
    return [
        'name' => __('GDPR Record of Processing Activities', 'gdprropa'),
        'version' => GDPRROPA_PLUGIN_VERSION,
        'author' => "<a href='https://github.com/yild/'>Yild</a>",
        'license' => 'GPLv3',
        'homepage' => 'https://github.com/yild/gdprropa',
        'requirements' => [
            'glpi' => [
                'min' => GDPRROPA_PLUGIN_MIN_GLPI_VERSION,
                'max' => GDPRROPA_PLUGIN_MAX_GLPI_VERSION,
            ]
        ]
    ];
}


function plugin_gdprropa_check_prerequisites()
{
    $min_version = defined('GDPRROPA_PLUGIN_MIN_GLPI_VERSION') ? GDPRROPA_PLUGIN_MIN_GLPI_VERSION : '10.0.0';
    $max_version = defined('GDPRROPA_PLUGIN_MAX_GLPI_VERSION') ? GDPRROPA_PLUGIN_MAX_GLPI_VERSION : '12.0';
    $glpi_version = null;
    $glpi_root = '/var/www/glpi';
    $version_dir = $glpi_root . '/version';
    if (is_dir($version_dir)) {
        $files = scandir($version_dir, SCANDIR_SORT_DESCENDING);
        foreach ($files as $file) {
            if ($file[0] !== '.' && preg_match('/^\d+\.\d+\.\d+$/', $file)) {
                $glpi_version = $file;
                break;
            }
        }
    }
    if ($glpi_version === null && defined('GLPI_VERSION')) {
        $glpi_version = GLPI_VERSION;
    }
    // Load Toolbox if not loaded
    if (!class_exists('Toolbox') && file_exists($glpi_root . '/src/Toolbox.php')) {
        require_once $glpi_root . '/src/Toolbox.php';
    }
    // Fallback error logger if Toolbox::logInFile is unavailable
    if (!function_exists('gdprropa_fallback_log')) {
        function gdprropa_fallback_log($msg) {
            $logfile = __DIR__ . '/gdprropa_error.log';
            $date = date('Y-m-d H:i:s');
            file_put_contents($logfile, "[$date] $msg\n", FILE_APPEND);
        }
    }
    if ($glpi_version === null) {
        $logmsg = '[setup.php:plugin_gdprropa_check_prerequisites] ERROR: GLPI version not detected.';
        if (class_exists('Toolbox') && method_exists('Toolbox', 'logInFile')) {
            Toolbox::logInFile('gdprropa', $logmsg);
        } else {
            gdprropa_fallback_log($logmsg);
        }
        echo "This plugin requires GLPI >= $min_version";
        return false;
    }
    if (version_compare($glpi_version, $min_version, '<')) {
        $logmsg = sprintf(
            'ERROR [setup.php:plugin_gdprropa_check_prerequisites] GLPI version %s is less than required minimum %s, user=%s',
            $glpi_version, $min_version, $_SESSION['glpiname'] ?? 'unknown'
        );
        if (class_exists('Toolbox') && method_exists('Toolbox', 'logInFile')) {
            Toolbox::logInFile('gdprropa', $logmsg);
        } else {
            gdprropa_fallback_log($logmsg);
        }
        if (method_exists('Plugin', 'messageIncompatible')) {
            echo Plugin::messageIncompatible('core', $min_version);
        } else {
            echo "This plugin requires GLPI >= $min_version";
        }
        return false;
    }
    if (version_compare($glpi_version, $max_version, '>')) {
        $logmsg = sprintf(
            'ERROR [setup.php:plugin_gdprropa_check_prerequisites] GLPI version %s is greater than supported maximum %s, user=%s',
            $glpi_version, $max_version, $_SESSION['glpiname'] ?? 'unknown'
        );
        if (class_exists('Toolbox') && method_exists('Toolbox', 'logInFile')) {
            Toolbox::logInFile('gdprropa', $logmsg);
        } else {
            gdprropa_fallback_log($logmsg);
        }
        echo "This plugin requires GLPI <= $max_version";
        return false;
    }
    return true;
}

function plugin_gdprropa_check_config($verbose = false)
{
    return true;
}
