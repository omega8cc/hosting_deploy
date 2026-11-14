<?php
/**
 * @file
 * The Provision_ComposerGitCreateProject class.
 */

class Provision_ComposerGitCreateProject extends Provision_DeployCommand {

  // The prefix used for properties in Aegir contexts.
  protected $context_prefix = 'composer_git_';

  // List of properties to load from the Aegir context.
  protected $context_properties = ['path', 'project_url', 'version'];

  // The local path in which we'll deploy the project.
  protected $path = FALSE;

  // The Git URL of the Composer project to clone.
  protected $project_url = FALSE;

  // The Composer package to use to create the project.
  protected $package = FALSE;

  // The branch or tag of the repository to check out.
  protected $version = FALSE;

  protected function buildPath() {
    return $this->path;
  }

  protected function pathExistsMessage() {
    return dt('Composer project path already exists. Aborting.');
  }

  protected function checkExistingBuild() {
    $this->warnOnUnknownReference($this->path, $this->version);
  }

  protected function announceBuild() {
    $this->notice(dt('Creating `:version` version of project from `:project_url` at `:path`', [
      ':version' => $this->version,
      ':project_url' => $this->project_url,
      ':path' => $this->path,
    ]));
  }

  protected function buildSteps() {
    return ['cloneProject', 'checkoutVersion', 'installProject'];
  }

  protected function cloneProject() {
    return $this->execCommand($this->buildCloneProjectCommand());
  }

  protected function checkoutVersion() {
    return $this->execCommand($this->buildCheckoutVersionCommand());
  }

  protected function installProject() {
    return $this->execCommand($this->buildCreateProjectCommand());
  }

  protected function buildCloneProjectCommand() {
    $command = 'git clone ';
    $command .= escapeshellarg(trim($this->project_url));
    $command .= ' ';
    $command .= escapeshellarg(trim($this->path));
    return $command;
  }

  protected function buildCheckoutVersionCommand() {
    $command = 'cd ';
    $command .= escapeshellarg(trim($this->path));
    $command .= ' && git checkout ';
    $command .= escapeshellarg(trim($this->version));
    return $command;
  }

  protected function buildCreateProjectCommand() {
    $path = escapeshellarg(trim($this->path));
    $command = "composer install --working-dir=$path --no-dev --no-interaction --no-progress";
    return $command;
  }

  public function postProvisionDelete() {
    if ($this->path != d()->root) {
      $this->notice(dt('Deleting Composer project path at: ') . d()->composer_git_path);
      _provision_recursive_delete($this->path);
    }
    $this->clearBuildMarker();
  }

}
