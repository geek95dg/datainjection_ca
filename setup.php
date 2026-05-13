<?php

/**
 * -------------------------------------------------------------------------
 * DataInjection plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of DataInjection.
 *
 * DataInjection is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * DataInjection is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with DataInjection. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2007-2023 by DataInjection plugin team.
 * @license   GPLv2 https://www.gnu.org/licenses/gpl-2.0.html
 * @link      https://github.com/pluginsGLPI/datainjection
 * -------------------------------------------------------------------------
 */

// Intentionally NOT using `use function Safe\define;` here. When this plugin
// lives under `<glpi>/plugins/` (as opposed to `marketplace/`), GLPI does not
// load the plugin's local composer autoload first, so `Safe\define` may not
// be resolvable when setup.php is included. The Safe wrappers throw on error;
// PHP's built-in `define`/`mkdir` only emit a warning. For our purposes that
// is fine — and it lets `glpi:plugin:install` succeed regardless of how the
// plugin is deployed.

define('PLUGIN_DATAINJECTION_VERSION', '2.16.20');

// Minimal GLPI version, inclusive
define("PLUGIN_DATAINJECTION_MIN_GLPI", "11.0.0");
// Maximum GLPI version, exclusive
define("PLUGIN_DATAINJECTION_MAX_GLPI", "11.0.99");

if (!defined("PLUGIN_DATAINJECTION_UPLOAD_DIR")) {
    define("PLUGIN_DATAINJECTION_UPLOAD_DIR", GLPI_PLUGIN_DOC_DIR . "/datainjection/");
}

// Eagerly include the logger so it's available before GLPI's plugin
// autoloader has wired everything up — and so the shutdown handler keeps
// working after autoloading is torn down at request end.
require_once __DIR__ . '/inc/logger.class.php';

// Catch fatals that bypass our try/catch wrappers (PHP parse errors,
// uncaught exceptions inside GLPI's tab AJAX loader, etc.). Without this
// the failure becomes a generic 500 with no plugin breadcrumb.
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!is_array($err)) {
        return;
    }
    $fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
    if (!($err['type'] & $fatal_types)) {
        return;
    }
    // Only log if the failure traces through datainjection code, otherwise
    // we noisily claim every site-wide fatal.
    $file = (string) ($err['file'] ?? '');
    if (strpos($file, __DIR__) === false) {
        return;
    }
    try {
        PluginDatainjectionLogger::error(
            'fatal: ' . ($err['message'] ?? 'unknown'),
            ['where' => $file . ':' . ($err['line'] ?? '?')],
        );
    } catch (\Throwable $e) {
        @error_log('[datainjection] fatal in ' . $file . ':' . ($err['line'] ?? '?') . ' — ' . ($err['message'] ?? ''));
    }
});

// Catch non-fatal PHP warnings/notices that originate inside the plugin —
// useful when GLPI's error handler converts them upstream and our shutdown
// handler never sees them. Daisy-chains to any previous handler.
$_datainjection_prev_error_handler = set_error_handler(
    static function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use (&$_datainjection_prev_error_handler) {
        if (strpos($errfile, __DIR__) !== false) {
            try {
                PluginDatainjectionLogger::warning(
                    'php error E' . $errno . ': ' . $errstr,
                    ['where' => $errfile . ':' . $errline],
                );
            } catch (\Throwable $e) {
                // Best effort.
            }
        }
        if (is_callable($_datainjection_prev_error_handler)) {
            return call_user_func($_datainjection_prev_error_handler, $errno, $errstr, $errfile, $errline);
        }
        return false; // let PHP's default handler also run
    },
);

function plugin_init_datainjection()
{
    /** @var array $PLUGIN_HOOKS */
    /** @var array $CFG_GLPI */
    /** @var array $INJECTABLE_TYPES */
    global $PLUGIN_HOOKS, $CFG_GLPI, $INJECTABLE_TYPES;

    $PLUGIN_HOOKS['csrf_compliant']['datainjection'] = true;
    $PLUGIN_HOOKS['migratetypes']['datainjection'] = 'plugin_datainjection_migratetypes';

    $plugin = new Plugin();
    if ($plugin->isActivated("datainjection")) {
        Plugin::registerClass(
            'PluginDatainjectionProfile',
            ['addtabon' => ['Profile'],
            ],
        );

        //If directory doesn't exists, create it
        if (!plugin_datainjection_checkDirectories()) {
            @\mkdir(PLUGIN_DATAINJECTION_UPLOAD_DIR);
        }
        $PLUGIN_HOOKS["config_page"]['datainjection'] = "front/clientinjection.form.php";



        if (Session::haveRight('plugin_datainjection_use', READ)) {
            $PLUGIN_HOOKS["menu_toadd"]['datainjection'] = ['tools'  => 'PluginDatainjectionMenu'];
        }

        $PLUGIN_HOOKS['pre_item_purge']['datainjection']
          = ['Profile' => ['PluginDatainjectionProfile', 'purgeProfiles']];

        // Css file. Hard-pin to `/plugins/datainjection` instead of using
        // Plugin::getPhpDir(): GLPI returns the marketplace path when the
        // plugin is installed under marketplace/, and this fork ships under
        // /plugins/ by design.
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/plugins/datainjection')) {
            $PLUGIN_HOOKS['add_css']['datainjection'] = 'css/datainjection.css';
        }

        // Javascript file
        $PLUGIN_HOOKS['add_javascript']['datainjection'] = 'js/datainjection.js';

        $INJECTABLE_TYPES = [];
    }
}


function plugin_version_datainjection()
{

    return [
        'name'         => __('Data injection (custom assets)', 'datainjection'),
        'author'       => 'geek95dg',
        'homepage'     => 'https://github.com/geek95dg/datainjection',
        'license'      => 'GPLv2+',
        'version'      => PLUGIN_DATAINJECTION_VERSION,
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_DATAINJECTION_MIN_GLPI,
                'max' => PLUGIN_DATAINJECTION_MAX_GLPI,
            ],
        ],
    ];
}


/**
 * Return all types that can be injected using datainjection
 *
 * @return void
 */
function getTypesToInject(): void
{
    /** @var array $INJECTABLE_TYPES */
    /** @var array $PLUGIN_HOOKS */
    global $INJECTABLE_TYPES,$PLUGIN_HOOKS;

    if (count($INJECTABLE_TYPES)) {
        // already populated
        return;
    }

    $INJECTABLE_TYPES = ['PluginDatainjectionCartridgeItemInjection'              => 'datainjection',
        'PluginDatainjectionBudgetInjection'                      => 'datainjection',
        'PluginDatainjectionComputerInjection'                    => 'datainjection',
        'PluginDatainjectionDatabaseInjection'                    => 'datainjection',
        'PluginDatainjectionDatabaseInstanceInjection'            => 'datainjection',
        'PluginDatainjectionNotepadInjection'                     => 'datainjection',
        //'PluginDatainjectionComputer_ItemInjection'               => 'datainjection',
        'PluginDatainjectionConsumableItemInjection'              => 'datainjection',
        'PluginDatainjectionContactInjection'                     => 'datainjection',
        'PluginDatainjectionContact_SupplierInjection'            => 'datainjection',
        'PluginDatainjectionContractInjection'                    => 'datainjection',
        'PluginDatainjectionContract_ItemInjection'               => 'datainjection',
        'PluginDatainjectionContract_SupplierInjection'           => 'datainjection',
        'PluginDatainjectionEntityInjection'                      => 'datainjection',
        'PluginDatainjectionGroupInjection'                       => 'datainjection',
        'PluginDatainjectionGroup_UserInjection'                  => 'datainjection',
        'PluginDatainjectionInfocomInjection'                     => 'datainjection',
        'PluginDatainjectionLocationInjection'                    => 'datainjection',
        'PluginDatainjectionCategoryInjection'                    => 'datainjection',
        'PluginDatainjectionStateInjection'                       => 'datainjection',
        'PluginDatainjectionManufacturerInjection'                => 'datainjection',
        'PluginDatainjectionMonitorInjection'                     => 'datainjection',
        'PluginDatainjectionNetworkequipmentInjection'            => 'datainjection',
        'PluginDatainjectionPeripheralInjection'                  => 'datainjection',
        'PluginDatainjectionPhoneInjection'                       => 'datainjection',
        'PluginDatainjectionPrinterInjection'                     => 'datainjection',
        'PluginDatainjectionProfileInjection'                     => 'datainjection',
        'PluginDatainjectionProfile_UserInjection'                => 'datainjection',
        'PluginDatainjectionSoftwareInjection'                    => 'datainjection',
        'PluginDatainjectionSoftwareLicenseInjection'             => 'datainjection',
        'PluginDatainjectionSoftwareVersionInjection'             => 'datainjection',
        'PluginDatainjectionSupplierInjection'                    => 'datainjection',
        'PluginDatainjectionUserInjection'                        => 'datainjection',
        'PluginDatainjectionNetworkportInjection'                 => 'datainjection',
        'PluginDatainjectionVlanInjection'                        => 'datainjection',
        'PluginDatainjectionNetworkport_VlanInjection'            => 'datainjection',
        'PluginDatainjectionNetworkNameInjection'                 => 'datainjection',
        'PluginDatainjectionNetpointInjection'                    => 'datainjection',
        'PluginDatainjectionKnowbaseItemCategoryInjection'        => 'datainjection',
        'PluginDatainjectionKnowbaseItemInjection'                => 'datainjection',
        'PluginDatainjectionITILFollowupTemplateInjection'        => 'datainjection',
        'PluginDatainjectionITILCategoryInjection'                => 'datainjection',
        'PluginDatainjectionTaskCategoryInjection'                => 'datainjection',
        'PluginDatainjectionTaskTemplateInjection'                => 'datainjection',
        'PluginDatainjectionSolutionTypeInjection'                => 'datainjection',
        'PluginDatainjectionRequestTypeInjection'                 => 'datainjection',
        'PluginDatainjectionSolutionTemplateInjection'            => 'datainjection',
        'PluginDatainjectionComputerTypeInjection'                => 'datainjection',
        'PluginDatainjectionMonitorTypeInjection'                 => 'datainjection',
        'PluginDatainjectionNetworkEquipmentTypeInjection'        => 'datainjection',
        'PluginDatainjectionPeripheralTypeInjection'              => 'datainjection',
        'PluginDatainjectionPrinterTypeInjection'                 => 'datainjection',
        'PluginDatainjectionPhoneTypeInjection'                   => 'datainjection',
        'PluginDatainjectionSoftwareLicenseTypeInjection'         => 'datainjection',
        'PluginDatainjectionContractTypeInjection'                => 'datainjection',
        'PluginDatainjectionContactTypeInjection'                 => 'datainjection',
        'PluginDatainjectionSupplierTypeInjection'                => 'datainjection',
        'PluginDatainjectionDeviceMemoryTypeInjection'            => 'datainjection',
        'PluginDatainjectionInterfaceTypeInjection'               => 'datainjection',
        'PluginDatainjectionPhonePowerSupplyTypeInjection'        => 'datainjection',
        'PluginDatainjectionFilesystemTypeInjection'              => 'datainjection',
        'PluginDatainjectionComputerModelInjection'               => 'datainjection',
        'PluginDatainjectionMonitorModelInjection'                => 'datainjection',
        'PluginDatainjectionPhoneModelInjection'                  => 'datainjection',
        'PluginDatainjectionPrinterModelInjection'                => 'datainjection',
        'PluginDatainjectionPeripheralModelInjection'             => 'datainjection',
        'PluginDatainjectionNetworkEquipmentModelInjection'       => 'datainjection',
        //'PluginDatainjectionNetworkEquipmentFirmwareInjection'    => 'datainjection',
        'PluginDatainjectionVirtualMachineTypeInjection'          => 'datainjection',
        'PluginDatainjectionVirtualMachineSystemInjection'        => 'datainjection',
        'PluginDatainjectionVirtualMachineStateInjection'         => 'datainjection',
        'PluginDatainjectionDocumentTypeInjection'                => 'datainjection',
        'PluginDatainjectionAutoUpdateSystemInjection'            => 'datainjection',
        'PluginDatainjectionOperatingSystemInjection'             => 'datainjection',
        'PluginDatainjectionOperatingSystemVersionInjection'      => 'datainjection',
        'PluginDatainjectionOperatingSystemServicePackInjection'  => 'datainjection',
        'PluginDatainjectionOperatingSystemKernelInjection'       => 'datainjection',
        'PluginDatainjectionOperatingSystemKernelVersionInjection' => 'datainjection',
        'PluginDatainjectionOperatingSystemEditionInjection'      => 'datainjection',
        'PluginDatainjectionItem_OperatingSystemInjection'        => 'datainjection',
        'PluginDatainjectionNetworkInterfaceInjection'            => 'datainjection',
        'PluginDatainjectionDomainInjection'                      => 'datainjection',
        'PluginDatainjectionNetworkInjection'                     => 'datainjection',
        'PluginDatainjectionDeviceCaseInjection'                  => 'datainjection',
        'PluginDatainjectionDeviceCaseTypeInjection'              => 'datainjection',
        'PluginDatainjectionDeviceControlInjection'               => 'datainjection',
        'PluginDatainjectionDeviceProcessorInjection'             => 'datainjection',
        'PluginDatainjectionDeviceMemoryInjection'                => 'datainjection',
        'PluginDatainjectionDeviceHardDriveInjection'             => 'datainjection',
        'PluginDatainjectionDeviceMotherboardInjection'           => 'datainjection',
        'PluginDatainjectionDeviceDriveInjection'                 => 'datainjection',
        'PluginDatainjectionDeviceNetworkCardInjection'           => 'datainjection',
        'PluginDatainjectionApplianceInjection'                   => 'datainjection',
        'PluginDatainjectionCertificateInjection'                 => 'datainjection',
    ];

    // GLPI 11 custom assets: enumerate AssetDefinitions and expose each one
    // as an injectable type. Failures (e.g. during install before tables
    // exist) are swallowed so the plugin still boots.
    try {
        if (class_exists('PluginDatainjectionCustomAssetRegistry')) {
            foreach (PluginDatainjectionCustomAssetRegistry::getInjectableTypes() as $cls => $origin) {
                $INJECTABLE_TYPES[$cls] = $origin;
            }
        }
    } catch (\Throwable $e) {
        // Best effort; the plugin must still load if custom assets fail.
        if (class_exists('PluginDatainjectionLogger')) {
            PluginDatainjectionLogger::exception($e, 'custom asset registry init failed');
        }
    }

    //Add plugins
    Plugin::doHook('plugin_datainjection_populate');
}


function plugin_datainjection_migratetypes($types)
{

    $types[996] = 'NetworkPort';
    $types[999] = 'NetworkPort';
    return $types;
}


function plugin_datainjection_checkDirectories()
{
    return !(!file_exists(PLUGIN_DATAINJECTION_UPLOAD_DIR) || !is_writable(PLUGIN_DATAINJECTION_UPLOAD_DIR));
}

function plugin_datainjection_geturl(): string
{
    /** @var array $CFG_GLPI */
    global $CFG_GLPI;
    return sprintf('%s/plugins/datainjection/', $CFG_GLPI['root_doc']);
}
