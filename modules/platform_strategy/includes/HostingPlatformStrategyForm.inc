<?php
/**
 * @file The HostingPlatformStrategyForm class.
 */

class HostingPlatformStrategyForm extends HostingForm {

  public function alter() {
    $strategies = $this->getStrategies();
    $this->makeFieldsetsIntoRadios('field_deployment_strategy', $strategies);
    $this->setDefault();
    $readonly = isset($this->node->platform_status) &&
      $this->node->platform_status != HOSTING_PLATFORM_QUEUED &&
      !empty($this->node->field_deployment_strategy);
    if ($readonly) {
        $this->makeFieldReadOnly('field_deployment_strategy');
    }
    $this->addGitHelp();

    // TODO: Add a validator to clear out values from other strategies' fields?
  }

  /**
   * Set default option and make it first.
   */
  protected function setDefault() {
    $default = 'none';
    if ($this->isANewNode()) {
      $this->form['field_deployment_strategy'][$this->language()]['#default_value'] = $default;
    }
    else {
      $this->form['field_deployment_strategy'][$this->language()]['#default_value'] = $this->node->field_deployment_strategy[$this->language()][0]['value'];
    }
    $options = $this->form['field_deployment_strategy'][$this->language()]['#options'];
    $this->form['field_deployment_strategy'][$this->language()]['#options'] = [$default => $options[$default]] + $options;
  }

  protected function addGitHelp() {
    if (module_exists('hosting_git')) {
      $this->form['git']['#description'] = t('You may deploy and manage this platform from a Git repository. This strategy is appropriate for development scenarios or when each such platform will only host a single site.');
      if (module_exists('hosting_platform_git')) {
        $this->form['git']['#description'] .= t(' It differs from <em>Deploy from Git repository</em> in that, once deployed, platforms can be updated or otherwise changed via Git.');
        $this->form['platform_git']['#description'] .= t(' It differs from <em>Manage with Git</em> in that, once deployed, platforms are treated as immutable.');
      }
    }
  }

  public function getStrategies() {
    $strategies = module_invoke_all('platform_deploy_strategies');
    drupal_alter('platform_deploy_strategies', $strategies);
    return $strategies;
  }

}

