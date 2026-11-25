<?php
/**
 * @file
 * The Provision_DeployCommand class.
 */

/**
 * What the platform builds of this module share.
 *
 * A build runs its steps in order and stops at the first one that fails. It
 * then removes the folder it made in this run, and only that folder, so the
 * next Verify builds again instead of taking the leftover for the platform.
 * A build that was cut off before it could do so leaves a marker file beside
 * its folder, and a later Verify refuses that folder by name. A build whose
 * folder the backend user cannot write is refused before its first step,
 * with the folder, its owner and its mode named, and so is a build with no
 * full path. A build whose code needs a newer PHP than the Verify runs on is
 * kept, and the Verify stops with that PHP named. Each step is stopped, with
 * everything it started, when it stays silent past one limit or runs past
 * another; the marker names the running step's process group, so a Delete
 * never removes a folder a build still writes into.
 */
class Provision_DeployCommand extends Provision_ShellCommand {

  // Seconds a build step may stay silent before it is stopped.
  protected $silence_limit = 300;

  // Seconds a stopped step gets to end by itself before it is killed.
  protected $stop_grace = 10;

  // Seconds one build step may run in all, output or not, before it is
  // stopped: the instance runs one task at a time, so a build that never
  // ends would hold every other task of the account.
  protected $step_limit = 2700;

  // What the marker says above the line naming the running step's group.
  protected $marker_text = '';

  // The last lines of the running step's output that can say why it failed.
  protected $tail = [];

  // Whether this run found no folder at the build path and so made its own.
  protected $made_path = FALSE;

  /**
   * The folder the build makes. Each strategy names its own.
   */
  protected function buildPath() {
    return FALSE;
  }

  /**
   * The methods that make up the build, in order. Each returns TRUE or fails.
   */
  protected function buildSteps() {
    return [];
  }

  /**
   * What to say when the folder is already there.
   */
  protected function pathExistsMessage() {
    return dt('Platform path already exists: nothing is built, the code there is used.');
  }

  /**
   * Said once, before the first step.
   */
  protected function announceBuild() {
  }

  /**
   * A look at a folder that is already there. Strategies may warn here.
   */
  protected function checkExistingBuild() {
  }

  public function validateProvisionVerify() {
    $path = $this->cleanPath();
    if ($this->refuseBesideLiveBuild(dt('Verify'))) {
      return FALSE;
    }
    if ($this->pathExists($path)) {
      if ($this->buildIsUnfinished()) {
        return $this->buildError('PLATFORM_BUILD_UNFINISHED', dt('A build at `:path` has not finished (`:marker` says so), so the code there is not taken as this platform. Delete this platform and add it again, or remove that folder and run Verify again. If the folder is complete and your own, remove the marker file instead.', [
          ':path' => $path,
          ':marker' => $this->buildMarker(),
        ]));
      }
      $this->notice($this->pathExistsMessage());
      $this->checkExistingBuild();
      return TRUE;
    }
    if ($this->deployPlatform() !== TRUE) {
      return FALSE;
    }
    return $this->checkBuildPhp($path);
  }

  /**
   * Stop a Verify whose PHP cannot run the code it has just built.
   *
   * The shell wrapper picks a task's PHP for the platform's code when the
   * task starts, and refuses code that cannot run on it. The Verify that
   * builds the platform starts before that code is there, and loading it on
   * an older PHP throws in Composer's platform check. Composer records the
   * code's lowest PHP in that check, so it is read here, as the wrapper reads
   * it: the build is kept, and the task says which PHP the code needs and
   * where to name it.
   */
  protected function checkBuildPhp($path) {
    $file = $path . '/vendor/composer/platform_check.php';
    if (!is_file($file) || !is_readable($file)) {
      return TRUE;
    }
    $text = @file_get_contents($file, FALSE, NULL, 0, 16384);
    if (!is_string($text) || !preg_match('/PHP_VERSION_ID\s*>=\s*([0-9]{5})/', $text, $m)) {
      return TRUE;
    }
    $floor = (int) $m[1];
    if (PHP_VERSION_ID >= $floor) {
      return TRUE;
    }
    return $this->buildError('PLATFORM_BUILD_PHP_TOO_OLD', dt('The build at `:path` is done and kept, but this Verify runs PHP :php and the code needs PHP :need or newer (vendor/composer/platform_check.php). Name PHP :need or newer for this platform in ~/static/control/cli-per-platform.info, then run Verify again. BOA adds such a line by itself within a few minutes where an installed PHP can run the code.', [
      ':path' => $path,
      ':php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
      ':need' => intval($floor / 10000) . '.' . intval(($floor % 10000) / 100),
    ]));
  }

  protected function deployPlatform() {
    $this->notice(dt('Deploying platform.'));
    $path = $this->cleanPath();
    if ($path === '' || $path[0] !== '/') {
      // A build tool handed an empty or relative target makes its project
      // in the working directory, not in the platform's folder.
      return $this->buildError('PLATFORM_BUILD_NO_PATH', dt('This platform has no full path for its build (`:path`), so nothing is built. Delete this platform and add it again.', [
        ':path' => $path,
      ]));
    }
    if (!$this->checkBuildFolder($path)) {
      return FALSE;
    }
    $this->made_path = ($path !== '' && !file_exists($path) && !is_link($path));
    $this->markBuildStarted();
    $this->announceBuild();
    foreach ($this->buildSteps() as $step) {
      if ($this->{$step}() !== TRUE) {
        if (!drush_get_error()) {
          $this->buildError('PLATFORM_BUILD_FAILED', dt('The build step `:step` failed.', [':step' => $step]));
        }
        $this->removeFailedBuild();
        return FALSE;
      }
    }
    $this->clearBuildMarker();
    return TRUE;
  }

  /**
   * The build path with no blanks around it and no slash at its end.
   */
  protected function cleanPath() {
    return rtrim(trim((string) $this->buildPath()), '/');
  }

  /**
   * Refuse a build into a folder the backend user cannot write.
   *
   * The build runs as the backend user, and its first step makes the build
   * path inside the nearest folder that already stands above it. Where that
   * folder does not let this user write, each build tool stops with a line
   * of its own that names neither the folder nor the cause, so the task says
   * both here, before anything is made. The account's logins make folders
   * group writable; one unpacked from an archive, uploaded with its own
   * modes or made before the logins did so may not be.
   */
  protected function checkBuildFolder($path) {
    if ($path === '' || $path[0] !== '/') {
      return TRUE;
    }
    clearstatcache();
    $folder = dirname($path);
    while ($folder !== '/' && !file_exists($folder) && !is_link($folder)) {
      $folder = dirname($folder);
    }
    if (!is_dir($folder) || is_writable($folder)) {
      return TRUE;
    }
    return $this->buildError('PLATFORM_BUILD_FOLDER_NOT_WRITABLE', $this->folderMessage($path, $folder));
  }

  /**
   * What stops the build in that folder, and what its owner can do.
   */
  protected function folderMessage($path, $folder) {
    $uid = @fileowner($folder);
    $gid = @filegroup($folder);
    $perms = @fileperms($folder);
    $args = [
      ':path' => $path,
      ':folder' => $folder,
      ':owner' => $this->idName($uid, FALSE),
      ':group' => $this->idName($gid, TRUE),
      ':mode' => ($perms === FALSE) ? '?' : substr(sprintf('%o', $perms), -4),
    ];
    if (!function_exists('posix_geteuid')) {
      $text = 'The build cannot make `:path`: the backend user that runs it cannot write in `:folder`, which belongs to `:owner`, group `:group`, mode :mode.';
      $text .= ' Its owner can give it group write with `chmod g+w :folder`, then run Verify again.';
      return dt($text, $args);
    }
    $euid = posix_geteuid();
    $groups = posix_getgroups();
    if (!is_array($groups)) {
      $groups = [];
    }
    $groups[] = posix_getegid();
    $args[':user'] = $this->idName($euid, FALSE);
    $text = 'The build cannot make `:path`: the backend user `:user` that runs it cannot write in `:folder`, which belongs to `:owner`, group `:group`, mode :mode.';
    if ($uid !== 0 && $uid !== $euid && in_array($gid, $groups, TRUE)) {
      // A login's folder in the backend user's group: group write is all
      // it lacks, and its owner can give it.
      $text .= ' Log in as `:owner` and give it group write with `chmod g+w :folder`, then run Verify again.';
    }
    else {
      // Root's folder, the backend user's own, or one in a group the backend
      // user is not in: no login of the account can open it to the build.
      $text .= ' Delete this platform and add it again with a Platform Path in a folder your account\'s login has made: such a folder is group writable.';
    }
    return dt($text, $args);
  }

  /**
   * A user or group name, or its number where the system has no name for it.
   */
  protected function idName($id, $group) {
    if (!is_int($id)) {
      return '?';
    }
    $row = FALSE;
    if ($group && function_exists('posix_getgrgid')) {
      $row = posix_getgrgid($id);
    }
    elseif (!$group && function_exists('posix_getpwuid')) {
      $row = posix_getpwuid($id);
    }
    if (is_array($row) && isset($row['name']) && $row['name'] !== '') {
      return $row['name'];
    }
    return (string) $id;
  }

  /**
   * The file that says a build of this folder started and did not finish.
   *
   * It sits beside the folder, not in it: a build tool wants its target
   * missing or empty, and the folder is the owner's code afterwards.
   */
  protected function buildMarker() {
    return $this->markerFor($this->cleanPath());
  }

  protected function markerFor($path) {
    if ($path === '' || $path[0] !== '/' || basename($path) === '') {
      return FALSE;
    }
    return dirname($path) . '/.' . basename($path) . '.unfinished-build';
  }

  protected function buildIsUnfinished() {
    $marker = $this->buildMarker();
    return $marker && is_file($marker);
  }

  protected function markBuildStarted() {
    $marker = $this->buildMarker();
    if (!$marker) {
      return FALSE;
    }
    // On an account's first build the folder that will hold it may be new
    // too. The build tool would make it; the marker has to be there first.
    if (!is_dir(dirname($marker))) {
      @mkdir(dirname($marker), 0777, TRUE);
    }
    $text = 'A platform build into ' . $this->cleanPath() . ' started on ' . date('Y-m-d H:i:s') . ' (process ' . getmypid() . ") and has not finished.\n";
    $text .= "The build removes this file when it ends. While it is here, Verify does not take that folder as the platform.\n";
    $this->marker_text = $text;
    if (@file_put_contents($marker, $text) === FALSE) {
      $this->notice(dt('The build marker `:marker` could not be written.', [':marker' => $marker]));
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Name the running step's process group in the marker.
   *
   * The steps run in a group of their own, which outlives a Verify killed
   * from outside: the group says whether anything still writes into the
   * folder. Only a marker this run wrote is rewritten.
   */
  protected function markStepGroup($pgid) {
    $marker = $this->buildMarker();
    if (!$marker || $this->marker_text === '' || (int) $pgid < 2 || !is_file($marker)) {
      return;
    }
    // The boot and the leader's start time tie the number to this group: a
    // number can be given to another process once the group has ended.
    $text = $this->marker_text . 'pgid=' . (int) $pgid . "\n";
    $boot = $this->bootId();
    if ($boot !== '') {
      $text .= 'boot=' . $boot . "\n";
    }
    $start = $this->processStart((int) $pgid);
    if ($start !== '') {
      $text .= 'start=' . $start . "\n";
    }
    @file_put_contents($marker, $text);
  }

  /**
   * This boot's id, or '' where the system does not say.
   */
  protected function bootId() {
    $id = @file_get_contents('/proc/sys/kernel/random/boot_id', FALSE, NULL, 0, 64);
    return (is_string($id) && preg_match('/^[0-9a-f-]{36}$/', trim($id))) ? trim($id) : '';
  }

  /**
   * When a process started, in clock ticks since boot, or '' when unknown.
   */
  protected function processStart($pid) {
    $stat = @file_get_contents('/proc/' . (int) $pid . '/stat', FALSE, NULL, 0, 4096);
    if (!is_string($stat) || ($end = strrpos($stat, ')')) === FALSE) {
      return '';
    }
    // The fields after the name; the start time is the stat's field 22.
    $rest = explode(' ', trim(substr($stat, $end + 2)));
    return (isset($rest[19]) && ctype_digit($rest[19])) ? $rest[19] : '';
  }

  /**
   * Refuse a Verify while an earlier build of this folder still runs.
   *
   * A Verify killed from outside leaves its step running in a group of its
   * own: a new build into the same folder would work beside it, and the old
   * step's clean-up could remove the new build.
   */
  protected function refuseBesideLiveBuild($what) {
    $marker = $this->buildMarker();
    $pgid = $this->markerGroupAlive($marker);
    if (!$pgid) {
      return FALSE;
    }
    $text = 'A build process (group :pgid) still writes into `:path`, so this :what changes nothing. Run it again once that process has ended';
    // Only a group whose leader still runs is bounded by it: what a step
    // left running after its own command ended has no limit.
    $text .= $this->markerLeaderRuns($marker, $pgid) ? '; it ends within :minutes minutes of its start.' : '. The command that started it has ended, so nothing here ends what is left of that group: the account\'s system user can, with `kill -- -:pgid`.';
    $this->buildError('PLATFORM_BUILD_STILL_RUNNING', dt($text, [
      ':pgid' => $pgid,
      ':path' => $this->cleanPath(),
      ':what' => $what,
      ':minutes' => (int) ceil(($this->step_limit + 70) / 60),
    ]));
    return TRUE;
  }

  /**
   * Whether the leader a marker names, the step's own bounded command, runs.
   */
  protected function markerLeaderRuns($marker, $pgid) {
    $text = @file_get_contents($marker, FALSE, NULL, 0, 4096);
    $now = $this->processStart($pgid);
    if ($now === '') {
      return FALSE;
    }
    return !is_string($text) || !preg_match('/^start=([0-9]+)$/m', $text, $s) || $s[1] === $now;
  }

  /**
   * Refuse a Delete while a build of this platform's folder still runs.
   *
   * Refused here, before the backend removes anything, the platform keeps
   * its record and its alias: the same Delete, run again once the build has
   * ended, still knows the folder and removes it. Refused later, the alias
   * would be gone and the folder left behind for good.
   */
  public function validateProvisionDelete() {
    return $this->refuseBesideLiveBuild(dt('Delete')) ? FALSE : TRUE;
  }

  /**
   * The process group a marker names when any of it still runs, or 0.
   */
  protected function markerGroupAlive($marker) {
    if (!$marker || !is_file($marker) || !function_exists('posix_kill')) {
      return 0;
    }
    $text = @file_get_contents($marker, FALSE, NULL, 0, 4096);
    if (!is_string($text) || !preg_match('/^pgid=([0-9]+)$/m', $text, $m) || (int) $m[1] < 2) {
      return 0;
    }
    $pgid = (int) $m[1];
    // Another boot, or a leader that started at another time: the number
    // now names another group.
    if (preg_match('/^boot=(\S+)$/m', $text, $b) && $this->bootId() !== '' && $b[1] !== $this->bootId()) {
      return 0;
    }
    if (preg_match('/^start=([0-9]+)$/m', $text, $s)) {
      $now = $this->processStart($pgid);
      if ($now !== '' && $now !== $s[1]) {
        return 0;
      }
    }
    // A build runs as this same user: a group it cannot signal (EPERM) is
    // never the build's, and does not keep anything from going ahead.
    return posix_kill(-$pgid, 0) ? $pgid : 0;
  }

  protected function clearBuildMarker() {
    $marker = $this->buildMarker();
    if ($marker && is_file($marker)) {
      @unlink($marker);
    }
  }

  /**
   * Remove the folder this run made, and nothing else.
   *
   * The marker goes only when the folder is gone: where the removal fails,
   * it stays and the next Verify says why it will not use what is there.
   */
  protected function removeFailedBuild() {
    $path = $this->cleanPath();
    if ($this->made_path && $this->pathIsRemovable($path) && (file_exists($path) || is_link($path))) {
      $this->notice(dt('Removing what the failed build left at `:path`.', [':path' => $path]));
      _provision_recursive_delete($path);
      clearstatcache();
    }
    if ($path !== '' && !file_exists($path) && !is_link($path)) {
      $this->clearBuildMarker();
    }
  }

  /**
   * After the Delete of a platform with no codebase: this build's own leftover.
   *
   * The backend removes nothing on disk for such a platform and keeps the
   * build path from the usual removal, because nothing verified what stands
   * there: a mistyped Platform Path can name a folder that holds anything.
   * One thing is known to be this build's, the folder beside the marker of a
   * build that never finished. That folder and its marker go, unless the
   * build's process group still runs (the Delete then fails and can run
   * again); whatever else is there stays.
   *
   * @param string $property
   *   The context property that holds this strategy's build path.
   *
   * @return bool
   *   TRUE when the backend flagged such a Delete, whatever was done here.
   */
  protected function removeUnfinishedLeftover($property) {
    $kept = drush_get_context('PROVISION_NO_CODEBASE_DEPLOY_PATHS', NULL);
    if (!is_array($kept)) {
      return FALSE;
    }
    if (empty($kept[$property]) || !is_string($kept[$property])) {
      return TRUE;
    }
    $path = rtrim(trim($kept[$property]), '/');
    $marker = $this->markerFor($path);
    if ($marker && is_file($marker) && ($pgid = $this->markerGroupAlive($marker))) {
      // A failed Delete keeps the platform's record, so the same Delete can
      // run again; it removes the folder once the build has ended.
      $this->buildError('PLATFORM_BUILD_STILL_RUNNING', dt('A build process (group :pgid) still writes into `:path`, so nothing there was removed. Run this Delete again once that process has ended.', [
        ':path' => $path,
        ':pgid' => $pgid,
      ]));
      return TRUE;
    }
    if ($marker && is_file($marker)) {
      if ($this->pathIsRemovable($path) && (file_exists($path) || is_link($path))) {
        $this->notice(dt('Removing the folder an unfinished build left at `:path`.', [':path' => $path]));
        _provision_recursive_delete($path);
        clearstatcache();
      }
      if (!file_exists($path) && !is_link($path)) {
        @unlink($marker);
      }
    }
    elseif (file_exists($path) || is_link($path)) {
      $this->notice(dt('The folder at `:path` is left as it is: nothing says a build of this platform made it.', [':path' => $path]));
    }
    return TRUE;
  }

  /**
   * A full path, at least three folders deep, with no way back up in it.
   */
  protected function pathIsRemovable($path) {
    if ($path === '' || $path[0] !== '/') {
      return FALSE;
    }
    $parts = explode('/', trim($path, '/'));
    if (count($parts) < 3) {
      return FALSE;
    }
    foreach ($parts as $part) {
      if ($part === '' || $part === '.' || $part === '..') {
        return FALSE;
      }
    }
    return TRUE;
  }

  protected function buildError($code, $message) {
    return drush_set_error($code, $message);
  }

  /**
   * Warn when a folder that is already there cannot hold the named version.
   *
   * A build that failed at its checkout used to leave the repository's
   * default branch behind, and a second Verify took it for the platform. Such
   * a platform keeps verifying; its owner is told what its code is not.
   */
  protected function warnOnUnknownReference($path, $reference) {
    $path = rtrim(trim((string) $path), '/');
    $reference = trim((string) $reference);
    if ($path === '' || $reference === '' || !is_dir($path . '/.git')) {
      return;
    }
    foreach ([$reference, 'origin/' . $reference] as $name) {
      $command = 'git -C ' . escapeshellarg($path) . ' rev-parse --verify --quiet ' . escapeshellarg($name . '^{commit}');
      if (drush_shell_exec($command)) {
        return;
      }
    }
    $this->warning(dt('The code at `:path` is not `:reference`: its repository has no branch, tag or commit of that name, so an earlier build left another version there. Delete this platform and add it again with a name the repository has.', [
      ':path' => $path,
      ':reference' => $reference,
    ]));
  }

  /**
   * Run a command in a subprocess, stream its output, and say how it ended.
   *
   * Returns TRUE when the command ended with status 0. Every other end sets
   * an error and returns FALSE: a non-zero status, with the last lines of
   * output that can say why; a command that could not be started; and one
   * that stayed silent past the silence limit or ran past the step limit,
   * which is stopped first with everything it started.
   */
  protected function execCommand($command) {
    $this->notice("Executing: `$command`");
    $descriptorspec = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];
    $grouped = (function_exists('posix_kill') && is_executable('/usr/bin/setsid'));
    $process = proc_open($this->wrapCommand($command), $descriptorspec, $pipes, realpath('./'));
    if (!is_resource($process)) {
      return $this->buildError('PLATFORM_BUILD_NOT_STARTED', dt('The command could not be started: `:command`', [':command' => $command]));
    }
    $started = NULL;
    if ($grouped) {
      // setsid makes the started process the leader of the step's group.
      // A step that has already ended leaves no group to name.
      $started = proc_get_status($process);
      if (!empty($started['pid']) && !empty($started['running'])) {
        $this->markStepGroup($started['pid']);
      }
    }
    $deadline = time() + $this->step_limit;
    $this->tail = [];
    fclose($pipes[0]);
    $open = [1 => $pipes[1], 2 => $pipes[2]];
    $pending = [1 => '', 2 => ''];
    foreach ($open as $stream) {
      stream_set_blocking($stream, FALSE);
    }
    $last_output = time();
    // The status the command ended with, once it has ended. Before PHP 8.3
    // only the first look after the end gives it, so a quick command that
    // ended before the look above keeps the status that look got.
    $exitcode = NULL;
    if (is_array($started) && isset($started['running']) && empty($started['running'])) {
      $exitcode = $started['exitcode'];
    }
    while (!empty($open)) {
      $read = array_values($open);
      $write = NULL;
      $except = NULL;
      $ready = @stream_select($read, $write, $except, ($exitcode === NULL) ? 5 : 1);
      if ($ready === FALSE) {
        break;
      }
      if ($ready > 0) {
        foreach ($open as $key => $stream) {
          if (!in_array($stream, $read, TRUE)) {
            continue;
          }
          $chunk = fread($stream, 8192);
          if ($chunk !== FALSE && $chunk !== '') {
            $last_output = time();
            $pending[$key] .= $chunk;
            while (($end = strpos($pending[$key], "\n")) !== FALSE) {
              $this->outputLine(substr($pending[$key], 0, $end + 1));
              $pending[$key] = (string) substr($pending[$key], $end + 1);
            }
            // A progress meter that never ends its line is still output.
            if (strlen($pending[$key]) > 65536) {
              $this->outputLine($pending[$key]);
              $pending[$key] = '';
            }
          }
          elseif (feof($stream)) {
            if ($pending[$key] !== '') {
              $this->outputLine($pending[$key]);
              $pending[$key] = '';
            }
            fclose($stream);
            unset($open[$key]);
          }
        }
      }
      if ($exitcode === NULL) {
        $status = proc_get_status($process);
        if (empty($status['running'])) {
          // Kept from this first look: a later one no longer knows it.
          $exitcode = $status['exitcode'];
          continue;
        }
      }
      elseif ($ready === 0) {
        // The command has ended and its output is read. A helper it left
        // running may hold the pipes open; that is not a silent command.
        break;
      }
      if (!empty($open) && $exitcode === NULL && (time() - $last_output) >= $this->silence_limit) {
        $this->stopProcess($process);
        foreach ($open as $stream) {
          fclose($stream);
        }
        proc_close($process);
        return $this->buildError('PLATFORM_BUILD_NO_OUTPUT', dt('No output for :seconds seconds, so the command was stopped: `:command`', [
          ':seconds' => $this->silence_limit,
          ':command' => $command,
        ]));
      }
      // Output or not, ended or still read: the whole step is bounded.
      if (time() >= $deadline) {
        return $this->stopTooLong($process, $open, $command);
      }
    }
    foreach ($open as $key => $stream) {
      if ($pending[$key] !== '') {
        $this->outputLine($pending[$key]);
      }
      fclose($stream);
    }
    if ($exitcode === NULL) {
      // The pipes closed, or could not be read, while the command still ran:
      // wait for its end within the same limit. proc_get_status() gives the
      // real status once, on the first look after the end, and proc_close()
      // may not, so the status is kept from that look.
      $status = proc_get_status($process);
      while (!empty($status['running']) && time() < $deadline) {
        sleep(1);
        $status = proc_get_status($process);
      }
      if (!empty($status['running'])) {
        return $this->stopTooLong($process, [], $command);
      }
      proc_close($process);
      $return = $status['exitcode'];
    }
    else {
      proc_close($process);
      $return = $exitcode;
    }
    if ($return === 0) {
      return $this->success("Finished running: `$command`");
    }
    return $this->buildError('PLATFORM_BUILD_STEP_FAILED', dt('The build command ended with status :exit: `:command`.:why', [
      ':exit' => $return,
      ':command' => $command,
      ':why' => $this->tailText(),
    ]));
  }

  /**
   * Stop a step that ran past the step limit, with everything it started.
   */
  protected function stopTooLong($process, $open, $command) {
    $this->stopProcess($process);
    foreach ($open as $stream) {
      fclose($stream);
    }
    proc_close($process);
    return $this->buildError('PLATFORM_BUILD_TOO_LONG', dt('The command ran longer than :minutes minutes, so it was stopped with everything it started: `:command`', [
      ':minutes' => (int) ceil($this->step_limit / 60),
      ':command' => $command,
    ]));
  }

  /**
   * Log a line of a step's output, and keep it when it can say why it failed.
   *
   * Composer's stack frames and its per-package lines say nothing of the
   * cause, and its messages come framed by blank lines: the last five other
   * lines are kept for the error of a failed step.
   */
  protected function outputLine($text) {
    $this->notice($text);
    $line = trim($text);
    // A command's synopsis, which Composer prints after its error, is no cause.
    if ($line === '' || strpos($line, 'phar://') !== FALSE || preg_match('/^at \//', $line)
      || preg_match('/^[a-z][a-z:-]* \[-/', $line)
      || preg_match('/^- (Downloading|Installing|Upgrading|Downgrading|Locking|Removing|Extracting|Syncing|Cloning|Checking out) /', $line)) {
      return;
    }
    $this->tail[] = (strlen($line) > 300) ? substr($line, 0, 300) . '...' : $line;
    if (count($this->tail) > 5) {
      array_shift($this->tail);
    }
  }

  /**
   * The kept lines of a failed step, as the end of its error.
   */
  protected function tailText() {
    if (empty($this->tail)) {
      return '';
    }
    return ' ' . dt('Its last lines: :lines', [':lines' => implode(' | ', $this->tail)]);
  }

  /**
   * Start the command as the leader of a process group of its own.
   *
   * Git and Composer start helpers; stopping the group stops them all, where
   * stopping the shell alone would leave them writing into the folder.
   */
  protected function wrapCommand($command) {
    if (function_exists('posix_kill') && is_executable('/usr/bin/setsid')) {
      // timeout leads the group and stops it a minute after the step limit:
      // a step whose Verify was killed from outside, and which nothing here
      // stops any more, still ends.
      $bound = is_executable('/usr/bin/timeout') ? '/usr/bin/timeout -k 10 ' . ($this->step_limit + 60) . ' ' : '';
      return 'exec /usr/bin/setsid ' . $bound . '/bin/sh -c ' . escapeshellarg($command);
    }
    return $command;
  }

  /**
   * Stop a running step: ask first, then kill what is still there.
   */
  protected function stopProcess($process) {
    $status = proc_get_status($process);
    $pid = empty($status['pid']) ? 0 : (int) $status['pid'];
    $grouped = ($pid > 1 && function_exists('posix_kill') && is_executable('/usr/bin/setsid'));
    if ($grouped) {
      posix_kill(-$pid, 15);
    }
    else {
      proc_terminate($process, 15);
    }
    $waited = 0;
    while ($waited < $this->stop_grace) {
      $status = proc_get_status($process);
      if (empty($status['running'])) {
        break;
      }
      sleep(1);
      $waited++;
    }
    if ($grouped) {
      // Whatever ignored the request, the leader gone or not.
      posix_kill(-$pid, 9);
    }
    else {
      $status = proc_get_status($process);
      if (!empty($status['running'])) {
        proc_terminate($process, 9);
      }
    }
  }

}
