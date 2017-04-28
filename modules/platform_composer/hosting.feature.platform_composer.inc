<?php

/**
 * @file The hosting feature definition for hosting_platform_composer.
 */

/**
 * Implements hook_hosting_feature().
 */
function hosting_platform_composer_hosting_feature() {
  $features['platform_composer'] = array(
    'title' => t('Composer Deploy'),
    'description' => t('Deploy platforms using Composer.'),
    'status' => HOSTING_FEATURE_DISABLED,
    'module' => 'hosting_platform_composer',
    'group' => 'experimental',
  );

  return $features;
}
