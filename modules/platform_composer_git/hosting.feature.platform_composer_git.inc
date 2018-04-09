<?php

/**
 * @file The hosting feature definition for hosting_platform_composer_git.
 */

/**
 * Implements hook_hosting_feature().
 */
function hosting_platform_composer_git_hosting_feature() {
  $features['platform_composer_git'] = array(
    'title' => t('Composer Deploy (from source)'),
    'description' => t('Deploy platforms using Composer.'),
    'status' => HOSTING_FEATURE_DISABLED,
    'module' => 'hosting_platform_composer_git',
    'group' => 'experimental',
  );

  return $features;
}
