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
    $this->form['platform_composer']['#title'] = 'Deploy a Composer Project';
    $this->form['platform_composer']['#description'] = 'You may deploy this platform from a Composer package.';
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
    $this->setProjectPath();
    $this->setPublishPath();
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

