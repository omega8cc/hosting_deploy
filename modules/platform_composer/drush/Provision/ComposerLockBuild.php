<?php
/**
 * @file
 * The Provision_ComposerLockBuild class.
 */

/**
 * A platform built again from another one's own composer.json and
 * composer.lock, updating only what was asked.
 *
 * It runs inside the task that asked for it, never in the new platform's
 * Verify: the recipe and the folder it copies stay in the process that
 * checked them, and the Verify that follows takes the folder as it is. The
 * marker, the step limits and the removal of a failed build are those of
 * every build of this module. The account's logins can write the platform
 * it is copied from and the folder both platforms sit in, where a folder
 * being built can be renamed away and another put in its place: the build
 * is made in a folder under the backend user's home that no login can
 * change, and moved beside the source whole once it is done. Its errors are
 * kept here: the calling task says what happened in its own result.
 */
class Provision_ComposerLockBuild extends Provision_DeployCommand {

  // Read from the recipe, never from the current context.
  protected $context_properties = [];

  // The folder the build makes.
  protected $path = '';

  // The folder the build is made in, closed to every login: the recipe's
  // 'stage', else .lockbuild in the backend user's home.
  protected $stage_root = '';

  // The folder the steps build, moved to $path once they are done.
  protected $stage = '';

  // The lock on the build's name, held while it runs.
  protected $lock = NULL;

  // The new folder's parent as its real path, and each folder from it up
  // to / with its device and inode, read before the build and again before
  // the build is moved there.
  protected $parent = '';
  protected $parents = [];

  // The project folder of the platform it is built from.
  protected $source = '';

  // The docroot's folder inside the project, '' when it is the project.
  protected $docroot = '';

  // The words of the update after `composer update`, each escaped here.
  protected $resolve = [];

  // The fingerprint the source's two files had when the panel built it.
  protected $expected = '';

  // Called with the folder once the new lock is written: '' or why not.
  protected $judge = NULL;

  // The mode the folder gets once the build is done.
  protected $folder_mode = 0;

  // The fingerprint of the built folder's two files.
  protected $files = '';

  // The first error of the build: its code and its words.
  protected $error_code = '';
  protected $error_text = '';

  // The command that runs Composer.
  protected $composer = 'composer';

  /**
   * @param array $recipe
   *   'path', 'source', 'docroot', 'resolve' (array), 'fingerprint',
   *   'judge' (a callable), 'mode' (an integer) and, optionally, 'stage'.
   */
  public function __construct($recipe) {
    parent::__construct();
    foreach (['path' => 'path', 'source' => 'source', 'docroot' => 'docroot', 'fingerprint' => 'expected', 'stage' => 'stage_root'] as $key => $property) {
      if (isset($recipe[$key]) && is_string($recipe[$key])) {
        $this->{$property} = $recipe[$key];
      }
    }
    $this->path = rtrim($this->path, '/');
    $this->source = rtrim($this->source, '/');
    $this->stage_root = ($this->stage_root === '/') ? '/' : rtrim($this->stage_root, '/');
    $this->resolve = (isset($recipe['resolve']) && is_array($recipe['resolve'])) ? array_values($recipe['resolve']) : [];
    $this->judge = isset($recipe['judge']) ? $recipe['judge'] : NULL;
    $this->folder_mode = isset($recipe['mode']) ? (int) $recipe['mode'] : 0;
  }

  /**
   * Builds the folder; TRUE when it is done, else see errorText().
   */
  public function deploy() {
    if ($this->recipeIsWhole() !== TRUE) {
      return FALSE;
    }
    // A folder that is there already is never this build's, and the build
    // is never moved over it.
    clearstatcache();
    if (file_exists($this->path) || is_link($this->path)) {
      return $this->buildError('PLATFORM_LOCK_BUILD_TAKEN', dt('The folder `:path` is there already.', [':path' => $this->path]));
    }
    $done = FALSE;
    try {
      $done = ($this->openStage() === TRUE && $this->deployPlatform() === TRUE);
    }
    finally {
      $this->closeStage();
    }
    return $done;
  }

  /**
   * The fingerprint of the built folder's composer.json and composer.lock.
   */
  public function files() {
    return $this->files;
  }

  public function errorCode() {
    return $this->error_code;
  }

  public function errorText() {
    return $this->error_text;
  }

  // The steps, the marker and the removal of a failed build know the stage
  // alone: nothing reaches the new folder's name until it is moved there.
  protected function buildPath() {
    return $this->stage;
  }

  protected function announceBuild() {
    $this->notice(dt('Building `:path` again from the composer.json and composer.lock of `:source`.', [
      ':path' => $this->path,
      ':source' => $this->source,
    ]));
  }

  protected function buildSteps() {
    return [
      'makeFolder',
      'copyProject',
      'pruneCopy',
      'checkCopy',
      'resolveLock',
      'judgeLock',
      'installLock',
      'recordFiles',
      'openFolder',
      'placeFolder',
    ];
  }

  /**
   * Keeps the first error for the calling task, which words its own result.
   */
  protected function buildError($code, $message) {
    if ($this->error_code === '') {
      $this->error_code = (string) $code;
      $this->error_text = (string) $message;
    }
    return FALSE;
  }

  /**
   * Whether the recipe names what a build needs.
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
    if (!$this->resolve || !preg_match('/^sha256:[0-9a-f]{64}:[0-9a-f]{64}$/', $this->expected) || !is_callable($this->judge)) {
      return $this->buildError('PLATFORM_LOCK_BUILD_RECIPE', dt('The build was not told what to update, what the files it starts from are, or how to judge the result.'));
    }
    clearstatcache();
    if (is_link($source) || !is_dir($source)) {
      return $this->buildError('PLATFORM_LOCK_BUILD_SOURCE', dt('The project folder `:source` is not a plain folder.', [':source' => $source]));
    }
    return TRUE;
  }

  /**
   * The new folder, open to its owner alone while it is built.
   */
  protected function makeFolder() {
    clearstatcache();
    if (file_exists($this->stage) || is_link($this->stage)) {
      return $this->buildError('PLATFORM_LOCK_BUILD_TAKEN', dt('The folder `:path` is there already.', [':path' => $this->stage]));
    }
    if (!@mkdir($this->stage, 0700) || !@chmod($this->stage, 0700)) {
      return $this->buildError('PLATFORM_LOCK_BUILD_FOLDER', dt('The folder `:path` could not be made.', [':path' => $this->stage]));
    }
    return TRUE;
  }

  /**
   * The source's project, without what Composer installs and what belongs
   * to its sites.
   *
   * Two archives made inside the new folder, as tar keeps them: a link as a
   * link, never what it leads to. The first holds the project but vendor,
   * the docroot's sites folder and every folder Composer installed for the
   * source; the second the docroot's sites/all. Composer empties a package's
   * folder before it installs it, so a folder of it that is copied anyway
   * comes out the same.
   */
  protected function copyProject() {
    $sites = $this->sitesFolder();
    $excludes = ['./vendor', './' . $sites];
    foreach ($this->installedFolders() as $folder) {
      $excludes[] = './' . $folder;
    }
    $main = $this->stage . '/.lockbuild-main.tar';
    $all = $this->stage . '/.lockbuild-all.tar';
    $command = 'tar -C ' . escapeshellarg($this->source) . ' --anchored --no-wildcards';
    foreach (array_unique($excludes) as $exclude) {
      $command .= ' --exclude=' . escapeshellarg($exclude);
    }
    $command .= ' -cf ' . escapeshellarg($main) . ' .';
    if ($this->execCommand($command) !== TRUE) {
      return FALSE;
    }
    // The archive's own entry for the project folder would give the new
    // folder the source's mode, and open it before the build is done.
    if ($this->execCommand('tar -C ' . escapeshellarg($this->stage) . ' --no-overwrite-dir -xpf ' . escapeshellarg($main)) !== TRUE) {
      return FALSE;
    }
    @unlink($main);
    clearstatcache();
    $from = $this->source . '/' . $sites;
    if (!is_link($from) && !is_link($from . '/all') && is_dir($from . '/all')) {
      if (!is_dir($this->stage . '/' . $sites) && !@mkdir($this->stage . '/' . $sites, 0755, TRUE)) {
        return $this->buildError('PLATFORM_LOCK_BUILD_FOLDER', dt('The folder `:path` could not be made.', [':path' => $this->stage . '/' . $sites]));
      }
      if ($this->execCommand('tar -C ' . escapeshellarg($from) . ' -cf ' . escapeshellarg($all) . ' ./all') !== TRUE) {
        return FALSE;
      }
      if ($this->execCommand('tar -C ' . escapeshellarg($this->stage . '/' . $sites) . ' -xpf ' . escapeshellarg($all)) !== TRUE) {
        return FALSE;
      }
      @unlink($all);
    }
    return TRUE;
  }

  /**
   * The docroot's sites folder, as a path inside the project.
   */
  protected function sitesFolder() {
    return ($this->docroot === '') ? 'sites' : $this->docroot . '/sites';
  }

  /**
   * The folders inside the project, outside vendor, that Composer installed
   * for the source, as its vendor/composer/installed.json records them.
   *
   * The file can be written by the account's logins: it only says what is
   * left out of the copy, and what is left out Composer installs afresh.
   */
  protected function installedFolders() {
    $file = $this->source . '/vendor/composer/installed.json';
    clearstatcache(TRUE, $file);
    $stat = @lstat($file);
    if ($stat === FALSE || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] > 16777216) {
      return [];
    }
    $data = json_decode((string) @file_get_contents($file), TRUE);
    $list = (is_array($data) && isset($data['packages']) && is_array($data['packages'])) ? $data['packages'] : (is_array($data) ? $data : []);
    $out = [];
    foreach ($list as $package) {
      if (!is_array($package) || !isset($package['install-path']) || !is_string($package['install-path'])) {
        continue;
      }
      $folder = self::projectPath('vendor/composer/' . $package['install-path']);
      if ($folder !== '' && strpos($folder . '/', 'vendor/') !== 0) {
        $out[] = $folder;
      }
    }
    return array_values(array_unique($out));
  }

  /**
   * A path relative to the project with its dots worked out, or '' when it
   * leaves the project or holds what a plain folder name does not.
   */
  public static function projectPath($path) {
    $parts = [];
    foreach (explode('/', (string) $path) as $part) {
      if ($part === '' || $part === '.') {
        continue;
      }
      if ($part === '..') {
        if (!$parts) {
          return '';
        }
        array_pop($parts);
        continue;
      }
      if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]*$/', $part)) {
        return '';
      }
      $parts[] = $part;
    }
    return implode('/', $parts);
  }

  /**
   * Takes out of the copy what is not the new platform's to carry.
   *
   * Every link and special file, wherever it is: a link could lead the
   * install to write outside the new folder, and the nightly makes the links
   * a platform needs. In sites/all, the platform's own active control file
   * (its line is written once the build is kept), the Drush settings the
   * first Verify writes, and the marks of the permission fixes, which would
   * tell the nightly the new platform was fixed already.
   */
  protected function pruneCopy() {
    $all = $this->stage . '/' . $this->sitesFolder() . '/all';
    $left = $this->pruneLinks($this->stage);
    if ($left !== '') {
      return $this->buildError('PLATFORM_LOCK_BUILD_COPY', dt('The copy holds `:what`, which could not be taken out.', [':what' => $left]));
    }
    $drop = [$all . '/modules/boa_platform_control.ini', $all . '/drush'];
    $marks = @glob($all . '/libraries/*-fixed-*.pid');
    foreach (is_array($marks) ? $marks : [] as $mark) {
      $drop[] = $mark;
    }
    clearstatcache();
    foreach ($drop as $item) {
      if (is_dir($item)) {
        _provision_recursive_delete($item);
      }
      elseif (file_exists($item)) {
        @unlink($item);
      }
      clearstatcache();
      if (file_exists($item)) {
        return $this->buildError('PLATFORM_LOCK_BUILD_COPY', dt('The copy holds `:what`, which could not be taken out.', [':what' => $item]));
      }
    }
    return TRUE;
  }

  /**
   * Removes every link and special file below a folder; '' or what stayed.
   */
  protected function pruneLinks($folder) {
    $entries = @scandir($folder);
    if (!is_array($entries)) {
      return $folder;
    }
    foreach ($entries as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $item = $folder . '/' . $entry;
      $stat = @lstat($item);
      if ($stat === FALSE) {
        return $item;
      }
      $type = $stat['mode'] & 0170000;
      if ($type === 0040000) {
        $left = $this->pruneLinks($item);
        if ($left !== '') {
          return $left;
        }
      }
      elseif ($type !== 0100000 && !@unlink($item)) {
        return $item;
      }
    }
    return '';
  }

  /**
   * The copied composer.json and composer.lock must be the ones the panel
   * built the source with.
   */
  protected function checkCopy() {
    $files = self::fingerprintOf($this->stage);
    if ($files === '') {
      return $this->buildError('PLATFORM_LOCK_BUILD_FILES', dt('The composer.json or composer.lock of `:source` cannot be read.', [':source' => $this->source]));
    }
    if ($files !== $this->expected) {
      return $this->buildError('PLATFORM_LOCK_BUILD_CHANGED', dt('The composer.json or composer.lock of `:source` changed since the panel built it.', [':source' => $this->source]));
    }
    return TRUE;
  }

  /**
   * A new composer.lock for what was asked, nothing installed.
   *
   * Composer's advisory block is lifted alone: it also refuses a locked
   * release with an advisory that the update was not asked to move, which
   * would fail every update of modules while the locked core carries one.
   * The task names an advisory the build adds. The malware block stays on.
   * The setting is this step's alone, in its environment, as the terminal
   * width is. No plugin runs: the copy has none installed.
   */
  protected function resolveLock() {
    $words = '';
    foreach ($this->resolve as $word) {
      $words .= ' ' . escapeshellarg((string) $word);
    }
    $block = getenv('COMPOSER_POLICY_ADVISORIES_BLOCK');
    putenv('COMPOSER_POLICY_ADVISORIES_BLOCK=0');
    $ok = $this->execCommand('cd ' . escapeshellarg($this->stage) . ' && ' . $this->composer . ' update' . $words . ' --no-install --no-scripts --no-plugins --no-audit --no-interaction --no-progress');
    putenv(($block === FALSE) ? 'COMPOSER_POLICY_ADVISORIES_BLOCK' : 'COMPOSER_POLICY_ADVISORIES_BLOCK=' . $block);
    return $ok;
  }

  /**
   * The new lock judged against the source's before anything is installed.
   */
  protected function judgeLock() {
    $why = call_user_func($this->judge, $this->stage);
    if (!is_string($why)) {
      return $this->buildError('PLATFORM_LOCK_BUILD_REFUSED', dt('The new composer.lock could not be judged.'));
    }
    if ($why !== '') {
      return $this->buildError('PLATFORM_LOCK_BUILD_REFUSED', $why);
    }
    return TRUE;
  }

  /**
   * The code the new lock names. Composer's plugins run (the scaffold, the
   * installers); the scripts of the root composer.json do not, since they
   * may run files of the copy that the account's logins could write.
   */
  protected function installLock() {
    return $this->execCommand('cd ' . escapeshellarg($this->stage) . ' && ' . $this->composer . ' install --no-dev --no-interaction --no-progress --no-scripts');
  }

  /**
   * The fingerprint of what was built, taken while the folder is closed.
   */
  protected function recordFiles() {
    $this->files = self::fingerprintOf($this->stage);
    if ($this->files === '') {
      return $this->buildError('PLATFORM_LOCK_BUILD_FILES', dt('The built composer.json or composer.lock cannot be read.'));
    }
    return TRUE;
  }

  /**
   * The folder gets the mode of the one it was built from, while the stage
   * still keeps it from every login.
   */
  protected function openFolder() {
    $mode = ($this->folder_mode > 0) ? ($this->folder_mode & 07777) : 02775;
    if (!@chmod($this->stage, $mode)) {
      return $this->buildError('PLATFORM_LOCK_BUILD_FOLDER', dt('The folder `:path` could not be given its mode.', [':path' => $this->stage]));
    }
    return TRUE;
  }

  /**
   * The finished build moved beside the source, whole.
   *
   * The folders from the new name's parent up to / must be the ones read
   * before the build, none of them swapped for a link or another folder
   * meanwhile, or the move could land wherever a login pointed it. The move
   * is made from inside the parent, entered and checked by its inode, with
   * the bare name: a parent swapped after that check is not where the build
   * goes. rename() never follows a link at the new name and fails over a
   * folder that holds anything, so a name taken while the build ran stays
   * as it is and the build is removed as a failed one.
   */
  protected function placeFolder() {
    $name = basename($this->path);
    if ($this->parentChain() !== $this->parents) {
      return $this->buildError('PLATFORM_LOCK_BUILD_TAKEN', dt('A folder above `:path` changed while the build ran.', [':path' => $this->path]));
    }
    $made = @lstat($this->stage);
    $back = getcwd();
    if ($made === FALSE || !@chdir($this->parent)) {
      return $this->buildError('PLATFORM_LOCK_BUILD_FOLDER', dt('The build could not be moved to `:path`.', [':path' => $this->path]));
    }
    try {
      clearstatcache();
      $here = @lstat('.');
      if ($here === FALSE || $here['dev'] !== $this->parents[0][1] || $here['ino'] !== $this->parents[0][2]) {
        return $this->buildError('PLATFORM_LOCK_BUILD_TAKEN', dt('A folder above `:path` changed while the build ran.', [':path' => $this->path]));
      }
      if (file_exists($name) || is_link($name)) {
        return $this->buildError('PLATFORM_LOCK_BUILD_TAKEN', dt('The folder `:path` appeared while the build ran.', [':path' => $this->path]));
      }
      if (!@rename($this->stage, $name)) {
        return $this->buildError('PLATFORM_LOCK_BUILD_FOLDER', dt('The build could not be moved to `:path`.', [':path' => $this->path]));
      }
      clearstatcache();
      $now = @lstat($name);
      if ($now === FALSE || $now['ino'] !== $made['ino'] || $now['dev'] !== $made['dev']) {
        return $this->buildError('PLATFORM_LOCK_BUILD_TAKEN', dt('The folder at `:path` is no longer the build.', [':path' => $this->path]));
      }
    }
    finally {
      if (is_string($back) && $back !== '') {
        @chdir($back);
      }
    }
    // In place: a parent swapped since leaves the platform's path elsewhere.
    if ($this->parentChain() !== $this->parents) {
      return $this->buildError('PLATFORM_LOCK_BUILD_TAKEN', dt('A folder above `:path` changed while the build was moved there.', [':path' => $this->path]));
    }
    return TRUE;
  }

  /**
   * Each folder from the new folder's real parent up to /, as its path,
   * device and inode; FALSE when the parent cannot be resolved.
   */
  protected function parentChain() {
    clearstatcache();
    $folder = realpath(dirname($this->path));
    if (!is_string($folder) || $folder === '' || $folder[0] !== '/') {
      return FALSE;
    }
    $chain = [];
    while (TRUE) {
      $stat = @lstat($folder);
      if ($stat === FALSE || ($stat['mode'] & 0170000) !== 0040000) {
        return FALSE;
      }
      $chain[] = [$folder, $stat['dev'], $stat['ino']];
      if ($folder === '/') {
        return $chain;
      }
      $folder = dirname($folder);
    }
  }

  /**
   * The closed folder the build is made in, and the lock on its name.
   *
   * Every folder from the stage up to / must be one that only the backend
   * user or root can change, the stage itself the backend user's and open
   * to it alone, and the stage must be on the file system of the new
   * folder, so that the finished build is moved there whole. What builds
   * that were cut off left there goes first, each while its name's lock is
   * held, this build's own name among them.
   */
  protected function openStage() {
    // The folder the build goes into is checked as every build's is: the
    // build itself writes only in the stage.
    if ($this->checkBuildFolder($this->path) !== TRUE) {
      return FALSE;
    }
    $chain = $this->parentChain();
    if (!is_array($chain)) {
      return $this->buildError('PLATFORM_LOCK_BUILD_FOLDER', dt('The folder `:folder` cannot be read.', [':folder' => dirname($this->path)]));
    }
    $this->parents = $chain;
    $this->parent = $chain[0][0];
    $root = $this->stageRoot();
    if ($root === '') {
      return $this->buildError('PLATFORM_LOCK_BUILD_STAGE', dt('The backend user\'s home cannot be read, so the build has no closed folder to be made in.'));
    }
    $open = $this->stageOpenAt(dirname($root), FALSE);
    clearstatcache();
    if ($open === '' && !file_exists($root) && !is_link($root)) {
      // The warning itself, caught here: under Drush's own error handler a
      // warning never reaches error_get_last().
      $why = '';
      set_error_handler(function ($number, $text) use (&$why) {
        $why = (string) $text;
        return TRUE;
      });
      $made = mkdir($root, 0700);
      restore_error_handler();
      clearstatcache();
      if (!$made && !file_exists($root) && !is_link($root)) {
        return $this->buildError('PLATFORM_LOCK_BUILD_STAGE', dt('The folder `:root` the build is made in could not be made: :why', [
          ':root' => $root,
          ':why' => ($why !== '') ? $why : 'no reason given',
        ]));
      }
    }
    if ($open === '') {
      $open = $this->stageOpenAt($root, TRUE);
    }
    if ($open !== '') {
      return $this->buildError('PLATFORM_LOCK_BUILD_STAGE', dt('The build is made in `:root`, but `:folder` can be changed by others than the backend user.', [':root' => $root, ':folder' => $open]));
    }
    $there = @stat($root);
    $beside = @stat($this->parent);
    if ($there === FALSE || $beside === FALSE || $there['dev'] !== $beside['dev']) {
      return $this->buildError('PLATFORM_LOCK_BUILD_STAGE', dt('`:root` and `:folder` are not on one file system, so the build could not be moved into place whole.', [':root' => $root, ':folder' => $this->parent]));
    }
    $name = basename($this->path);
    $handle = @fopen($root . '/lock-' . $name, 'c');
    if ($handle === FALSE) {
      return $this->buildError('PLATFORM_LOCK_BUILD_STAGE', dt('The lock `:lock` could not be made.', [':lock' => $root . '/lock-' . $name]));
    }
    $this->lock = $handle;
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
      return $this->buildError('PLATFORM_LOCK_BUILD_TAKEN', dt('Another build of `:path` runs.', [':path' => $this->path]));
    }
    $this->stage_root = $root;
    $this->stage = $root . '/build-' . $name;
    $this->sweepStages();
    clearstatcache();
    if (file_exists($this->stage) || is_link($this->stage)) {
      return $this->buildError('PLATFORM_LOCK_BUILD_STAGE', dt('What an earlier build left at `:stage` could not be removed.', [':stage' => $this->stage]));
    }
    return TRUE;
  }

  /**
   * The recipe's stage, else .lockbuild in the home of the process's user,
   * read by its uid; '' when neither is known.
   */
  protected function stageRoot() {
    if ($this->stage_root !== '') {
      return ($this->stage_root[0] === '/') ? $this->stage_root : '';
    }
    if (!function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
      return '';
    }
    $user = posix_getpwuid(posix_geteuid());
    if (!is_array($user) || !isset($user['dir']) || !is_string($user['dir']) || $user['dir'] === '' || $user['dir'][0] !== '/') {
      return '';
    }
    return rtrim($user['dir'], '/') . '/.lockbuild';
  }

  /**
   * The first folder from $folder up to / that others than the backend user
   * and root can change, or '' when there is none. With $own, $folder must
   * also be the backend user's, open to it alone.
   */
  protected function stageOpenAt($folder, $own) {
    if (!function_exists('posix_geteuid')) {
      return $folder;
    }
    $euid = posix_geteuid();
    $first = TRUE;
    while (TRUE) {
      clearstatcache(TRUE, $folder);
      $stat = @lstat($folder);
      if ($stat === FALSE || ($stat['mode'] & 0170000) !== 0040000 || ($stat['uid'] !== $euid && $stat['uid'] !== 0) || ($stat['mode'] & 0022)) {
        return $folder;
      }
      if ($first && $own && ($stat['uid'] !== $euid || ($stat['mode'] & 0077))) {
        return $folder;
      }
      if ($folder === '/' || $folder === '.' || $folder === '') {
        return '';
      }
      $first = FALSE;
      $folder = dirname($folder);
    }
  }

  /**
   * Removes the stage and marker of every name whose lock no build holds;
   * this build's own name is held by this build.
   */
  protected function sweepStages() {
    $locks = @glob($this->stage_root . '/lock-*');
    foreach (is_array($locks) ? $locks : [] as $file) {
      $name = substr(basename($file), 5);
      $own = ($name === basename($this->path));
      $handle = $own ? $this->lock : @fopen($file, 'c');
      if ($handle === FALSE) {
        continue;
      }
      if (!$own && !@flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        continue;
      }
      $stage = $this->stage_root . '/build-' . $name;
      $this->removeTree($stage);
      clearstatcache();
      if (!file_exists($stage) && !is_link($stage)) {
        @unlink($this->markerFor($stage));
      }
      if (!$own) {
        @flock($handle, LOCK_UN);
        fclose($handle);
      }
    }
  }

  /**
   * Removes what the failed build made: the stage, whatever it holds.
   */
  protected function removeFailedBuild() {
    $path = $this->cleanPath();
    clearstatcache();
    if ($this->made_path && $this->pathIsRemovable($path) && (file_exists($path) || is_link($path))) {
      $this->notice(dt('Removing what the failed build left at `:path`.', [':path' => $path]));
      $this->removeTree($path);
      clearstatcache();
    }
    if ($path !== '' && !file_exists($path) && !is_link($path)) {
      $this->clearBuildMarker();
    }
  }

  /**
   * Removes a folder of the backend user's with all it holds, never through
   * a link; TRUE when nothing is left. Whatever is not a folder is unlinked,
   * FIFOs among them, and each folder is opened to its owner before it is
   * emptied, since the copy keeps the source's modes.
   */
  protected function removeTree($path) {
    clearstatcache(TRUE, $path);
    $stat = @lstat($path);
    if ($stat === FALSE) {
      return TRUE;
    }
    if (($stat['mode'] & 0170000) !== 0040000) {
      return @unlink($path);
    }
    @chmod($path, 0700);
    $entries = @scandir($path);
    foreach (is_array($entries) ? $entries : [] as $entry) {
      if ($entry !== '.' && $entry !== '..') {
        $this->removeTree($path . '/' . $entry);
      }
    }
    return @rmdir($path);
  }

  /**
   * Lets go of the name's lock. The lock file stays for the next build.
   */
  protected function closeStage() {
    if (is_resource($this->lock)) {
      @flock($this->lock, LOCK_UN);
      @fclose($this->lock);
    }
    $this->lock = NULL;
  }

  /**
   * sha256:<composer.json>:<composer.lock> of a project folder, or ''.
   *
   * Each file is read only when it is a plain file, never through a link.
   */
  public static function fingerprintOf($folder) {
    $out = 'sha256';
    foreach (['composer.json', 'composer.lock'] as $name) {
      $file = rtrim((string) $folder, '/') . '/' . $name;
      clearstatcache(TRUE, $file);
      $stat = @lstat($file);
      if ($stat === FALSE || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] > 33554432) {
        return '';
      }
      $hash = @hash_file('sha256', $file);
      if (!is_string($hash) || strlen($hash) !== 64) {
        return '';
      }
      $out .= ':' . $hash;
    }
    return $out;
  }

}
