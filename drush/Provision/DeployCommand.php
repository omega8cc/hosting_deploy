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
 * with the folder, its owner and its mode named.
 */
class Provision_DeployCommand extends Provision_ShellCommand {

  // Seconds a build step may stay silent before it is stopped.
  protected $silence_limit = 300;

  // Seconds a stopped step gets to end by itself before it is killed.
  protected $stop_grace = 10;

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
    return dt('Platform path already exists. Aborting.');
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
    return $this->deployPlatform();
  }

  protected function deployPlatform() {
    $this->notice(dt('Deploying platform.'));
    $path = $this->cleanPath();
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
    if (@file_put_contents($marker, $text) === FALSE) {
      $this->notice(dt('The build marker `:marker` could not be written.', [':marker' => $marker]));
      return FALSE;
    }
    return TRUE;
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
   * build that never finished. That folder and its marker go; whatever else
   * is there stays.
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
   * an error and returns FALSE: a non-zero status, a command that could not
   * be started, and one that stayed silent past the limit, which is stopped
   * first with everything it started.
   */
  protected function execCommand($command) {
    $this->notice("Executing: `$command`");
    $descriptorspec = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];
    $process = proc_open($this->wrapCommand($command), $descriptorspec, $pipes, realpath('./'));
    if (!is_resource($process)) {
      return $this->buildError('PLATFORM_BUILD_NOT_STARTED', dt('The command could not be started: `:command`', [':command' => $command]));
    }
    fclose($pipes[0]);
    $open = [1 => $pipes[1], 2 => $pipes[2]];
    $pending = [1 => '', 2 => ''];
    foreach ($open as $stream) {
      stream_set_blocking($stream, FALSE);
    }
    $last_output = time();
    // The status the command ended with, once it has ended.
    $exitcode = NULL;
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
              $this->notice(substr($pending[$key], 0, $end + 1));
              $pending[$key] = (string) substr($pending[$key], $end + 1);
            }
            // A progress meter that never ends its line is still output.
            if (strlen($pending[$key]) > 65536) {
              $this->notice($pending[$key]);
              $pending[$key] = '';
            }
          }
          elseif (feof($stream)) {
            if ($pending[$key] !== '') {
              $this->notice($pending[$key]);
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
    }
    foreach ($open as $key => $stream) {
      if ($pending[$key] !== '') {
        $this->notice($pending[$key]);
      }
      fclose($stream);
    }
    if ($exitcode === NULL) {
      // Workaround for proc_close()'s propensity to return an error status.
      $status = proc_get_status($process);
      $exit = proc_close($process);
      $return = $status['running'] ? $exit : $status['exitcode'];
    }
    else {
      proc_close($process);
      $return = $exitcode;
    }
    if ($return === 0) {
      return $this->success("Finished running: `$command`");
    }
    return $this->error(dt('An error occured when running command! (returned :exit)', [':exit' => $return]));
  }

  /**
   * Start the command as the leader of a process group of its own.
   *
   * Git and Composer start helpers; stopping the group stops them all, where
   * stopping the shell alone would leave them writing into the folder.
   */
  protected function wrapCommand($command) {
    if (function_exists('posix_kill') && is_executable('/usr/bin/setsid')) {
      return 'exec /usr/bin/setsid /bin/sh -c ' . escapeshellarg($command);
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
