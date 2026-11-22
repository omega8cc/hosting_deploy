<?php
/**
 * @file The HostingPlatformComposerGitForm class.
 */

class HostingPlatformComposerGitForm extends HostingForm {

  // Fields managed by this module.
  protected $fields = [
    'field_composer_git_docroot',
    'field_composer_git_project_url',
    'field_composer_git_path',
    'field_composer_git_version',
  ];

  public function __construct(&$form, &$form_state, &$node = FALSE) {
    parent::__construct($form, $form_state, $node);
    if ($this->node->type == 'platform') {
      $this->platform = new HostingPlatformComposerGitNode($node);
    }
  }

  public function alter() {
    $this->disableField('field_composer_git_path');
    $this->lockFields();
    $this->moveFieldsIntoFieldset('platform_composer_git', $this->fields);
    $this->form['platform_composer_git']['#title'] = 'Deploy a Composer project from a Git repository';
    $this->form['platform_composer_git']['#description'] = '
<p>Builds this platform from your own Git repository. The first Verify clones the <em>Git URL</em> into the Platform Path, which must be a folder that does not exist yet, checks out the <em>Branch/tag</em> and runs <code>composer install --no-dev</code> there. An empty <em>Branch/tag</em> means <code>master</code>.</p>

<p>Commit <code>composer.lock</code> to the repository and build from a tag: the install then gives the same code every time. Require <code>drush/drush</code> in the repository itself. Where a codebase has none, the first Verify adds it and changes <code>composer.json</code> and <code>composer.lock</code> in the folder it cloned.</p>

<p>A private repository is cloned with the deploy key of this &AElig;gir instance: add the contents of <code>~/static/&lt;instance&gt;.id_ed25519.pub</code> to the repository as a read-only deploy key first.</p>

<p>A build that fails removes what it made and fails the Verify. Correct the fields and run Verify again.</p>

<p>Composer runs the scripts and plugins of the repository and of its packages during the build, as the system user of this instance. Build only from repositories you trust.</p>
    ';
    $this->addSubmitHandler('hosting_platform_composer_git_platform_submit');
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
    return $this->form_state['values']['field_deployment_strategy'][$lang][0]['value'] === 'platform_composer_git';
  }

  protected function setProjectPath() {
    if ($this->isANewNode() && !empty($this->getFormStateValue('field_composer_git_project_url'))) {
      $this->setFormStateValue('field_composer_git_path', $this->getFormStateValue('publish_path'));
    }
  }

  protected function setPublishPath() {
    if (!empty($this->getFormStateValue('field_composer_git_docroot'))) {
      $publish_path = $this->getFormStateValue('field_composer_git_path') . '/' . $this->getFormStateValue('field_composer_git_docroot');
      $this->setFormStateValue('publish_path', $publish_path);
    }
  }

}

