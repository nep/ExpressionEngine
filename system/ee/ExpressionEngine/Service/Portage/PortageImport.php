<?php
/**
 * This source file is part of the open source project
 * ExpressionEngine (https://expressionengine.com)
 *
 * @link      https://expressionengine.com/
 * @copyright Copyright (c) 2003-2022, Packet Tide, LLC (https://www.packettide.com)
 * @license   https://expressionengine.com/license Licensed under Apache License, Version 2.0
 */

namespace ExpressionEngine\Service\Portage;

use Closure;
use ExpressionEngine\Library\Data\Collection;
use ExpressionEngine\Service\Portage\PortageExport;
use ExpressionEngine\Model\Channel\ChannelField;
use ExpressionEngine\Addons\Grid\Model\GridColumn;

/**
 * Portage Service: Portage
 */
class PortageImport
{
    /**
     * @var Int Id of the site to import to
     */
    private $site_id = 1;

    /**
     * Components / models that can be ste as related as part of this portage
     *
     * @var array
     */
    private $components = array();

    /**
     * Valid elements / model instances that will be saved
     *
     * @var array
     */
    public $elements = array();

    /**
     * Associations by UUID
     *
     * @var array
     */
    private $associations = array();

    /**
     * Array holding the association TO given UUID
     *
     * @var array
     */
    private $reverseAssociations = array();

    /**
     * @var String containing the path to the channel set
     */
    private $path;

    /**
     * @var ImportResult containing the result of the import
     */
    private $result;

    /**
     * @var Array of things that would create duplicates and need to be renamed
     *
     * Looks like so:
     *		[model => [shortname] => [field_to_change => newvalue]]
     *
     * The shortname will always be the name as specified in the channel set
     * definition so that we can relate entities by name. The _original_ shortname
     * is the key on the above arrays. Tread carefully, in this class aliases should
     * never be used for identification. Do not trust `$model->shortname`.
     */
    private $aliases = array();

    /**
     * @var Associative array of top level element types and the IDs of the
     *      newly-created elements
     */
    private $insert_ids = [];

    /**
     * @param String $path Path to the channel set
     */
    public function __construct($path)
    {
        $this->path = $path;
        $this->result = new ImportResult();
    }

    /**
     * Get path to directory
     *
     * @return String Filesystem path to this set
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Portage the site id
     *
     * @param Int Id of the site we're on
     * @return void
     */
    public function setSiteId($site_id)
    {
        $this->site_id = $site_id;
    }

    /**
     * Validate the set before import
     *
     * @return ImportResult
     */
    public function validate()
    {
        $this->load();

        return $this->result;
    }

    /**
     * Deletes the source files used in the import
     */
    public function cleanUpSourceFiles()
    {
        ee('Filesystem')->delete($this->getPath());
    }

    /**
     * Consider this private. It's for relationship use only.
     */
    public function getIdsForChannels(array $titles)
    {
        $channels = array();

        foreach ($titles as $title) {
            if (isset($this->channels[$title])) {
                $channel = $this->channels[$title];
                $channels[$title] = $channel->getId();
            }
        }

        return $channels;
    }

    /**
     * Save all of the Portage entities
     *
     * @return void
     */
    public function save()
    {
        ee()->legacy_api->instantiate('channel_fields');

        // save everything - some relationships might still be missing
        foreach ($this->elements as $uuid => $modelInstance)
        {
            // -------------------------------------------
            // 'portage_import_before_save' hook.
            //  - Modify the model before saving
            //
            if (ee()->extensions->active_hook('portage_import_before_save') === true) {
                $modelInstance = ee()->extensions->call('portage_import_before_save', $this, $uuid, $modelInstance);
            }
            //
            // -------------------------------------------

            // save the model, for the first time
            $modelInstance->save();
            // now that we have model ID, update other models that might be referencing this one
            if (isset($this->reverseAssociations[$uuid])) {
                foreach ($this->reverseAssociations[$uuid] as $relatedUuid) {
                    if (isset($this->elements[$relatedUuid])) {
                        $this->elements[$relatedUuid] = $this->_setExistingAssociations($relatedUuid, $this->elements[$relatedUuid]);
                    }
                }
            }
        }
        // now, set the relationships
        foreach ($this->elements as $uuid => $modelInstance)
        {
            $uuidField = method_exists($modelInstance, 'getColumnPrefix') ? $modelInstance->getColumnPrefix() . 'uuid' : 'uuid';
            if (isset($this->associations[$uuid]) || $modelInstance instanceof ChannelField) {
                foreach ($this->associations[$uuid] as $relationship => $relatioshipData) {
                    // only if there are some data and the related models are included in portage
                    if (! empty($relatioshipData) && ! empty($relatioshipData['related']) && in_array($relatioshipData['model'], $this->components)) {
                        $relatedUuids = $relatioshipData['related'];
                        if (! is_array($relatedUuids)) {
                            if (isset($this->elements[$relatedUuids])) {
                                $modelInstance->{$relationship} = $this->elements[$relatedUuids];
                            } else {
                                $modelInstance->{$relationship} = ee('Model')->get($relatioshipData['model'])->filter($uuidField, $relatedUuids)->first();
                            }
                        } else {
                            $related = [];
                            foreach ($relatedUuids as $relatedUuid) {
                                if (! isset($this->elements[$relatedUuid])) {
                                    $related = ee('Model')->get($relatioshipData['model'])->filter($uuidField, 'IN', $relatedUuids)->all();
                                    break;
                                }
                                $related[] = $this->elements[$relatedUuid];
                            }
                            $modelInstance->{$relationship} = $related; //is_array($related) ? new Collection($related) : $related;
                        }
                    }
                }
                // if this is fieldtype, convert UUIDs back to IDs
                if ($modelInstance instanceof ChannelField || $modelInstance instanceof GridColumn) {
                    $typeProperty = $modelInstance instanceof GridColumn ? 'col_type' : 'field_type';
                    $settingsProperty = $modelInstance instanceof GridColumn ? 'col_settings' : 'field_settings';
                    $ftClassName = ee()->api_channel_fields->include_handler($modelInstance->$typeProperty);
                    $reflection = new \ReflectionClass($ftClassName);
                    $instance = $reflection->newInstanceWithoutConstructor();
                    if (isset($instance->relationship_field_settings)) {
                        $ftSettings = $modelInstance->$settingsProperty;
                        foreach ($instance->relationship_field_settings as $setting => $settingModel) {
                            // force including these models into portage
                            if (is_array($ftSettings[$setting])) {
                                $relatedIds = [];
                                foreach ($ftSettings[$setting] as $relatedUuid) {
                                    if (substr_count($relatedUuid, '-') == 4) { //looks like UUID
                                        $relatedSettingModelRecord = ee('Model')->get($settingModel)->filter('uuid', $relatedUuid)->first();
                                        if (!is_null($relatedSettingModelRecord)) {
                                            $relatedIds[] = $relatedSettingModelRecord->getId();
                                        }
                                    }
                                }
                                $ftSettings[$setting] = $relatedIds;
                            } else if (substr_count($ftSettings[$setting], '-') == 4) {//looks like UUID
                                $relatedSettingModelRecord = ee('Model')->get($settingModel)->filter('uuid', $ftSettings[$setting])->first();
                                if (!is_null($relatedSettingModelRecord)) {
                                    $ftSettings[$setting] = $relatedSettingModelRecord->getId();
                                }
                            }
                        }
                        $modelInstance->$settingsProperty = $ftSettings;
                    }
                }

                // -------------------------------------------
                // 'portage_import_before_relationships_save' hook.
                //  - Modify the model before second round of saving (with relationships)
                //
                if (ee()->extensions->active_hook('portage_import_before_relationships_save') === true) {
                    $modelInstance = ee()->extensions->call('portage_import_before_relationships_save', $this, $uuid, $modelInstance);
                }
                //
                // -------------------------------------------

                // save with all relationships
                $modelInstance->save();

                // Grids have an extra model/table that needs to be saved
                if ($modelInstance instanceof ChannelField && in_array($modelInstance->field_type, ['grid', 'file_grid'])) {
                    ee()->load->library('api');
                    ee()->legacy_api->instantiate('channel_fields');
                    ee()->load->model('grid_model');
                    ee()->grid_model->create_field($modelInstance->getId(), 'channel');
                    $columns = ee('Model')->get('grid:GridColumn')->filter('field_id', $modelInstance->getId())->all();
                    foreach ($columns as $column)
                    {
                        //ee()->grid_model->save_col_settings($column->toArray(), $column->getId(), 'channel');
                        $column = $column->toArray();
                        ee()->api_channel_fields->setup_handler($column['col_type']);
                        ee()->api_channel_fields->set_datatype(
                            $column['col_id'],
                            $column['col_settings'],
                            array(),
                            true,
                            false,
                            array(
                                'id_field' => 'col_id',
                                'type_field' => 'col_type',
                                'col_settings_method' => 'grid_settings_modify_column',
                                'col_prefix' => 'col',
                                'fields_table' => 'grid_columns',
                                'data_table' => 'channel_grid_field_' . $modelInstance->getId(),
                            )
                        );
                    }
                }

                // -------------------------------------------
                // 'portage_import_after_relationships_save' hook.
                //  - Do extra stuff after model import is complete
                //
                if (ee()->extensions->active_hook('portage_import_after_relationships_save') === true) {
                    ee()->extensions->call('portage_import_after_relationships_save', $this, $uuid, $modelInstance);
                }
                //
                // -------------------------------------------

            }
        }

        // clear caches
        ee()->functions->clear_caching('all');
        ee('CP/JumpMenu')->clearAllCaches();
    }

    /**
     * Portage manual overrides
     *
     * @return void
     */
    public function setAliases($aliases)
    {
        $this->aliases = $aliases;
    }

    /**
     * Read all the files and load up a big graph of models. Sweet!
     *
     * @return void
     */
    private function load()
    {
        $base = $this->_checkJsonExistAndValid('portage.json');
        if ($base === false) {
            return false;
        }

        ee()->legacy_api->instantiate('channel_fields');

        //might be worth to allow skipping version checks for EE and addons?

        // can only import between same minor versions
        $version = explode('.', $base['version']);
        $app_version = explode('.', ee()->config->item('app_version'));
        if ($app_version[0] != $version[0] || $app_version[1] != $version[1]) {
            $this->result->addError(lang('portage_incompatible'));
            return false;
        }

        $this->components = $base['components'];

        //try to install / update missing addons
        if (in_array('add-ons', $this->components)) {
            $addonsNotCompatible = false;
            $json = $this->_checkJsonExistAndValid('add-ons.json');
            if ($json === false) {
                return false;
            }
            foreach ($json['addons'] as $addonPortage) {
                $addon = ee('Addon')->get($addonPortage['name']);
                // we only check fieldtypes, but should we care about every add-on maybe?
                if (!$addon->hasFieldtype()) {
                    continue;
                }
                if (empty($addon)) {
                    $this->result->addError(sprintf(lang('portage_addon_missing'), $addonPortage['name']));
                    $addonsNotCompatible = true;
                    continue;
                }
                $version = explode('.', $addonPortage['version']);
                $addonVersion = explode('.', $addon->getInstalledVersion());
                if (empty($addonVersion)) {
                    $this->result->addError(sprintf(lang('portage_addon_not_installed'), $addon->getName()));
                    $addonsNotCompatible = true;
                    continue;
                }
                if ($addonVersion[0] != $version[0] || $addonVersion[1] != $version[1]) {
                    $this->result->addError(sprintf(lang('portage_addon_incompatible'), $addon->getName()));
                    $addonsNotCompatible = true;
                    continue;
                }
            }
            if ($addonsNotCompatible) {
                return false;
            }
        }

        $currentSite = ee('Model')->get('Site', $this->site_id)->first();

        $reverseExport = new PortageExport();
        $portableModels = $reverseExport->getPortableModels();
        foreach ($this->components as $model)
        {
            if ($model == 'add-ons') {
                continue;
            }
            $file = str_replace(':', '_', $model) . '.json';
            $json = $this->_checkJsonExistAndValid($file);
            if ($json === false) {
                return false;
            }
            foreach ($json as $uuid => $modelPortage) {
                //should we skip as told by user?
                if (isset($this->aliases[$model]) && isset($this->aliases[$model][$uuid]) && $this->aliases[$model][$uuid]['portage__action'] == 'skip') {
                    continue;
                }

                $uuidField = $portableModels[$model]['uuidField'];
                // grab the matching model, or the model we need to overwrite
                if (isset($this->aliases[$model]) && isset($this->aliases[$model][$uuid]) && $this->aliases[$model][$uuid]['portage__action'] == 'overwrite' && isset($this->aliases[$model][$uuid]['portage__duplicates']) && !empty($this->aliases[$model][$uuid]['portage__duplicates'])) {
                    $modelInstance = ee('Model')->get($model, (int) $this->aliases[$model][$uuid]['portage__duplicates'])->first();
                    //overwrite UUID
                    $modelInstance->setRawProperty($uuidField, $uuid);
                } else {
                    $modelInstance = ee('Model')->get($model)->filter($uuidField, $uuid)->first();
                }
                //if the model exists, and is same, we just skip
                if (!is_null($modelInstance)) {
                    $currentState = $reverseExport->getDataFromModelRecord($model, $modelInstance);
                    if ($currentState == $modelPortage) {
                        continue;
                    }
                }

                if (!empty($modelPortage['associationsByUuid'])) {
                    // set the associations
                    $this->associations[$uuid] = $modelPortage['associationsByUuid'];
                    // set the 'reverse' associations (that link here)
                    foreach ($modelPortage['associationsByUuid'] as $rel => $relData) {
                        if (is_array($relData['related'])) {
                            foreach ($relData['related'] as $relUuid) {
                                $this->reverseAssociations[$relUuid][] = $uuid;
                            }
                        } else {
                            $this->reverseAssociations[$relData['related']][] = $uuid;
                        }
                    }
                }
                unset($modelPortage['associationsByUuid']);

                if (empty($modelInstance)) {
                    $modelInstance = ee('Model')->make($model);
                }
                $modelInstance->set($modelPortage);

                // when working with custom fields, we need to pass field settings as individual properties for validation
                if (array_key_exists('field_settings', $modelPortage) && is_array($modelPortage['field_settings'])) {
                    $modelInstance->field_settings = $modelPortage['field_settings'];
                }

                // set overrides posted in the form
                
                if (isset($this->aliases[$model]) && isset($this->aliases[$model][$uuid])) {
                    foreach ($this->aliases[$model][$uuid] as $field => $value) {
                        if (strpos($field, 'portage__') !== 0) {
                            $modelInstance->$field = $value;
                        }
                    }
                }

                // site is usually required, so set to current site by default
                if ($modelInstance->hasAssociation('Site')) {
                    if (in_array($model, ['ee:ChannelField', 'ee:ChannelFieldGroup'])) {
                        // fields and groups are shared by default
                        $modelInstance->site_id = 0;
                    } else {
                        $modelInstance->Site = $currentSite;
                    }
                }

                // set the relationships with the models that already exist
                $modelInstance = $this->_setExistingAssociations($uuid, $modelInstance);

                // enforce UUID for new models
                $modelInstance->setUuid($uuid);

                $result = $modelInstance->validate();

                if ($result->failed()) {

                    foreach ($result->getFailed() as $field => $rules) {
                        $this->result->addModelError($modelInstance, $field, $rules);
                    }
                } else {
                    $this->elements[$uuid] = $modelInstance;
                }
            }
        }

        return $this->result;
    }

    private function _setExistingAssociations($uuid, $modelInstance)
    {
        $uuidField = method_exists($modelInstance, 'getColumnPrefix') ? $modelInstance->getColumnPrefix() . 'uuid' : 'uuid';
        if (isset($this->associations[$uuid])) {
            foreach ($this->associations[$uuid] as $relationship => $relatioshipData) {
                // only if there are some data and the related models are included in portage
                if (! empty($relatioshipData) && ! empty($relatioshipData['related']) && in_array($relatioshipData['model'], $this->components)) {
                    if (! is_array($relatioshipData['related'])) {
                        $modelInstance->{$relationship} = ee('Model')->get($relatioshipData['model'])->filter($uuidField, $relatioshipData['related'])->first();
                    } else {
                        $modelInstance->{$relationship} = ee('Model')->get($relatioshipData['model'])->filter($uuidField, 'IN', $relatioshipData['related'])->all();
                    }
                }
            }
        }
        return $modelInstance;
    }

    /**
     * Extract JSON file and make sure it's valid
     *
     * @param [type] $file
     * @return array
     */
    private function _checkJsonExistAndValid($file)
    {
        if (! file_exists($this->path . $file)) {
            $this->result->addError(sprintf(lang('portage_file_invalid'), $file));
            return false;
        }

        $json = json_decode(file_get_contents($this->path . $file), true);

        if (empty($json)) {
            $this->result->addError(sprintf(lang('portage_file_invalid'), $file));
            return false;
        }

        return $json;
    }

}
