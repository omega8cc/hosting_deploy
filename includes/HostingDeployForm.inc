<?php
/**
 * @file The HostingDeployForm class.
 */

class HostingDeployForm extends HostingForm {

  public function __construct(&$form, &$form_state, &$node = FALSE) {
    parent::__construct($form, $form_state, $node);
    if ($this->node->type == 'platform') {
      $this->platform = new HostingDeployNode($this->node);
    }
  }

  public function alter() {
    $this->makeFieldsetsIntoRadios('field_deployment_strategy', $this->getStrategyLabels());
    $this->addDefaultStrategyOption();
    $this->setStrategy();
    $readonly = isset($this->node->platform_status) &&
      $this->node->platform_status != HOSTING_PLATFORM_QUEUED &&
      !empty($this->node->field_deployment_strategy);
    if ($readonly) {
      $this->makeFieldReadOnly('field_deployment_strategy');
    }
    $this->keepBuildStrategy();
    $this->keepBuildPath();
    $this->addGitHelp();
    $this->addSubmitHandler('hosting_deploy_platform_submit');

    // TODO: Add a validator to ensure required fields for a given strategy are
    // provided.
  }

  /**
   * Keep only the build fields of the strategy a platform is saved with.
   *
   * The form sends every build strategy's fields, shown or not, and the
   * backend runs every build whose fields reach it: a field of another build
   * sent with a save would make the next Verify run that build too. So a
   * save empties, for every build but the platform's own, the fields a
   * request can set. The build folders, which no request sets, are kept: a
   * Delete reads them. A platform that verified is left as it is, its fields
   * locked, and so is one saved before its strategy was stored, whose
   * strategy is read from the fields that are set.
   */
  public function submit() {
    $lang = $this->language();
    if (!isset($this->form_state['values']['field_deployment_strategy'][$lang][0]['value'])) {
      return;
    }
    if (!$this->isANewNode()) {
      if (!isset($this->node->platform_status) || $this->node->platform_status != HOSTING_PLATFORM_QUEUED) {
        return;
      }
      if (empty($this->platform->getFieldValue('field_deployment_strategy'))) {
        return;
      }
    }
    $own = $this->form_state['values']['field_deployment_strategy'][$lang][0]['value'];
    foreach (self::getBuildStrategies() as $name) {
      // Each strategy keeps its fields in a fieldset of its own name.
      if ($name === $own || !isset($this->form[$name])) {
        continue;
      }
      foreach (element_children($this->form[$name]) as $field) {
        $element = $this->form[$name][$field];
        if (!$this->isAField($field) || !isset($this->form_state['values'][$field])) {
          continue;
        }
        if ((isset($element['#access']) && !$element['#access']) || !empty($element['#disabled'])) {
          continue;
        }
        $this->form_state['values'][$field] = [$lang => []];
      }
    }
  }

  /**
   * A build strategy is chosen when a platform is added, never on an edit.
   *
   * A build takes its folder from the Platform Path only when the platform
   * is added. A platform switched to a build on an edit would have no folder:
   * the build tool would get an empty target and make its project in the
   * backend's working directory, and the path would be saved as the docroot
   * alone. One switched away from a build keeps that build's fields, so the
   * old build would still run. So the strategy of a platform a build makes
   * stays as it is, and an existing platform of another kind is not offered
   * a build.
   */
  protected function keepBuildStrategy() {
    if ($this->isANewNode() || !isset($this->form['field_deployment_strategy'][$this->language()]['#options'])) {
      return;
    }
    $builds = self::getBuildStrategies();
    if (in_array($this->determineExistingStrategy(), $builds, TRUE)) {
      $this->makeFieldReadOnly('field_deployment_strategy');
      $this->form['field_deployment_strategy'][$this->language()]['#description'] = t('Chosen when this platform was added. To build it another way, delete this platform and add it again.');
      return;
    }
    foreach ($builds as $name) {
      unset($this->form['field_deployment_strategy'][$this->language()]['#options'][$name]);
    }
  }

  /**
   * The strategies that build a platform at its first Verify.
   */
  static public function getBuildStrategies() {
    $builds = [];
    foreach (self::getStrategies() as $name => $strategy) {
      if (!empty($strategy['builds'])) {
        $builds[] = $name;
      }
    }
    return $builds;
  }

  /**
   * Show the Platform Path of a platform a build makes, and keep it.
   *
   * A build takes its folder from the Platform Path when the platform is
   * added, and every save writes the path back from that folder and the
   * docroot. An edited path would be replaced without a word, or, with no
   * docroot, kept while the build goes into the first folder. As for a
   * makefile platform, the path is shown and sent as it is, and the text
   * beside it says how to build elsewhere.
   */
  protected function keepBuildPath() {
    if ($this->isANewNode() || empty($this->node->publish_path) || !isset($this->form['publish_path']['#type'])) {
      return;
    }
    if ($this->form['publish_path']['#type'] === 'hidden') {
      return;
    }
    $built = FALSE;
    foreach (['field_composer_project_path', 'field_composer_git_path', 'field_git_repository_path'] as $field) {
      if ($this->platform->getFieldValue($field)) {
        $built = TRUE;
      }
    }
    if (!$built) {
      return;
    }
    $path = $this->node->publish_path;
    $this->form['info']['publish_path'] = [
      '#type' => 'item',
      '#title' => t('Platform Path'),
      '#markup' => check_plain($path),
      '#description' => t('Set from the folder the build goes into when this platform was added. To build into another folder, delete this platform and add it again.'),
    ];
    $this->form['publish_path'] = [
      '#type' => 'hidden',
      '#value' => function_exists('hosting_path_normalize') ? hosting_path_normalize($path) : $path,
    ];
  }

  /**
   * Add default option and make it first.
   */
  protected function addDefaultStrategyOption() {
    $default = 'none';
    $options = $this->form['field_deployment_strategy'][$this->language()]['#options'];
    $this->form['field_deployment_strategy'][$this->language()]['#options'] = [$default => $options[$default]] + $options;
  }

  protected function setStrategy() {
    if ($this->isANewNode()) {
      $this->setFormDefaultValue('field_deployment_strategy', 'none');
    }
    else {
      $this->setFormDefaultValue('field_deployment_strategy', $this->determineExistingStrategy());
    }
  }

  protected function determineExistingStrategy() {
    if (!empty($this->platform->getFieldValue('field_deployment_strategy'))) {
      return $this->platform->getFieldValue('field_deployment_strategy');
    }
    else {
      return $this->guessStrategy();
    }
  }

  protected function guessStrategy() {
    foreach ($this->getStrategyRequiredFields() as $strategy => $fields) {
      foreach ($fields as $field) {
        if (!empty($this->platform->getFieldValue($field))) {
          return $strategy;
        }
      }
    }
    return 'none';
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

  static protected function getStrategies() {
    $strategies = module_invoke_all('platform_deploy_strategies');
    drupal_alter('platform_deploy_strategies', $strategies);
    return $strategies;
  }

  static public function getStrategyLabels() {
    foreach (self::getStrategies() as $name => $strategy) {
      $strategies[$name] = $strategy['label'];
    }
    return $strategies;
  }

  public function getStrategyRequiredFields() {
    foreach ($this->getStrategies() as $name => $strategy) {
      $strategies[$name] = $strategy['required_fields'];
    }
    return $strategies;
  }

}

