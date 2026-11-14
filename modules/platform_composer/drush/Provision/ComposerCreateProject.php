<?php
/**
 * @file
 * The Provision_ComposerCreateProject class.
 */

class Provision_ComposerCreateProject extends Provision_DeployCommand {

  // The prefix used for properties in Aegir contexts.
  protected $context_prefix = 'composer_project_';

  // List of properties to load from the Aegir context.
  protected $context_properties = ['path', 'package', 'version'];

  // The local path in which we'll deploy the project.
  protected $path = FALSE;

  // The Composer package to use to create the project.
  protected $package = FALSE;

  // The version of the Composer package to use.
  protected $version = FALSE;

  protected function buildPath() {
    return $this->path;
  }

  protected function pathExistsMessage() {
    return dt('Composer project path already exists. Aborting.');
  }

  protected function announceBuild() {
    $this->notice(dt('Creating project from package `:package` at `:path`', [
      ':package' => $this->package,
      ':path' => $this->path,
    ]));
  }

  protected function buildSteps() {
    return ['createProject'];
  }

  protected function createProject() {
    return $this->execCommand($this->buildCreateProjectCommand());
  }

  protected function buildCreateProjectCommand() {
    $command = 'composer create-project --no-dev --no-interaction --no-progress';
    $command .= ' ' . escapeshellarg(trim($this->package));
    $command .= ' ' . escapeshellarg(trim($this->path));
    $command .= ' ' . escapeshellarg(trim($this->version));
    return $command;
  }

  public function postProvisionDelete() {
    if ($this->removeUnfinishedLeftover('composer_project_path')) {
      return;
    }
    if ($this->path != d()->root) {
      $this->notice(dt('Deleting Composer project path at: ') . d()->composer_project_path);
      _provision_recursive_delete($this->path);
    }
    $this->clearBuildMarker();
  }

}
