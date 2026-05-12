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

use Glpi\Application\View\TemplateRenderer;

use function Safe\ob_start;
use function Safe\ob_get_clean;

class PluginDatainjectionCaInfo extends CommonDBTM
{
    public static $rightname = "plugin_datainjectionca_model";

    public function getEmpty()
    {
        $this->fields['itemtype']     = PluginDatainjectionCaInjectionType::NO_VALUE;
        $this->fields['value']        = PluginDatainjectionCaInjectionType::NO_VALUE;
        $this->fields['is_mandatory'] = 0;

        return true;
    }


    public function isMandatory()
    {
        return $this->fields["is_mandatory"];
    }

    public function getValue()
    {

        return $this->fields["value"];
    }


    public function getID()
    {

        return $this->fields["id"];
    }


    public function getInfosType()
    {

        return $this->fields["itemtype"];
    }

    /**
    * @param PluginDatainjectionCaModel $model     PluginDatainjectionCaModel object
    * @param boolean $canedit   (false by default)
   **/
    public static function showAddInfo(PluginDatainjectionCaModel $model, $canedit = false)
    {

        if ($canedit) {
            echo "<form method='post' name='form' id='form' action='" .
             Toolbox::getItemTypeFormURL(self::class) . "'>";
            echo "<table class='tab_cadre_fixe'>";
            echo "<tr>";
            echo "<th>" . __s('Tables', 'datainjectionca') . "</th>";
            echo "<th>" . _sn('Field', 'Fields', 2) . "</th>";
            echo "<th>" . __s('Mandatory information', 'datainjectionca') . "</th>";
            echo "</tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td class='center'>";
            $infos_id                  = -1;
            $info                      = new PluginDatainjectionCaInfo();
            $info->fields['id']        = -1;
            $info->fields['models_id'] = $model->fields['id'];
            $info->getEmpty();

            $rand = PluginDatainjectionCaInjectionType::dropdownLinkedTypes(
                $info,
                ['primary_type'
                                                                        => $model->fields['itemtype'],
                ],
            );
            echo "</td>";
            echo "<td class='center'><span id='span_field_$infos_id'></span></td>";
            echo "<td class='center'><span id='span_mandatory_$infos_id'></span></td>";
            echo "</tr>";

            echo "<tr>";
            echo "<td class='tab_bg_2 center' colspan='4'>";
            echo "<input type='hidden' name='models_id' value='" . $model->fields['id'] . "'>";
            echo "<input type='submit' name='update' value='" . _sx('button', 'Add') . "' class='submit' >";
            echo "</td></tr>";

            echo "</table>";
            Html::closeForm();
            echo "<br/>";
        }
    }


    /**
    * Display additional information form from Model form
    *
    * @param PluginDatainjectionCaModel $model
    */
    public static function showFormInfos(PluginDatainjectionCaModel $model)
    {

        $canedit = $model->can($model->fields['id'], UPDATE);
        self::showAddInfo($model, $canedit);

        $model->loadInfos();
        $nb = count($model->getInfos());
        $rand = mt_rand();
        if ($nb > 0) {
            echo "<form method='post' name='info_form$rand' id='info_form$rand' action='" . Toolbox::getItemTypeFormURL(self::class) . "'>";
            echo "<table class='tab_cadre_fixe'>";
            echo "<tr>";
            if ($canedit) {
                echo "<th>&nbsp;</th>";
            }
            echo "<th>" . __s('Tables', 'datainjectionca') . "</th>";
            echo "<th>" . __s('Fields', 'datainjectionca') . "</th>";
            echo "<th>" . __s('Mandatory information', 'datainjectionca') . "</th>";
            echo "</tr>";

            foreach ($model->getInfos() as $info) {
                $infos_id     = $info->fields['id'];
                echo "<tr class='tab_bg_1'>";
                if ($canedit) {
                    echo "<td width='10'>";
                    $sel = "";
                    if (isset($_GET["select"]) && ($_GET["select"] == "all")) {
                        $sel = "checked";
                    }
                    echo "<input type='checkbox' name='item[" . $infos_id . "]' value='1' $sel>";
                    echo "</td>";
                }
                echo "<td class='center'>";
                $rand = PluginDatainjectionCaInjectionType::dropdownLinkedTypes(
                    $info,
                    ['primary_type'
                                                                          => $model->fields['itemtype'],
                    ],
                );
                echo "</td>";
                echo "<td class='center'><span id='span_field_$infos_id'></span></td>";
                echo "<td class='center'><span id='span_mandatory_$infos_id'></span></td></tr>";
            }

            if ($canedit) {
                echo "<tr>";
                echo "<td class='tab_bg_2 center' colspan='4'>";
                echo "<input type='hidden' name='models_id' value='" . $model->fields['id'] . "'>";
                echo "<input type='submit' name='update' value='" . _sx('button', 'Save') . "' class='submit'>";
                echo "</td></tr>";

                $formname = 'info_form' . $rand;
                echo "<table width='950px'>";
                $arrow = "fas fa-level-up-alt";

                echo "<tr>";
                echo "<td><i class='$arrow fa-flip-horizontal fa-lg mx-2'></i></td>";
                echo "<td class='center' style='white-space:nowrap;'>";
                echo "<a onclick= \"if ( markCheckboxes('$formname') ) return false;\" href='#'>" . __s('Check all') . "</a></td>";
                echo "<td>/</td>";
                echo "<td class='center' style='white-space:nowrap;'>";
                echo "<a onclick= \"if ( unMarkCheckboxes('$formname') ) return false;\" href='#'>" . __s('Uncheck all') . "</a></td>";
                echo "<td class='left' width='80%'>";

                echo "<input type='submit' name='delete' ";
                echo "value=\"" . addslashes(_sx('button', 'Delete permanently')) . "\" class='btn btn-primary'>&nbsp;";
                echo "</td></tr>";
                echo "</table>";
            }
            echo "</table>";
            Html::closeForm();
        }
    }


    /**
    * @param int $models_id
    * @param array $infos        array
   **/
    public static function manageInfos($models_id, $infos = [])
    {
        /** @var DBmysql $DB */
        global $DB;

        $info = new self();

        if (isset($_POST['data']) && is_array($_POST['data']) && count($_POST['data'])) {
            foreach ($_POST['data'] as $id => $info_infos) {
                $info_infos['id'] = $id;
                //If no field selected, reset other values

                if ($info_infos['value'] == PluginDatainjectionCaInjectionType::NO_VALUE) {
                    $info_infos['itemtype']     = PluginDatainjectionCaInjectionType::NO_VALUE;
                    $info_infos['is_mandatory'] = 0;
                } else {
                    $info_infos['is_mandatory'] = (isset($info_infos['is_mandatory']) ? 1 : 0);
                }

                if ($id > 0) {
                    $info->update($info_infos);
                } else {
                    $info_infos['models_id'] = $models_id;
                    unset($info_infos['id']);
                    $info->add($info_infos);
                }
            }
        }

        $info->deleteByCriteria(
            ['models_id' => $models_id,
                'value'     => PluginDatainjectionCaInjectionType::NO_VALUE,
            ],
        );
    }


    /**
    * @param PluginDatainjectionCaModel $model     PluginDatainjectionCaModel object
   **/
    public static function showAdditionalInformationsForm(PluginDatainjectionCaModel $model)
    {
        $infos = getAllDataFromTable(
            'glpi_plugin_datainjectionca_infos',
            ['models_id' => $model->getField('id')],
        );

        $modeltype = PluginDatainjectionCaModel::getInstance($model->getField('filetype'));
        $modeltype->getFromDBByModelID($model->getField('id'));

        $rendered_infos = [];
        foreach ($infos as $info_data) {
            $info = new self();
            $info->fields = $info_data;

            ob_start();
            self::displayAdditionalInformation($info, $_SESSION['datainjectionca']['infos'] ?? []);
            $rendered_infos[] = ob_get_clean();
        }

        $data = [
            'infos' => $infos,
            'rendered_infos' => $rendered_infos,
            'model' => $model,
            'modeltype' => $modeltype,
            'has_sample' => $modeltype->haveSample(),
            'comment' => $model->fields['comment'],
            'session_infos' => $_SESSION['datainjectionca']['infos'] ?? [],
        ];

        // Store models_id in session for future usage
        $_SESSION['datainjectionca']['models_id'] = $model->getField('id');

        // Render the Twig template
        TemplateRenderer::getInstance()->display('@datainjectionca/infoadditionnalinfo.html.twig', $data);

        // Show the upload file form
        $options['models_id'] = $model->getField('id');
        $options['confirm'] = 'process';
        PluginDatainjectionCaClientInjection::showUploadFileForm($options);
    }


    /**
    * @param PluginDatainjectionCaInfo $info               PluginDatainjectionCaInfo object
    * @param array $values    array
    */
    public static function displayAdditionalInformation(PluginDatainjectionCaInfo $info, $values = [])
    {

        $injectionClass
        = PluginDatainjectionCaCommonInjectionLib::getInjectionClassInstance($info->fields['itemtype']);
        $option
        = PluginDatainjectionCaCommonInjectionLib::findSearchOption(
            $injectionClass->getOptions($info->fields['itemtype']),
            $info->fields['value'],
        );
        if ($option) {
            echo "<td>" . $option['name'] . "</td><td>";
            self::showAdditionalInformation($info, $option, $injectionClass, $values);
            echo "</td>";
        }
    }


    /**
     * Display command additional informations
     *
     * @param PluginDatainjectionCaInfo $info
     * @param array $option
     * @param PluginDatainjectionCaInjectionInterface $injectionClass
     *
     * @return void
     */
    public static function showAdditionalInformation(
        PluginDatainjectionCaInfo $info,
        $option,
        $injectionClass,
        $values = []
    ) {

        $name = "info[" . $option['linkfield'] . "]";

        if (isset($_SESSION['datainjectionca']['infos'][$option['linkfield']])) {
            $value = $_SESSION['datainjectionca']['infos'][$option['linkfield']];
        } else {
            $value = '';
        }

        switch ($option['displaytype']) {
            case 'text':
            case 'decimal':
                if (empty($value)) {
                    $value = ($option['default'] ?? '');
                }
                echo "<input type='text' name='$name' value='$value'";
                if (isset($option['size'])) {
                    echo " size='" . $option['size'] . "'";
                }
                echo ">";
                break;

            case 'dropdown':
                if ($value == '') {
                    $value = 0;
                }
                Dropdown::show(
                    getItemTypeForTable($option['table']),
                    ['name'  => $name,
                        'value' => $value,
                    ],
                );
                break;

            case 'bool':
                if ($value == '') {
                    $value = 0;
                }
                Dropdown::showYesNo($name, $value);
                break;

            case 'user':
                if ($value == '') {
                    $value = 0;
                }
                User::dropdown(
                    ['name'  => $name,
                        'value' => $value,
                    ],
                );
                break;

            case 'date':
                Html::showDateField($name, ['value' => $value]);
                break;

            case 'multiline_text':
                echo "<textarea cols='45' rows='5' name='$name'>$value</textarea>";
                break;

            case 'dropdown_integer':
                $minvalue = ($option['minvalue'] ?? 0);
                $maxvalue = ($option['maxvalue'] ?? 0);
                $step     = ($option['step'] ?? 1);
                $default  = (isset($option['-1']) ? [-1 => $option['-1']] : []);

                Dropdown::showNumber(
                    $name,
                    ['value' => $value,
                        'min'   => $minvalue,
                        'max'   => $maxvalue,
                        'step'  => $step,
                        'toadd' => $default,
                    ],
                );
                break;

            case 'template':
                self::dropdownTemplates($name, $option['table']);
                break;

            case 'password':
                echo "<input type='password' name='$name' value='' size='20' autocomplete='off'>";
                break;

            default:
                if (method_exists($injectionClass, 'showAdditionalInformation')) {
                    //If type is not a standard type, must be treated by specific injection class
                    $injectionClass->showAdditionalInformation($info, $option);
                }
        }

        if ($info->isMandatory()) {
            echo "&nbsp;*";
        }
    }


    /**
    * @param PluginDatainjectionCaInfo $info      PluginDatainjectionCaInfo object
    * @param string $value
   **/
    public static function keepInfo(PluginDatainjectionCaInfo $info, $value)
    {

        $itemtype       = $info->getInfosType();
        $injectionClass = PluginDatainjectionCaCommonInjectionLib::getInjectionClassInstance($itemtype);
        $options        = $injectionClass->getOptions($itemtype);
        $option         = PluginDatainjectionCaCommonInjectionLib::findSearchOption(
            $options,
            $info->getValue(),
        );

        if ($option) {
            switch ($option['displaytype']) {
                default:
                case 'text':
                case 'multiline_text':
                    return $value != PluginDatainjectionCaCommonInjectionLib::EMPTY_VALUE;

                case 'dropdown':
                case 'user':
                case 'contact':
                    return $value != PluginDatainjectionCaCommonInjectionLib::DROPDOWN_EMPTY_VALUE;
            }
        }
    }


    /**
    * @param string $name
    * @param string $table
   **/
    public static function dropdownTemplates($name, $table)
    {
        /** @var DBmysql $DB */
        global $DB;

        $values    = [0 => Dropdown::EMPTY_VALUE];

        $sql = "SELECT `id`, `template_name`
              FROM `" . $table . "`
              WHERE `is_template`=1 " .
                  getEntitiesRestrictRequest(' AND ', $table) .
           "ORDER BY `template_name`";

        foreach ($DB->doQuery($sql) as $data) {
            $values[$data['id']] = $data['template_name'];
        }
        Dropdown::showFromArray($name, $values);
    }
}
