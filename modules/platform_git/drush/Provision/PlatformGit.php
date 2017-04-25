<?php
/**
 * @file
 * The Provision_PlatformGit class.
 */

class Provision_PlatformGit extends Provision_ShellCommand {

  // The prefix used for properties in Aegir contexts.
  protected $context_prefix = 'git_';

  // List of properties to load from the Aegir context.
  protected $context_properties = ['repository_url', 'repository_path', 'reference'];

  // The Git reporitory URL from whence we'll clone.
  protected $repository_url = FALSE;

  // The local path to which we'll clone the repository.
  protected $repository_path = FALSE;

  // The Git reference that we'll clone or checkout.
  protected $reference = FALSE;

  public function validateProvisionVerify() {
    if ($this->pathExists($this->repository_path)) {
      return $this->error(dt('Platform Git repository path already exists. Aborting.'));
    }
    else {
      $this->notice(dt("Platform Git repository path does not exist."));
      return $this->gitClone();
    }
  }

  /**
   * Implements the provision-git-clone command.
   */
  protected function gitClone() {
    $this->notice(dt('Cloning `:url` to `:path`', [
      ':url' => $this->repository_url,
      ':path' => $this->repository_path,
    ]));

    $command = 'git clone --recursive --depth=1';
    if ($this->referenceIsBranch()) {
      $command .= ' --branch ' . escapeshellarg(trim($this->reference));
    }
    $command .= ' ' . escapeshellarg(trim($this->repository_url));
    $command .= ' ' . escapeshellarg(trim($this->repository_path));

    return $this->runCommand($command);
  }

  protected function runCommand($command) {

    $this->notice("Running `$command`");
    if (drush_shell_exec($command)) {
      $this->success(dt('Clone successful.'));
      $this->success(implode("\n", drush_shell_exec_output()));
    }
    else {
      return $this->error(dt("Git clone failed! \nThe specific errors are below:\n!errors", array('!errors' => implode("\n", drush_shell_exec_output()))));
    }
  }

  protected function referenceIsBranch() {
    // TODO: implement this check so that we can determine whether to clone a
    // branch, or the full repo, the checkout.
    return TRUE;
  }

  public function postProvisionDelete() {
    if ($this->repository_path != d()->root) {
      $this->notice(t('Deleting repo path at: ') . d()->repo_path);
      _provision_recursive_delete($this->repository_path);
    }
  }

}
