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
    $this->form['platform_composer_git']['#title'] = 'Deploy a Composer Project (from source)';
    $this->form['platform_composer_git']['#description'] = 'You may deploy this platform from a Composer project URL.';
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
    $this->setProjectPath();
    $this->setPublishPath();
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

