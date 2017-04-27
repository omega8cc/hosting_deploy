<?php
/**
 * @file
 * Drush hook implementations for the Composer install module.
 */

/**
* Implements drush_hook_pre_hosting_task().
*/
function drush_hosting_composer_pre_hosting_task() {
  $task =& drush_get_context('HOSTING_TASK');
  if ($task->ref->type == 'platform' && $task->task_type == 'verify') {
    // Send a flag to the backend if the platform hasn't been built yet.
    $task->options['run_composer_install'] = $task->ref->platform_status == HOSTING_PLATFORM_QUEUED;
  }
}
