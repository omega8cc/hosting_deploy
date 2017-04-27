<?php
/**
 * @file
 * Expose the distribution features to hostmaster.
 */

/**
 * Implements hook_hosting_feature().
 */
function hosting_composer_hosting_feature() {
  $features = array();

  $features['composer'] = array(
    'title' => t('Composer Install'),
    'description' => t('Build platform dependencies using Composer.'),
    'status' => HOSTING_FEATURE_DISABLED,
    'module' => 'hosting_composer',
    'group' => 'experimental',
  );

  return $features;
}
