<?php

/**
 * @file
 * Provision/Drush hooks for composer commands.
 */

/**
 * Implements drush_HOOK_COMMAND_validate().
 *
 * This needs to be called in the validate step so that it runs before the
 * Makefile check and the check for a Drupal installation. Those operations,
 * very inconveniently, run in the same function.
 */
function drush_platform_composer_provision_verify_validate() {
  if (d()->type == 'platform' && d()->composer_project_package) {
    $platform = new Provision_ComposerCreateProject();
    return $platform->validateProvisionVerify();
  }
  return _platform_composer_shell_build_guard('Verify');
}

/**
 * The folders of a platform added by its path that a build as the account's
 * shell login may have made, each with a marker beside it naming the login:
 * the docroot's parent, the docroot. None for a platform the panel built.
 */
function _platform_composer_shell_build_folders() {
  // The parent first: a copy of this module older than the build from a
  // platform's own lock has neither class.
  if (d()->type != 'platform' || !class_exists('Provision_ComposerLockBuild') || !class_exists('Provision_ComposerShellBuild')) {
    return array();
  }
  foreach (array('composer_project_package', 'composer_project_path', 'composer_git_path', 'composer_git_project_url', 'git_repository_url', 'git_repository_path', 'repo_url', 'makefile') as $property) {
    if (d()->{$property}) {
      return array();
    }
  }
  $root = rtrim((string) d()->root, '/');
  if ($root === '' || $root[0] !== '/') {
    return array();
  }
  $out = array();
  foreach (array_unique(array(dirname($root), $root)) as $folder) {
    if (Provision_ComposerShellBuild::markerNamesLogin($folder)) {
      $out[] = $folder;
    }
  }
  return $out;
}

/**
 * Refuses a task of a platform added by its path while a build as the shell
 * login writes into its folder, and a Verify while that build never finished:
 * no build strategy runs such a platform's tasks to ask its own class.
 */
function _platform_composer_shell_build_guard($what) {
  foreach (_platform_composer_shell_build_folders() as $folder) {
    $build = new Provision_ComposerShellBuild(array('path' => $folder));
    $why = $build->taskRefusal(dt($what), $what === 'Verify');
    if ($why !== '') {
      return drush_set_error(($what === 'Verify' && strpos($why, 'has not finished') !== FALSE) ? 'PLATFORM_BUILD_UNFINISHED' : 'PLATFORM_BUILD_STILL_RUNNING', $why);
    }
  }
}

/**
 * Implements drush_HOOK_COMMAND_validate() for provision-delete.
 *
 * Refuses the Delete, before anything is removed, while a build of the
 * platform's folder still runs.
 */
function drush_platform_composer_provision_delete_validate() {
  if (d()->type == 'platform' && d()->composer_project_path) {
    $platform = new Provision_ComposerCreateProject();
    return $platform->validateProvisionDelete();
  }
  return _platform_composer_shell_build_guard('Delete');
}

/**
 * Implements drush_HOOK_post_COMMAND() for provision-delete command.
 *
 * If repository path is different from root, delete it too.
 */
function drush_platform_composer_post_provision_delete() {
  if (d()->type =='platform') {
    // A build as the shell login: its marker goes once its folder has.
    foreach (_platform_composer_shell_build_folders() as $folder) {
      $build = new Provision_ComposerShellBuild(array('path' => $folder));
      $build->forgetGoneBuild();
    }
    $platform = new Provision_ComposerCreateProject();
    return $platform->postProvisionDelete();
  }
}

/**
 * Implements hook_composer_install_path().
 */
function platform_composer_composer_install_path() {
  return d()->composer_project_path;
}

/**
 * Implements hook_composer_install_paths_alter().
 */
function platform_composer_composer_install_paths_alter(&$paths) {
  // Provide a path for hosting_git.module
  $paths[] = d()->repo_path;
}

/**
 * Register our directory as a place to find provision classes.
 */
function platform_composer_register_autoload() {
  static $loaded = FALSE;
  if (!$loaded) {
    $loaded = TRUE;
    provision_autoload_register_prefix('Provision_', dirname(__FILE__));
    // The build class the strategies of this project share.
    provision_autoload_register_prefix('Provision_', dirname(dirname(dirname(dirname(__FILE__)))) . '/drush');
  }
}

/**
 * Implements hook_drush_init().
 */
function platform_composer_drush_init() {
  platform_composer_register_autoload();
}

/**
 *  Implements hook_provision_services().
 */
function platform_composer_provision_services() {
  platform_composer_register_autoload();
  return array('platform_composer' => NULL);
}
