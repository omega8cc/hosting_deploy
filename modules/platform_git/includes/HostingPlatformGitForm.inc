<?php
/**
 * @file The HostingPlatformGitForm class.
 */

class HostingPlatformGitForm extends HostingForm {

  // Fields managed by this module.
  protected $fields = [
    'field_git_repository_url',
    'field_git_repository_path',
    'field_git_reference',
    'field_git_docroot',
  ];

  public function __construct(&$form, &$form_state, &$node = FALSE) {
    parent::__construct($form, $form_state, $node);
    if ($this->node->type == 'platform') {
      $this->platform = new HostingPlatformGitNode($node);
    }
  }

  public function alter() {
    $this->moveFieldsIntoFieldset('platform_git', $this->fields);
    $this->form['platform_git']['#title'] = 'Deploy from Git';
    $this->form['platform_git']['#description'] = 'You may deploy this platform from a Git repository. This strategy is appropriate for hosting scenarios where multiple sites will share common platforms.';
  }

  public function nodeSubmit() {
    if ($this->isANewNode() && !empty($this->getFormStateValue('field_git_repository_url'))) {
      $this->setFormStateValue('field_git_repository_path', $this->node->publish_path);
    #  $this->platform->setFieldValue('field_git_repository_path', $this->node->publish_path);
    }
    if (!empty($this->getFormStateValue('field_git_docroot')) && !empty($this->getFormStateValue('field_git_repository_url'))) {
      $publish_path = $this->getFormStateValue('field_git_repository_path') . '/' . $this->getFormStateValue('field_git_docroot');
      $this->setFormStateValue('publish_path', $publish_path);
      $this->platform->setProperty('publish_path', $publish_path);
    }
  }

  public function fieldAttachSubmit() {
    if ($this->isANewNode() && !empty($this->getFormStateValue('field_git_repository_url'))) {
      $this->setFormStateValue('field_git_repository_path', $this->node->publish_path);
      $this->platform->setFieldValue('field_git_repository_path', $this->node->publish_path);
    }
  }

}

