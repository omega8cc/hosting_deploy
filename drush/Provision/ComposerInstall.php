<?php
/**
 * @file
 * The Provision_PlatformComposer class.
 */

class Provision_ComposerInstall extends Provision_ShellCommand {

  // The prefix used for properties in Aegir contexts.
  protected $context_prefix = 'git_';

  // List of properties to load from the Aegir context.
  protected $context_properties = ['repository_path'];

  // The local path to which we'll clone the repository.
  protected $repository_path = FALSE;

  public function preProvisionVerify() {
    if (!$this->checkComposerInstall()) {
      return;
    }
    if (!$this->pathExists($this->repository_path)) {
      return $this->error(dt('Platform repository path does not exist. Cannot install dependencies. Aborting.'));
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
    if (file_exists($this->repository_path . '/composer.lock')) {
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
    if (file_exists($this->repository_path . '/composer.json')) {
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
    $command = 'cd ' . escapeshellarg(trim($this->repository_path));
    $command .= ' && composer install --no-progress --prefer-dist --no-interaction --no-suggest';
    return $command;
  }

}
