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
    define("PLUGIN_DATAINJECTION_CA_UPLOAD_DIR", GLPI_PLUGIN_DOC_DIR . "/datainjectionca/");
}

/**
 * Map our `PluginDatainjectionCa<Suffix>` class names to `inc/<suffix>.class.php`.
 *
 * GLPI's stock plugin autoloader derives the class prefix from the
 * directory name via `ucfirst()`, so a directory named `datainjectionca`
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

function plugin_init_datainjectionca()
{
    /** @var array $PLUGIN_HOOKS */
    /** @var array $CFG_GLPI */
    /** @var array $INJECTABLE_TYPES */
    global $PLUGIN_HOOKS, $CFG_GLPI, $INJECTABLE_TYPES;

    $PLUGIN_HOOKS['csrf_compliant']['datainjectionca'] = true;
    $PLUGIN_HOOKS['migratetypes']['datainjectionca'] = 'plugin_datainjectionca_migratetypes';

    $plugin = new Plugin();
    if ($plugin->isActivated("datainjectionca")) {
        Plugin::registerClass(
            'PluginDatainjectionCaProfile',
            ['addtabon' => ['Profile'],
            ],
        );

        //If directory doesn't exists, create it
        if (!plugin_datainjectionca_checkDirectories()) {
            @ mkdir(PLUGIN_DATAINJECTION_CA_UPLOAD_DIR);
        }
        $PLUGIN_HOOKS["config_page"]['datainjectionca'] = "front/clientinjection.form.php";



        if (Session::haveRight('plugin_datainjectionca_use', READ)) {
            $PLUGIN_HOOKS["menu_toadd"]['datainjectionca'] = ['tools'  => 'PluginDatainjectionCaMenu'];
        }

        $PLUGIN_HOOKS['pre_item_purge']['datainjectionca']
          = ['Profile' => ['PluginDatainjectionCaProfile', 'purgeProfiles']];

        // Css file. Hard-pin to `/plugins/datainjectionca` instead of using
        // Plugin::getPhpDir(): GLPI returns the marketplace path when the
        // plugin is installed under marketplace/, and this fork ships under
        // /plugins/ by design.
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/plugins/datainjectionca')) {
            $PLUGIN_HOOKS['add_css']['datainjectionca'] = 'css/datainjectionca.css';
        }

        // Javascript file
        $PLUGIN_HOOKS['add_javascript']['datainjectionca'] = 'js/datainjectionca.js';

        $INJECTABLE_TYPES = [];
    }
}


function plugin_version_datainjectionca()
{

    return [
        'name'         => __('Data injection (custom assets)', 'datainjectionca'),
        'author'       => 'geek95dg',
        'homepage'     => 'https://github.com/geek95dg/datainjectionca',
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

    $INJECTABLE_TYPES = ['PluginDatainjectionCaCartridgeItemInjection'              => 'datainjectionca',
        'PluginDatainjectionCaBudgetInjection'                      => 'datainjectionca',
        'PluginDatainjectionCaComputerInjection'                    => 'datainjectionca',
        'PluginDatainjectionCaDatabaseInjection'                    => 'datainjectionca',
        'PluginDatainjectionCaDatabaseInstanceInjection'            => 'datainjectionca',
        'PluginDatainjectionCaNotepadInjection'                     => 'datainjectionca',
        //'PluginDatainjectionCaComputer_ItemInjection'               => 'datainjectionca',
        'PluginDatainjectionCaConsumableItemInjection'              => 'datainjectionca',
        'PluginDatainjectionCaContactInjection'                     => 'datainjectionca',
        'PluginDatainjectionCaContact_SupplierInjection'            => 'datainjectionca',
        'PluginDatainjectionCaContractInjection'                    => 'datainjectionca',
        'PluginDatainjectionCaContract_ItemInjection'               => 'datainjectionca',
        'PluginDatainjectionCaContract_SupplierInjection'           => 'datainjectionca',
        'PluginDatainjectionCaEntityInjection'                      => 'datainjectionca',
        'PluginDatainjectionCaGroupInjection'                       => 'datainjectionca',
        'PluginDatainjectionCaGroup_UserInjection'                  => 'datainjectionca',
        'PluginDatainjectionCaInfocomInjection'                     => 'datainjectionca',
        'PluginDatainjectionCaLocationInjection'                    => 'datainjectionca',
        'PluginDatainjectionCaCategoryInjection'                    => 'datainjectionca',
        'PluginDatainjectionCaStateInjection'                       => 'datainjectionca',
        'PluginDatainjectionCaManufacturerInjection'                => 'datainjectionca',
        'PluginDatainjectionCaMonitorInjection'                     => 'datainjectionca',
        'PluginDatainjectionCaNetworkequipmentInjection'            => 'datainjectionca',
        'PluginDatainjectionCaPeripheralInjection'                  => 'datainjectionca',
        'PluginDatainjectionCaPhoneInjection'                       => 'datainjectionca',
        'PluginDatainjectionCaPrinterInjection'                     => 'datainjectionca',
        'PluginDatainjectionCaProfileInjection'                     => 'datainjectionca',
        'PluginDatainjectionCaProfile_UserInjection'                => 'datainjectionca',
        'PluginDatainjectionCaSoftwareInjection'                    => 'datainjectionca',
        'PluginDatainjectionCaSoftwareLicenseInjection'             => 'datainjectionca',
        'PluginDatainjectionCaSoftwareVersionInjection'             => 'datainjectionca',
        'PluginDatainjectionCaSupplierInjection'                    => 'datainjectionca',
        'PluginDatainjectionCaUserInjection'                        => 'datainjectionca',
        'PluginDatainjectionCaNetworkportInjection'                 => 'datainjectionca',
        'PluginDatainjectionCaVlanInjection'                        => 'datainjectionca',
        'PluginDatainjectionCaNetworkport_VlanInjection'            => 'datainjectionca',
        'PluginDatainjectionCaNetworkNameInjection'                 => 'datainjectionca',
        'PluginDatainjectionCaNetpointInjection'                    => 'datainjectionca',
        'PluginDatainjectionCaKnowbaseItemCategoryInjection'        => 'datainjectionca',
        'PluginDatainjectionCaKnowbaseItemInjection'                => 'datainjectionca',
        'PluginDatainjectionCaITILFollowupTemplateInjection'        => 'datainjectionca',
        'PluginDatainjectionCaITILCategoryInjection'                => 'datainjectionca',
        'PluginDatainjectionCaTaskCategoryInjection'                => 'datainjectionca',
        'PluginDatainjectionCaTaskTemplateInjection'                => 'datainjectionca',
        'PluginDatainjectionCaSolutionTypeInjection'                => 'datainjectionca',
        'PluginDatainjectionCaRequestTypeInjection'                 => 'datainjectionca',
        'PluginDatainjectionCaSolutionTemplateInjection'            => 'datainjectionca',
        'PluginDatainjectionCaComputerTypeInjection'                => 'datainjectionca',
        'PluginDatainjectionCaMonitorTypeInjection'                 => 'datainjectionca',
        'PluginDatainjectionCaNetworkEquipmentTypeInjection'        => 'datainjectionca',
        'PluginDatainjectionCaPeripheralTypeInjection'              => 'datainjectionca',
        'PluginDatainjectionCaPrinterTypeInjection'                 => 'datainjectionca',
        'PluginDatainjectionCaPhoneTypeInjection'                   => 'datainjectionca',
        'PluginDatainjectionCaSoftwareLicenseTypeInjection'         => 'datainjectionca',
        'PluginDatainjectionCaContractTypeInjection'                => 'datainjectionca',
        'PluginDatainjectionCaContactTypeInjection'                 => 'datainjectionca',
        'PluginDatainjectionCaSupplierTypeInjection'                => 'datainjectionca',
        'PluginDatainjectionCaDeviceMemoryTypeInjection'            => 'datainjectionca',
        'PluginDatainjectionCaInterfaceTypeInjection'               => 'datainjectionca',
        'PluginDatainjectionCaPhonePowerSupplyTypeInjection'        => 'datainjectionca',
        'PluginDatainjectionCaFilesystemTypeInjection'              => 'datainjectionca',
        'PluginDatainjectionCaComputerModelInjection'               => 'datainjectionca',
        'PluginDatainjectionCaMonitorModelInjection'                => 'datainjectionca',
        'PluginDatainjectionCaPhoneModelInjection'                  => 'datainjectionca',
        'PluginDatainjectionCaPrinterModelInjection'                => 'datainjectionca',
        'PluginDatainjectionCaPeripheralModelInjection'             => 'datainjectionca',
        'PluginDatainjectionCaNetworkEquipmentModelInjection'       => 'datainjectionca',
        //'PluginDatainjectionCaNetworkEquipmentFirmwareInjection'    => 'datainjectionca',
        'PluginDatainjectionCaVirtualMachineTypeInjection'          => 'datainjectionca',
        'PluginDatainjectionCaVirtualMachineSystemInjection'        => 'datainjectionca',
        'PluginDatainjectionCaVirtualMachineStateInjection'         => 'datainjectionca',
        'PluginDatainjectionCaDocumentTypeInjection'                => 'datainjectionca',
        'PluginDatainjectionCaAutoUpdateSystemInjection'            => 'datainjectionca',
        'PluginDatainjectionCaOperatingSystemInjection'             => 'datainjectionca',
        'PluginDatainjectionCaOperatingSystemVersionInjection'      => 'datainjectionca',
        'PluginDatainjectionCaOperatingSystemServicePackInjection'  => 'datainjectionca',
        'PluginDatainjectionCaOperatingSystemKernelInjection'       => 'datainjectionca',
        'PluginDatainjectionCaOperatingSystemKernelVersionInjection' => 'datainjectionca',
        'PluginDatainjectionCaOperatingSystemEditionInjection'      => 'datainjectionca',
        'PluginDatainjectionCaItem_OperatingSystemInjection'        => 'datainjectionca',
        'PluginDatainjectionCaNetworkInterfaceInjection'            => 'datainjectionca',
        'PluginDatainjectionCaDomainInjection'                      => 'datainjectionca',
        'PluginDatainjectionCaNetworkInjection'                     => 'datainjectionca',
        'PluginDatainjectionCaDeviceCaseInjection'                  => 'datainjectionca',
        'PluginDatainjectionCaDeviceCaseTypeInjection'              => 'datainjectionca',
        'PluginDatainjectionCaDeviceControlInjection'               => 'datainjectionca',
        'PluginDatainjectionCaDeviceProcessorInjection'             => 'datainjectionca',
        'PluginDatainjectionCaDeviceMemoryInjection'                => 'datainjectionca',
        'PluginDatainjectionCaDeviceHardDriveInjection'             => 'datainjectionca',
        'PluginDatainjectionCaDeviceMotherboardInjection'           => 'datainjectionca',
        'PluginDatainjectionCaDeviceDriveInjection'                 => 'datainjectionca',
        'PluginDatainjectionCaDeviceNetworkCardInjection'           => 'datainjectionca',
        'PluginDatainjectionCaApplianceInjection'                   => 'datainjectionca',
        'PluginDatainjectionCaCertificateInjection'                 => 'datainjectionca',
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
    Plugin::doHook('plugin_datainjectionca_populate');
}


function plugin_datainjectionca_migratetypes($types)
{

    $types[996] = 'NetworkPort';
    $types[999] = 'NetworkPort';
    return $types;
}


function plugin_datainjectionca_checkDirectories()
{
    return !(!file_exists(PLUGIN_DATAINJECTION_CA_UPLOAD_DIR) || !is_writable(PLUGIN_DATAINJECTION_CA_UPLOAD_DIR));
}

function plugin_datainjectionca_geturl(): string
{
    /** @var array $CFG_GLPI */
    global $CFG_GLPI;
    return sprintf('%s/plugins/datainjectionca/', $CFG_GLPI['root_doc']);
}
