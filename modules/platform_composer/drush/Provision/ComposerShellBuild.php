<?php
/**
 * @file
 * The Provision_ComposerShellBuild class.
 */

/**
 * A Composer project the account built in its shell, built again from its
 * own composer.json and composer.lock as the account's shell login.
 *
 * The project was made by the shell login (oN.ftp) under the account's
 * static folder and added to the panel by its path. Nothing but that login
 * knows how it was made, so its build runs as that login too, never as the
 * backend user: each step is one fixed verb of /opt/local/bin/boa-ftp-build,
 * reached through the one sudo line BOA writes for the account
 * ("oN ALL=(oN.ftp) NOPASSWD: /opt/local/bin/boa-ftp-build"). The program
 * resolves every folder as the shell login and refuses any other call.
 *
 * Its files are the login's, so no fingerprint vouches for them: the build
 * runs them as that login, with its Unix rights and never the backend
 * user's (outside lshell, so without the Landlock rules a session of that
 * login has). It is made in place beside the
 * source, where the login could make it by hand, with the marker, the step
 * limits, the judge between the resolve and the install and the removal of
 * a failed build of every build of this module. The marker names the login:
 * what is left of a step's group may be the login's alone, which the backend
 * user cannot signal, and that is still the build.
 */
class Provision_ComposerShellBuild extends Provision_ComposerLockBuild {

  // The account's shell login the steps run as.
  protected $login = '';

  // The program the steps run, and sudo, which runs it as the login.
  protected $program = '/opt/local/bin/boa-ftp-build';
  protected $sudo = '/usr/bin/sudo';

  // The account's static folder, which every folder of the build is in.
  protected $static = '';

  // The program's own stop takes up to its poll and its grace: a stopped
  // step gets that long before the backend kills what it can.
  protected $stop_grace = 20;

  /**
   * @param array $recipe
   *   'path', 'source', 'docroot', 'resolve' (array), 'judge' (a callable)
   *   and 'login'; optionally 'program', 'sudo' and 'static'.
   */
  public function __construct($recipe) {
    parent::__construct($recipe);
    foreach (array('login' => 'login', 'program' => 'program', 'sudo' => 'sudo', 'static' => 'static') as $key => $property) {
      if (isset($recipe[$key]) && is_string($recipe[$key]) && $recipe[$key] !== '') {
        $this->{$property} = $recipe[$key];
      }
    }
    $this->static = rtrim($this->static, '/');
    $this->marker_login = $this->login;
    // Built in place: the folder is known before the build, so the marker
    // beside it is too, for the guard of the platform's own tasks.
    $this->stage = $this->path;
  }

  /**
   * Why a Verify or a Delete of the platform at this build's folder must not
   * go ahead now, or ''.
   *
   * A platform added by its path has no build strategy to run its tasks, so
   * its own drush hooks ask here: while the build's group lives (a runner
   * killed from outside leaves the login's program running), and, for a
   * Verify, while the marker says the build never finished.
   */
  public function taskRefusal($what, $unfinished_too) {
    if ($this->refuseBesideLiveBuild($what)) {
      return $this->error_text;
    }
    if ($unfinished_too && $this->buildIsUnfinished()) {
      return dt('A build at `:path` has not finished (`:marker` says so), so the code there is not taken as this platform. Delete this platform, or remove that folder and the marker with the account\'s shell login.', array(
        ':path' => $this->path,
        ':marker' => $this->buildMarker(),
      ));
    }
    return '';
  }

  /**
   * Removes the marker of a build whose folder is gone; TRUE when one was.
   */
  public function forgetGoneBuild() {
    $marker = $this->buildMarker();
    clearstatcache();
    if ($marker && is_file($marker) && !file_exists($this->path) && !is_link($this->path)) {
      @unlink($marker);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Whether the marker beside a folder names a shell login: a build of this
   * class made it.
   */
  public static function markerNamesLogin($folder) {
    $folder = rtrim((string) $folder, '/');
    if ($folder === '' || $folder[0] !== '/') {
      return FALSE;
    }
    $text = self::markerText(dirname($folder) . '/.' . basename($folder) . '.unfinished-build');
    return is_string($text) && (bool) preg_match('/^login=[a-z][a-z0-9_]{0,31}\.ftp$/m', $text);
  }

  protected function announceBuild() {
    $this->notice(dt('Building `:path` again from the composer.json and composer.lock of `:source`, as the account\'s shell login `:login`.', array(
      ':path' => $this->path,
      ':source' => $this->source,
      ':login' => $this->login,
    )));
  }

  protected function buildSteps() {
    return array(
      'copyAsLogin',
      'resolveAsLogin',
      'judgeLock',
      'installAsLogin',
      'checkBuilt',
    );
  }

  /**
   * Whether the recipe names what a build as the shell login needs.
   */
  protected function recipeIsWhole() {
    $path = $this->path;
    $source = $this->source;
    if ($path === '' || $path[0] !== '/' || $source === '' || $source[0] !== '/' || dirname($path) !== dirname($source) || $path === $source || strpos($path . '/', '/../') !== FALSE) {
      return $this->buildError('PLATFORM_LOCK_BUILD_RECIPE', dt('The build names no new folder beside the platform it is built from.'));
    }
    if ($this->docroot !== '' && !preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]*$/', $this->docroot)) {
      return $this->buildError('PLATFORM_LOCK_BUILD_RECIPE', dt('The build names a docroot that is no folder name.'));
    }
    if (!$this->resolve || !is_callable($this->judge)) {
      return $this->buildError('PLATFORM_LOCK_BUILD_RECIPE', dt('The build was not told what to update or how to judge the result.'));
    }
    $m = array();
    if (!preg_match('/^([a-z][a-z0-9_]{0,31})\.ftp$/', $this->login, $m)) {
      return $this->buildError('PLATFORM_SHELL_BUILD_LOGIN', dt('The build names no shell login of the account.'));
    }
    if ($this->static === '') {
      $this->static = '/data/disk/' . $m[1] . '/static';
    }
    if (strpos($path, $this->static . '/') !== 0 || strpos($source, $this->static . '/') !== 0) {
      return $this->buildError('PLATFORM_SHELL_BUILD_FOLDER', dt('A build as the shell login is made in the account\'s static folder `:static` only.', array(':static' => $this->static)));
    }
    clearstatcache();
    if (is_link($source) || !is_dir($source)) {
      return $this->buildError('PLATFORM_LOCK_BUILD_SOURCE', dt('The project folder `:source` is not a plain folder.', array(':source' => $source)));
    }
    return TRUE;
  }

  /**
   * Built in place, where the login could build it by hand: the folder is
   * the login's, made by the program's copy, and no other name is used.
   */
  protected function openStage() {
    $this->stage = $this->path;
    return TRUE;
  }

  protected function closeStage() {
  }

  /**
   * One verb of the program, as the shell login, as a bounded step.
   */
  protected function runAsLogin($args) {
    $command = $this->sudo . ' -n -u ' . escapeshellarg($this->login) . ' ' . escapeshellarg($this->program);
    foreach ($args as $arg) {
      $command .= ' ' . escapeshellarg((string) $arg);
    }
    return $this->execCommand($command);
  }

  /**
   * The project copied beside itself, without what Composer installs, the
   * sites but sites/all, every link, the platform's control file and what
   * BOA's fixes left in sites/all: the program's copy.
   */
  protected function copyAsLogin() {
    $args = array('copy', $this->source, $this->path, ($this->docroot === '') ? '-' : $this->docroot);
    foreach ($this->installedFolders() as $folder) {
      $args[] = $folder;
    }
    $ok = $this->runAsLogin($args);
    // A copy the program refused made nothing: what stands at the name, if
    // anything, is not this build's.
    if ($ok !== TRUE && preg_match('/^boa-ftp-build: refused: /m', implode("\n", $this->tail))) {
      $this->made_path = FALSE;
    }
    return $ok;
  }

  /**
   * What the failed build made, removed by the shell login itself (the
   * program's remove): the backend user never walks a folder the login can
   * rewrite. A link put at the name is only unlinked. The marker goes once
   * the folder has, or at once when the build made nothing.
   */
  protected function removeFailedBuild() {
    $path = $this->cleanPath();
    clearstatcache();
    if ($this->made_path && is_link($path)) {
      @unlink($path);
    }
    elseif ($this->made_path && file_exists($path)) {
      // A stopped step's program may still be ending its own stop: the
      // removal waits a little for that group, so it never races its writes.
      $marker = $this->buildMarker();
      for ($i = 0; $i < 30 && $marker && $this->markerGroupAlive($marker); $i++) {
        sleep(1);
      }
      $this->notice(dt('Removing what the failed build left at `:path`, as the account\'s shell login.', array(':path' => $path)));
      $this->runAsLogin(array('remove', $path));
    }
    clearstatcache();
    if (!$this->made_path || ($path !== '' && !file_exists($path) && !is_link($path))) {
      $this->clearBuildMarker();
    }
  }

  /**
   * A new composer.lock for what was asked, nothing installed: the program's
   * resolve, which lifts Composer's advisory block alone.
   */
  protected function resolveAsLogin() {
    return $this->runAsLogin(array_merge(array('resolve', $this->path), $this->resolve));
  }

  /**
   * The code the new lock names: the program's install, the plugins run and
   * the root composer.json's scripts do not.
   */
  protected function installAsLogin() {
    return $this->runAsLogin(array('install', $this->path));
  }

  /**
   * The folder is still a plain folder holding the two files.
   */
  protected function checkBuilt() {
    clearstatcache();
    if (is_link($this->path) || !is_dir($this->path)) {
      return $this->buildError('PLATFORM_LOCK_BUILD_TAKEN', dt('The folder at `:path` is no longer the build.', array(':path' => $this->path)));
    }
    if (self::fingerprintOf($this->path) === '') {
      return $this->buildError('PLATFORM_LOCK_BUILD_FILES', dt('The built composer.json or composer.lock cannot be read.'));
    }
    return TRUE;
  }

}
