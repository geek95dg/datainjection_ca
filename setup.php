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

use function Safe\define;
use function Safe\mkdir;

define('PLUGIN_DATAINJECTION_CA_VERSION', '2.15.6');

// Minimal GLPI version, inclusive
define("PLUGIN_DATAINJECTION_CA_MIN_GLPI", "11.0.5");
// Maximum GLPI version, exclusive
define("PLUGIN_DATAINJECTION_CA_MAX_GLPI", "11.0.99");

if (!defined("PLUGIN_DATAINJECTION_CA_UPLOAD_DIR")) {
    define("PLUGIN_DATAINJECTION_CA_UPLOAD_DIR", GLPI_PLUGIN_DOC_DIR . "/datainjection_ca/");
}

/**
 * Map our `PluginDatainjectionCa<Suffix>` class names to `inc/<suffix>.class.php`.
 *
 * GLPI's stock plugin autoloader derives the class prefix from the
 * directory name via `ucfirst()`, so a directory named `datainjection_ca`
 * would expect classes named `PluginDatainjection_caFoo`. We prefer the
 * cleaner camel-cased prefix, so we ship our own loader and let GLPI's
 * loader handle everything else.
 */
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'PluginDatainjectionCa', 21) !== 0) {
        return;
    }
    $short = strtolower(substr($class, 21));
    if ($short === '') {
        return;
    }
    $file = __DIR__ . '/inc/' . $short . '.class.php';
    if (is_file($file)) {
        require_once $file;
    }
});

function plugin_init_datainjection_ca()
{
    /** @var array $PLUGIN_HOOKS */
    /** @var array $CFG_GLPI */
    /** @var array $INJECTABLE_TYPES */
    global $PLUGIN_HOOKS, $CFG_GLPI, $INJECTABLE_TYPES;

    $PLUGIN_HOOKS['csrf_compliant']['datainjection_ca'] = true;
    $PLUGIN_HOOKS['migratetypes']['datainjection_ca'] = 'plugin_datainjection_ca_migratetypes';

    $plugin = new Plugin();
    if ($plugin->isActivated("datainjection_ca")) {
        Plugin::registerClass(
            'PluginDatainjectionCaProfile',
            ['addtabon' => ['Profile'],
            ],
        );

        //If directory doesn't exists, create it
        if (!plugin_datainjection_ca_checkDirectories()) {
            @ mkdir(PLUGIN_DATAINJECTION_CA_UPLOAD_DIR);
        }
        $PLUGIN_HOOKS["config_page"]['datainjection_ca'] = "front/clientinjection.form.php";



        if (Session::haveRight('plugin_datainjection_ca_use', READ)) {
            $PLUGIN_HOOKS["menu_toadd"]['datainjection_ca'] = ['tools'  => 'PluginDatainjectionCaMenu'];
        }

        $PLUGIN_HOOKS['pre_item_purge']['datainjection_ca']
          = ['Profile' => ['PluginDatainjectionCaProfile', 'purgeProfiles']];

        // Css file. Hard-pin to `/plugins/datainjection_ca` instead of using
        // Plugin::getPhpDir(): GLPI returns the marketplace path when the
        // plugin is installed under marketplace/, and this fork ships under
        // /plugins/ by design.
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/plugins/datainjection_ca')) {
            $PLUGIN_HOOKS['add_css']['datainjection_ca'] = 'css/datainjection_ca.css';
        }

        // Javascript file
        $PLUGIN_HOOKS['add_javascript']['datainjection_ca'] = 'js/datainjection_ca.js';

        $INJECTABLE_TYPES = [];
    }
}


function plugin_version_datainjection_ca()
{

    return [
        'name'         => __('Data injection (custom assets)', 'datainjection_ca'),
        'author'       => 'geek95dg',
        'homepage'     => 'https://github.com/geek95dg/datainjection_ca',
        'license'      => 'GPLv2+',
        'version'      => PLUGIN_DATAINJECTION_CA_VERSION,
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_DATAINJECTION_CA_MIN_GLPI,
                'max' => PLUGIN_DATAINJECTION_CA_MAX_GLPI,
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

    $INJECTABLE_TYPES = ['PluginDatainjectionCaCartridgeItemInjection'              => 'datainjection_ca',
        'PluginDatainjectionCaBudgetInjection'                      => 'datainjection_ca',
        'PluginDatainjectionCaComputerInjection'                    => 'datainjection_ca',
        'PluginDatainjectionCaDatabaseInjection'                    => 'datainjection_ca',
        'PluginDatainjectionCaDatabaseInstanceInjection'            => 'datainjection_ca',
        'PluginDatainjectionCaNotepadInjection'                     => 'datainjection_ca',
        //'PluginDatainjectionCaComputer_ItemInjection'               => 'datainjection_ca',
        'PluginDatainjectionCaConsumableItemInjection'              => 'datainjection_ca',
        'PluginDatainjectionCaContactInjection'                     => 'datainjection_ca',
        'PluginDatainjectionCaContact_SupplierInjection'            => 'datainjection_ca',
        'PluginDatainjectionCaContractInjection'                    => 'datainjection_ca',
        'PluginDatainjectionCaContract_ItemInjection'               => 'datainjection_ca',
        'PluginDatainjectionCaContract_SupplierInjection'           => 'datainjection_ca',
        'PluginDatainjectionCaEntityInjection'                      => 'datainjection_ca',
        'PluginDatainjectionCaGroupInjection'                       => 'datainjection_ca',
        'PluginDatainjectionCaGroup_UserInjection'                  => 'datainjection_ca',
        'PluginDatainjectionCaInfocomInjection'                     => 'datainjection_ca',
        'PluginDatainjectionCaLocationInjection'                    => 'datainjection_ca',
        'PluginDatainjectionCaCategoryInjection'                    => 'datainjection_ca',
        'PluginDatainjectionCaStateInjection'                       => 'datainjection_ca',
        'PluginDatainjectionCaManufacturerInjection'                => 'datainjection_ca',
        'PluginDatainjectionCaMonitorInjection'                     => 'datainjection_ca',
        'PluginDatainjectionCaNetworkequipmentInjection'            => 'datainjection_ca',
        'PluginDatainjectionCaPeripheralInjection'                  => 'datainjection_ca',
        'PluginDatainjectionCaPhoneInjection'                       => 'datainjection_ca',
        'PluginDatainjectionCaPrinterInjection'                     => 'datainjection_ca',
        'PluginDatainjectionCaProfileInjection'                     => 'datainjection_ca',
        'PluginDatainjectionCaProfile_UserInjection'                => 'datainjection_ca',
        'PluginDatainjectionCaSoftwareInjection'                    => 'datainjection_ca',
        'PluginDatainjectionCaSoftwareLicenseInjection'             => 'datainjection_ca',
        'PluginDatainjectionCaSoftwareVersionInjection'             => 'datainjection_ca',
        'PluginDatainjectionCaSupplierInjection'                    => 'datainjection_ca',
        'PluginDatainjectionCaUserInjection'                        => 'datainjection_ca',
        'PluginDatainjectionCaNetworkportInjection'                 => 'datainjection_ca',
        'PluginDatainjectionCaVlanInjection'                        => 'datainjection_ca',
        'PluginDatainjectionCaNetworkport_VlanInjection'            => 'datainjection_ca',
        'PluginDatainjectionCaNetworkNameInjection'                 => 'datainjection_ca',
        'PluginDatainjectionCaNetpointInjection'                    => 'datainjection_ca',
        'PluginDatainjectionCaKnowbaseItemCategoryInjection'        => 'datainjection_ca',
        'PluginDatainjectionCaKnowbaseItemInjection'                => 'datainjection_ca',
        'PluginDatainjectionCaITILFollowupTemplateInjection'        => 'datainjection_ca',
        'PluginDatainjectionCaITILCategoryInjection'                => 'datainjection_ca',
        'PluginDatainjectionCaTaskCategoryInjection'                => 'datainjection_ca',
        'PluginDatainjectionCaTaskTemplateInjection'                => 'datainjection_ca',
        'PluginDatainjectionCaSolutionTypeInjection'                => 'datainjection_ca',
        'PluginDatainjectionCaRequestTypeInjection'                 => 'datainjection_ca',
        'PluginDatainjectionCaSolutionTemplateInjection'            => 'datainjection_ca',
        'PluginDatainjectionCaComputerTypeInjection'                => 'datainjection_ca',
        'PluginDatainjectionCaMonitorTypeInjection'                 => 'datainjection_ca',
        'PluginDatainjectionCaNetworkEquipmentTypeInjection'        => 'datainjection_ca',
        'PluginDatainjectionCaPeripheralTypeInjection'              => 'datainjection_ca',
        'PluginDatainjectionCaPrinterTypeInjection'                 => 'datainjection_ca',
        'PluginDatainjectionCaPhoneTypeInjection'                   => 'datainjection_ca',
        'PluginDatainjectionCaSoftwareLicenseTypeInjection'         => 'datainjection_ca',
        'PluginDatainjectionCaContractTypeInjection'                => 'datainjection_ca',
        'PluginDatainjectionCaContactTypeInjection'                 => 'datainjection_ca',
        'PluginDatainjectionCaSupplierTypeInjection'                => 'datainjection_ca',
        'PluginDatainjectionCaDeviceMemoryTypeInjection'            => 'datainjection_ca',
        'PluginDatainjectionCaInterfaceTypeInjection'               => 'datainjection_ca',
        'PluginDatainjectionCaPhonePowerSupplyTypeInjection'        => 'datainjection_ca',
        'PluginDatainjectionCaFilesystemTypeInjection'              => 'datainjection_ca',
        'PluginDatainjectionCaComputerModelInjection'               => 'datainjection_ca',
        'PluginDatainjectionCaMonitorModelInjection'                => 'datainjection_ca',
        'PluginDatainjectionCaPhoneModelInjection'                  => 'datainjection_ca',
        'PluginDatainjectionCaPrinterModelInjection'                => 'datainjection_ca',
        'PluginDatainjectionCaPeripheralModelInjection'             => 'datainjection_ca',
        'PluginDatainjectionCaNetworkEquipmentModelInjection'       => 'datainjection_ca',
        //'PluginDatainjectionCaNetworkEquipmentFirmwareInjection'    => 'datainjection_ca',
        'PluginDatainjectionCaVirtualMachineTypeInjection'          => 'datainjection_ca',
        'PluginDatainjectionCaVirtualMachineSystemInjection'        => 'datainjection_ca',
        'PluginDatainjectionCaVirtualMachineStateInjection'         => 'datainjection_ca',
        'PluginDatainjectionCaDocumentTypeInjection'                => 'datainjection_ca',
        'PluginDatainjectionCaAutoUpdateSystemInjection'            => 'datainjection_ca',
        'PluginDatainjectionCaOperatingSystemInjection'             => 'datainjection_ca',
        'PluginDatainjectionCaOperatingSystemVersionInjection'      => 'datainjection_ca',
        'PluginDatainjectionCaOperatingSystemServicePackInjection'  => 'datainjection_ca',
        'PluginDatainjectionCaOperatingSystemKernelInjection'       => 'datainjection_ca',
        'PluginDatainjectionCaOperatingSystemKernelVersionInjection' => 'datainjection_ca',
        'PluginDatainjectionCaOperatingSystemEditionInjection'      => 'datainjection_ca',
        'PluginDatainjectionCaItem_OperatingSystemInjection'        => 'datainjection_ca',
        'PluginDatainjectionCaNetworkInterfaceInjection'            => 'datainjection_ca',
        'PluginDatainjectionCaDomainInjection'                      => 'datainjection_ca',
        'PluginDatainjectionCaNetworkInjection'                     => 'datainjection_ca',
        'PluginDatainjectionCaDeviceCaseInjection'                  => 'datainjection_ca',
        'PluginDatainjectionCaDeviceCaseTypeInjection'              => 'datainjection_ca',
        'PluginDatainjectionCaDeviceControlInjection'               => 'datainjection_ca',
        'PluginDatainjectionCaDeviceProcessorInjection'             => 'datainjection_ca',
        'PluginDatainjectionCaDeviceMemoryInjection'                => 'datainjection_ca',
        'PluginDatainjectionCaDeviceHardDriveInjection'             => 'datainjection_ca',
        'PluginDatainjectionCaDeviceMotherboardInjection'           => 'datainjection_ca',
        'PluginDatainjectionCaDeviceDriveInjection'                 => 'datainjection_ca',
        'PluginDatainjectionCaDeviceNetworkCardInjection'           => 'datainjection_ca',
        'PluginDatainjectionCaApplianceInjection'                   => 'datainjection_ca',
        'PluginDatainjectionCaCertificateInjection'                 => 'datainjection_ca',
    ];

    // GLPI 11 custom assets: enumerate AssetDefinitions and expose each one
    // as an injectable type. Failures (e.g. during install before tables
    // exist) are swallowed so the plugin still boots.
    try {
        if (class_exists('PluginDatainjectionCaCustomAssetRegistry')) {
            foreach (PluginDatainjectionCaCustomAssetRegistry::getInjectableTypes() as $cls => $origin) {
                $INJECTABLE_TYPES[$cls] = $origin;
            }
        }
    } catch (\Throwable $e) {
        // Best effort; the plugin must still load if custom assets fail.
    }

    //Add plugins
    Plugin::doHook('plugin_datainjection_ca_populate');
}


function plugin_datainjection_ca_migratetypes($types)
{

    $types[996] = 'NetworkPort';
    $types[999] = 'NetworkPort';
    return $types;
}


function plugin_datainjection_ca_checkDirectories()
{
    return !(!file_exists(PLUGIN_DATAINJECTION_CA_UPLOAD_DIR) || !is_writable(PLUGIN_DATAINJECTION_CA_UPLOAD_DIR));
}

function plugin_datainjection_ca_geturl(): string
{
    /** @var array $CFG_GLPI */
    global $CFG_GLPI;
    return sprintf('%s/plugins/datainjection_ca/', $CFG_GLPI['root_doc']);
}
