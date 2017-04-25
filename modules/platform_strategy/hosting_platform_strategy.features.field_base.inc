<?php
/**
 * @file
 * hosting_platform_strategy.features.field_base.inc
 */

/**
 * Implements hook_field_default_field_bases().
 */
function hosting_platform_strategy_field_default_field_bases() {
  $field_bases = array();

  // Exported field_base: 'field_deployment_strategy'.
  $field_bases['field_deployment_strategy'] = array(
    'active' => 1,
    'cardinality' => 1,
    'deleted' => 0,
    'entity_types' => array(),
    'field_name' => 'field_deployment_strategy',
    'indexes' => array(
      'value' => array(
        0 => 'value',
      ),
    ),
    'locked' => 0,
    'module' => 'list',
    'settings' => array(
      'allowed_values' => array(
        'frommakefile' => 'Deploy from Drush makefile',
      ),
      'allowed_values_function' => 'hosting_platform_strategy_get_strategies_options_list',
    ),
    'translatable' => 0,
    'type' => 'list_text',
  );

  return $field_bases;
}
