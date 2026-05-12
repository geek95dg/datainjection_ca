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

class PluginDatainjectionCaDropdown
{
    public static function dateFormats()
    {

        $date_format[PluginDatainjectionCaCommonInjectionLib::DATE_TYPE_DDMMYYYY]
                                                          = __('dd-mm-yyyy', 'datainjectionca');
        $date_format[PluginDatainjectionCaCommonInjectionLib::DATE_TYPE_MMDDYYYY]
                                                          = __('mm-dd-yyyy', 'datainjectionca');
        $date_format[PluginDatainjectionCaCommonInjectionLib::DATE_TYPE_YYYYMMDD]
                                                          = __('yyyy-mm-dd', 'datainjectionca');

        return $date_format;
    }


    public static function getDateFormat($date)
    {

        $dates = self::dateFormats();
        return $dates[$date] ?? "";
    }


    public static function floatFormats()
    {

        $float_format[PluginDatainjectionCaCommonInjectionLib::FLOAT_TYPE_DOT]
                                                          = __('1 234.56', 'datainjectionca');
        $float_format[PluginDatainjectionCaCommonInjectionLib::FLOAT_TYPE_COMMA]
                                                          = __('1 234,56', 'datainjectionca');
        $float_format[PluginDatainjectionCaCommonInjectionLib::FLOAT_TYPE_DOT_AND_COM]
                                                          = __('1,234.56', 'datainjectionca');

        return $float_format;
    }


    /**
    * @param string $format
   **/
    public static function getFloatFormat($format)
    {

        $formats = self::floatFormats();
        return $formats[$format] ?? "";
    }


    public static function statusLabels()
    {

        $states[0]                                            = Dropdown::EMPTY_VALUE;
        //$states[PluginDatainjectionCaModel::INITIAL_STEP] = __('Creation of the model on going', 'datainjectionca');
        $states[PluginDatainjectionCaModel::FILE_STEP]          = __('File to inject', 'datainjectionca');
        $states[PluginDatainjectionCaModel::MAPPING_STEP]       = __('Mappings', 'datainjectionca');
        $states[PluginDatainjectionCaModel::OTHERS_STEP]        = __(
            'Additional Information',
            'datainjectionca',
        );
        $states[PluginDatainjectionCaModel::READY_TO_USE_STEP]  = __(
            'Model available for use',
            'datainjectionca',
        );
        return $states;
    }


    /**
    * Return current status of the model
    *
    * @return string
   **/
    public static function getStatusLabel($step)
    {

        $states = self::statusLabels();
        return $states[$step] ?? "";
    }

    public static function getStatusColor($step)
    {
        switch ($step) {
            case PluginDatainjectionCaModel::MAPPING_STEP:
            case PluginDatainjectionCaModel::OTHERS_STEP:
                return "#ffb832";
            case PluginDatainjectionCaModel::READY_TO_USE_STEP:
                return "#2ec41f";
            default:
                return "#ff4e4e";
        }
    }


    public static function getFileEncodingValue()
    {

        $values[PluginDatainjectionCaBackend::ENCODING_AUTO]      = __('Automatic detection', 'datainjectionca');
        $values[PluginDatainjectionCaBackend::ENCODING_UFT8]      = __('UTF-8', 'datainjectionca');
        $values[PluginDatainjectionCaBackend::ENCODING_ISO8859_1] = __('ISO8859-1', 'datainjectionca');

        return $values;
    }


    public static function portUnicityValues()
    {

        $values[PluginDatainjectionCaCommonInjectionLib::UNICITY_NETPORT_LOGICAL_NUMBER]
                                           = __('Port number');
        $values[PluginDatainjectionCaCommonInjectionLib::UNICITY_NETPORT_NAME]
                                           = __('Name');
        $values[PluginDatainjectionCaCommonInjectionLib::UNICITY_NETPORT_MACADDRESS]
                                           = __('Mac address');
        $values[PluginDatainjectionCaCommonInjectionLib::UNICITY_NETPORT_LOGICAL_NUMBER_NAME]
                                           = __('Port number') . "+" . __('Name');
        $values[PluginDatainjectionCaCommonInjectionLib::UNICITY_NETPORT_LOGICAL_NUMBER_MAC]
                                           = __('Port number') . "+" . __('Mac address');
        $values[PluginDatainjectionCaCommonInjectionLib::UNICITY_NETPORT_LOGICAL_NUMBER_NAME_MAC]
                                           = __('Port number') . "+" . __('Name') . "+" .
                                             __('Mac address');
        return $values;
    }


    /**
    * @param array $value
   **/
    public static function getPortUnicityValues($value)
    {

        $values = self::portUnicityValues();
        return $values[$value] ?? "";
    }
}
