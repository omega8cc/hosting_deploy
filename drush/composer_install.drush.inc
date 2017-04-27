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
function drush_composer_install_pre_provision_verify() {
  if (d()->type =='platform') {
    $platform = new Provision_ComposerInstall();
    $platform->preProvisionVerify();
  }
}

/**
 * Register our directory as a place to find provision classes.
 */
function composer_install_register_autoload() {
  static $loaded = FALSE;
  if (!$loaded) {
    $loaded = TRUE;
    provision_autoload_register_prefix('Provision_', dirname(__FILE__));
  }
}

/**
 * Implements hook_drush_init().
 */
function composer_install_drush_init() {
  composer_install_register_autoload();
}

