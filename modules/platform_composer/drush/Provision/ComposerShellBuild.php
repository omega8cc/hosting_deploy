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

  // The removal after a Delete that left the disk alone says a failure in
  // the Delete's log, as a warning: no task words it as a build's result,
  // and the folder and its marker then stay for the shell login to remove.
  protected $failure_is_warning = FALSE;

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
   * After a platform Delete that left the disk alone: the folder an
   * unfinished build as the shell login left, removed by that login.
   *
   * Provision deletes nothing on disk for a platform whose root holds no
   * codebase (a build cut off before its install leaves no vendor/), since
   * nothing verified what stands there, and leaves the leftover of an
   * unfinished build to the hook that knows it. This one knows the folder
   * beside a marker naming the shell login of the account whose static
   * folder holds it: the program's remove takes that folder as the login,
   * and only when the program's own copy made and recorded it; never while
   * the build's group still runs. The marker goes once the folder has, and
   * whatever else is there stays.
   *
   * @return bool
   *   TRUE when the backend flagged such a Delete, whatever was done here.
   */
  public function removeLeftoverAfterDelete() {
    // Drush gives an unset context as an empty array: only a default it
    // keeps tells a Delete with no codebase from any other.
    if (!is_array(drush_get_context('PROVISION_NO_CODEBASE_DEPLOY_PATHS', FALSE))) {
      return FALSE;
    }
    $path = $this->cleanPath();
    $marker = $this->buildMarker();
    clearstatcache();
    if ($path === '' || !$marker || !is_file($marker) || is_link($path) || !is_dir($path)) {
      return TRUE;
    }
    $login = $this->markerLogin($marker);
    $static = $this->static;
    if ($static === '' && $login !== '') {
      $static = '/data/disk/' . substr($login, 0, -4) . '/static';
    }
    if ($login === '' || strpos($path, $static . '/') !== 0) {
      $this->notice(dt('The folder at `:path` is left as it is: its marker names no shell login of the account it is in.', array(':path' => $path)));
      return TRUE;
    }
    if ($this->markerGroupAlive($marker)) {
      $this->warning(dt('The folder at `:path` is left as it is: a build process still writes into it.', array(':path' => $path)));
      return TRUE;
    }
    // As the build's plan asks: the backend user is that account's own, and
    // the program is root's, as BOA installs it, and no one else's to change.
    if ($this->backendUser() . '.ftp' !== $login) {
      $this->notice(dt('The folder at `:path` is left as it is: its marker names the shell login of another account.', array(':path' => $path)));
      return TRUE;
    }
    clearstatcache(TRUE, $this->program);
    $stat = @lstat($this->program);
    if ($stat === FALSE || ($stat['mode'] & 0170000) !== 0100000 || $stat['uid'] !== 0 || ($stat['mode'] & 0022) || !($stat['mode'] & 0001)) {
      $this->warning(dt('The folder at `:path` is left as it is: the shell login\'s build program `:program` is not on this server as BOA installs it.', array(':path' => $path, ':program' => $this->program)));
      return TRUE;
    }
    $this->login = $login;
    $this->marker_login = $login;
    $this->notice(dt('Removing the folder an unfinished build left at `:path`, as the account\'s shell login.', array(':path' => $path)));
    $this->failure_is_warning = TRUE;
    $this->removeAsLogin($path);
    $this->failure_is_warning = FALSE;
    clearstatcache();
    if (!file_exists($path) && !is_link($path)) {
      $this->clearBuildMarker();
    }
    return TRUE;
  }

  /**
   * The name of the process's own user, the account's backend user.
   */
  protected function backendUser() {
    $me = (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) ? posix_getpwuid(posix_geteuid()) : FALSE;
    return (is_array($me) && isset($me['name']) && is_string($me['name'])) ? $me['name'] : '';
  }

  /**
   * The program's remove of a folder, as the login. It removes a large tree
   * without a word and bounds that by its own step limit, so for this one
   * call the backend's silence limit is the step limit too.
   */
  protected function removeAsLogin($path) {
    $silence = $this->silence_limit;
    $this->silence_limit = max($this->silence_limit, $this->step_limit);
    $ok = $this->runAsLogin(array('remove', $path));
    $this->silence_limit = $silence;
    return $ok;
  }

  protected function buildError($code, $message) {
    if ($this->failure_is_warning) {
      $this->warning($message);
      return FALSE;
    }
    return parent::buildError($code, $message);
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
      $this->removeAsLogin($path);
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
