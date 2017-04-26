<?php

/**
 * @file The hosting feature definition for hosting_platform_git.
 */

/**
 * Implements hook_hosting_feature().
 */
function hosting_platform_git_hosting_feature() {
  $features['platform_git'] = array(
    'title' => t('Git Deploy'),
    'description' => t('Deploy platforms using Git.'),
    'status' => HOSTING_FEATURE_DISABLED,
    'module' => 'hosting_platform_git',
    'group' => 'experimental',
  );

  return $features;
}
