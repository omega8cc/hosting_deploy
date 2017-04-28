<?php
/**
 * @file
 * The Provision_PlatformComposer class.
 */

class Provision_ComposerInstall extends Provision_ShellCommand {

  // The local path in which we'll install dependencies.
  protected $path = FALSE;

  public function __construct() {
    $this->setPath();
  }

  protected function setPath() {
    $this->path = $this->findPath();
  }

  protected function findPath() {
    $paths = drush_command_invoke_all('composer_install_path');
    drush_command_invoke_all_ref('composer_install_paths_alter', $paths);
    $paths = array_filter($paths); // Filter NULL values.
    $paths = array_filter($paths, 'is_dir'); // Ensure remaining paths exist.
    switch (count($paths)) {
      case 0:
        $this->warning(dt('Could not find path for Composer install.'));
        return FALSE;
      case 1:
        break;
      default:
        $this->warning(dt('Multiple candidate paths found:'));
        $this->warning(implode("\n", $paths));
        $this->warning(dt('Proceeding with: ' . $paths[0]));
    }
    return $paths[0];
  }

  public function preProvisionVerify() {
    if (!$this->checkComposerInstall()) {
      return;
    }
    if (!$this->pathExists($this->path)) {
      return $this->error(dt('Platform repository path (:path) does not exist. Cannot install dependencies. Aborting.', [':path' => $this->path]));
    }
    else {
      $this->notice(dt('Platform Git repository path exists.'));
      return $this->checkComposerFilesExist() && $this->installDependencies();
    }
  }

  protected function checkComposerInstall() {
    $this->notice(dt('Checking whether to install dependencies with Composer.'));
    if (drush_get_option('run_composer_install', FALSE)) {
      $this->notice(dt('Proceeding with installation of dependencies.'));
      return TRUE;
    }
    else {
      $this->notice(dt('Skipping installation of dependencies.'));
      return FALSE;
    }
  }

  protected function checkComposerFilesExist() {
    return $this->checkComposerLockExists() || $this->checkComposerJsonExists();
  }

  protected function checkComposerLockExists() {
    $this->notice(dt('Checking for `composer.lock` file.'));
    if (file_exists($this->path . '/composer.lock')) {
      $this->success(dt('Found `composer.lock` file. Proceeding with installation of dependencies.'));
      return TRUE;
    }
    else {
      $this->warning(dt('`composer.lock` file was not found.'));
      return FALSE;
    }
  }

  protected function checkComposerJsonExists() {
    $this->notice(dt('Checking for `composer.json` file.'));
    if (file_exists($this->path . '/composer.json')) {
      $this->success(dt('Found `composer.json` file. Proceeding with installation of dependencies.'));
      $this->warning(dt('Installing dependencies from `composer.json` does not guarantee a reproducible build. A `composer.lock` file should be committed to this Git repository.'));
      return TRUE;
    }
    else {
      $this->error(dt('`composer.json` file was not found. Cannot install dependencies. Aborting.'));
      return FALSE;
    }
  }

  protected function installDependencies() {
    $this->notice(dt('Installing dependencies with Composer.'));
    return $this->execCommand($this->buildComposerInstallCommand());
  }

  protected function buildComposerInstallCommand() {
    $command = 'cd ' . escapeshellarg(trim($this->path));
    $command .= ' && composer install --no-progress --prefer-dist --no-interaction --no-suggest';
    return $command;
  }

}
