<?php
/**
 * @file
 * The Provision_PlatformGit class.
 */

class Provision_PlatformGit extends Provision_DeployCommand {

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

  // A list of references fetched from a Git repository.
  protected $references = [];

  // Whether the repository's references were read in this run.
  protected $scanned = FALSE;

  protected function buildPath() {
    return $this->repository_path;
  }

  protected function pathExistsMessage() {
    return dt('Platform Git repository path already exists: nothing is built, the code there is used.');
  }

  protected function checkExistingBuild() {
    $this->warnOnUnknownReference($this->repository_path, $this->reference);
  }

  protected function buildSteps() {
    return ['gitClone', 'gitCheckout'];
  }

  protected function gitClone() {
    $this->notice(dt('Cloning `:url` to `:path`', [
      ':url' => $this->repository_url,
      ':path' => $this->repository_path,
    ]));

    return $this->runCommand($this->buildGitCloneCommand());
  }

  protected function gitCheckout() {
    if ($this->referenceIsACommit()) {
      $this->notice(dt('Fetching full Git history and checking out commit `:ref`', [
        ':ref' => $this->reference,
      ]));

      return $this->runCommand($this->buildGitCheckoutCommand());
    }
    return TRUE;
  }

  protected function buildGitCloneCommand() {
    $command = 'git clone --recursive --depth 1 --no-progress --quiet';
    if ($this->referenceIsABranch() || $this->referenceIsATag()) {
      $command .= ' --branch ' . escapeshellarg(trim($this->reference));
    }
    $command .= ' ' . escapeshellarg(trim($this->repository_url));
    $command .= ' ' . escapeshellarg(trim($this->repository_path));
    return $command;
  }

  protected function buildGitCheckoutCommand() {
    $command = 'cd ' . escapeshellarg(trim($this->repository_path));
    $command .= ' && git fetch --unshallow && ';
    $command .= 'git checkout ' . escapeshellarg(trim($this->reference));
    return $command;
  }

  protected function referenceIsABranch() {
    return $this->reference && in_array($this->reference, $this->lsRemote('heads'));
  }

  protected function referenceIsATag() {
    return $this->reference && in_array($this->reference, $this->lsRemote('tags'));
  }

  protected function referenceIsACommit() {
    return $this->reference && !$this->referenceIsABranch() && !$this->referenceIsATag();
  }

  /**
   * The repository's branches ('heads') or tags, read once.
   *
   * A kind the repository has none of, or a repository that cannot be read,
   * is an empty list: in_array() on a missing list is a fatal error on PHP 8.
   * The clone then fails with the repository's own error where it cannot be
   * reached.
   */
  protected function lsRemote($type = FALSE) {
    if (!$this->scanned) {
      $this->scanned = TRUE;
      $this->references = ['heads' => [], 'tags' => []];
      $this->notice(dt('Scanning remote git repository tags and branches.'));
      $debug = drush_get_context('DRUSH_DEBUG');
      drush_set_context('DRUSH_DEBUG', FALSE);
      $read = drush_shell_exec('git ls-remote ' . escapeshellarg(trim($this->repository_url)));
      drush_set_context('DRUSH_DEBUG', $debug);
      if ($read) {
        foreach (drush_shell_exec_output() as $line) {
          $ref = explode('/', $line, 3);
          if (isset($ref[1]) && isset($ref[2])) {
            $this->references[$ref[1]][] = $ref[2];
          }
        }
      }
    }
    if (!$type) {
      return $this->references;
    }
    return isset($this->references[$type]) ? $this->references[$type] : [];
  }

  public function postProvisionDelete() {
    if ($this->removeUnfinishedLeftover('git_repository_path')) {
      return;
    }
    if ($this->repository_path != d()->root) {
      $this->notice(dt('Deleting repo path at: ') . d()->repo_path);
      _provision_recursive_delete($this->repository_path);
    }
    $this->clearBuildMarker();
  }

}
