<?php

/**
 * @file
 * Provision/Drush hooks for Composer install.
 */

/**
 * Implements drush_HOOK_pre_COMMAND().
 *
 * We need to run 'composer install' after the platform has been created, in
 * the validate hook, but before the rest of hosting_platform kicks in.
 */
function drush_platform_composer_pre_provision_verify() {
  if (d()->type =='platform') {
    $platform = new Provision_ComposerInstall();
    if ($platform->initialize()) {
      return $platform->preProvisionVerify();
    }
  }
}

/**
 * Register our directory as a place to find provision classes.
 */
function platform_composer_register_autoload() {
  static $loaded = FALSE;
  if (!$loaded) {
    $loaded = TRUE;
    provision_autoload_register_prefix('Provision_', dirname(__FILE__));
  }
}

/**
 * Implements hook_drush_init().
 */
function platform_composer_drush_init() {
  platform_composer_register_autoload();
}

