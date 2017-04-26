<?php

class Provision_ShellCommand {

  // The prefix used for properties in Aegir contexts.
  protected $context_prefix = '';

  // List of properties to load from the Aegir context.
  protected $context_properties = [];

  /**
   * Initialize properties from the platform context.
   */
  public function initialize() {
    foreach ($this->context_properties as $property) {
      $this->setProperty($property);
    }
    if (drush_get_error() == DRUSH_FRAMEWORK_ERROR) {
      return FALSE;
    }
    return TRUE;
  }

  protected function setProperty($property) {
    $context_property_name = $this->context_prefix . $property;
    $context_property = d()->$context_property_name;
    if (is_null($context_property)) {
      return $this->notice(dt('Skipping unset context property: ') . $context_property_name);
    }
    $this->$property = $context_property;
  }

  protected function error($message) {
    return drush_set_error('PLATFORM_GIT_CLONE_FAILED', $message);
  }

  protected function abort($message) {
    return drush_user_abort($message);
  }

  protected function log($message, $type) {
    drush_log($message, $type);
  }

  protected function notice($message) {
    $this->log($message, 'notice');
  }

  protected function warning($message) {
    $this->log($message, 'warning');
  }

  protected function success($message) {
    $this->log($message, 'success');
  }

  protected function pathExists($path) {
    return provision_file()->exists($path)->status();
  }

}
