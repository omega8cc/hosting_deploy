<?php
/**
 * @file The HostingPlatformComposerForm class.
 */

class HostingPlatformComposerForm extends HostingForm {

  // Fields managed by this module.
  protected $fields = [
    'field_composer_project_docroot',
    'field_composer_project_package',
    'field_composer_project_path',
    'field_composer_project_version',
  ];

  public function __construct(&$form, &$form_state, &$node = FALSE) {
    parent::__construct($form, $form_state, $node);
    if ($this->node->type == 'platform') {
      $this->platform = new HostingPlatformComposerNode($node);
    }
  }

  public function alter() {
    $this->disableField('field_composer_project_path');
    $this->lockFields();
    $this->moveFieldsIntoFieldset('platform_composer', $this->fields);
    $this->form['platform_composer']['#title'] = 'Deploy a Composer project from a Packagist repository';
    $this->form['platform_composer']['#description'] = '
<p>Builds this platform from a package published on <a href="https://packagist.org/">Packagist</a>, the PHP package repository. The first Verify runs <code>composer create-project --no-dev</code> for the <em>Repository name</em> at the <em>Version</em>, into the Platform Path, which must be a folder that does not exist yet.</p>

<p>For Drupal core alone, enter <em>drupal/recommended-project</em> as the Repository name, a constraint such as <em>~11.4.0</em> as the Version and <em>web</em> as the Docroot. A package builds exactly as it was published, so a site that uses contributed modules needs a codebase that lists them: build that one from your own repository with <em>Deploy a Composer project from a Git repository</em>.</p>

<p>Where the package brings no Drush, the first Verify adds it. A build that fails removes what it made and fails the Verify. Correct the fields and run Verify again.</p>

<p>Composer runs the scripts and plugins of the package and of its dependencies during the build, as the system user of this instance. Build only from packages you trust.</p>
    ';
    $this->addSubmitHandler('hosting_platform_composer_platform_submit');
  }

  protected function lockFields() {
    if (isset($this->node->platform_status) && $this->node->platform_status != HOSTING_PLATFORM_QUEUED) {
      foreach ($this->fields as $field) {
        $this->makeFieldReadOnly($field);
      }
    }
  }

  public function submit() {
    if (!$this->savedWithThisStrategy()) {
      return;
    }
    $this->setProjectPath();
    $this->setPublishPath();
  }

  /**
   * Whether the platform is saved with this strategy.
   *
   * Every strategy's handler runs on every platform save, and its fields
   * are submitted, hidden or not, whatever the strategy: only this
   * strategy's own save sets the build folder and the Platform Path from
   * them. Where the form carries no strategy, the handler runs as it did.
   */
  protected function savedWithThisStrategy() {
    $lang = $this->language();
    if (!isset($this->form_state['values']['field_deployment_strategy'][$lang][0]['value'])) {
      return TRUE;
    }
    return $this->form_state['values']['field_deployment_strategy'][$lang][0]['value'] === 'platform_composer';
  }

  protected function setProjectPath() {
    if ($this->isANewNode() && !empty($this->getFormStateValue('field_composer_project_package'))) {
      $this->setFormStateValue('field_composer_project_path', $this->getFormStateValue('publish_path'));
    }
  }

  protected function setPublishPath() {
    if (!empty($this->getFormStateValue('field_composer_project_docroot'))) {
      $publish_path = $this->getFormStateValue('field_composer_project_path') . '/' . $this->getFormStateValue('field_composer_project_docroot');
      $this->setFormStateValue('publish_path', $publish_path);
    }
  }

}

