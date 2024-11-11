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
    $this->disableField('field_git_repository_path');
    $this->lockFields();
    $this->moveFieldsIntoFieldset('platform_git', $this->fields);
    $this->form['platform_git']['#title'] = 'Deploy from Git';
    $this->form['platform_git']['#description'] = 'You may deploy this platform from a Git repository. This strategy is appropriate for hosting scenarios where multiple sites will share common platforms.';
    $this->addValidateHandler('hosting_platform_git_platform_validate');
    $this->addSubmitHandler('hosting_platform_git_platform_submit');
  }

  protected function lockFields() {
    if (isset($this->node->platform_status) && $this->node->platform_status != HOSTING_PLATFORM_QUEUED) {
      foreach ($this->fields as $field) {
        $this->makeFieldReadOnly($field);
      }
    }
  }

  public function validate() {
    $field_git_repository_path = $this->getFormStateValue('field_git_repository_path');
    if ($this->isANewNode() && !empty($this->getFormStateValue('field_git_repository_url'))) {
      $field_git_repository_path = $this->getFormStateValue('publish_path');
    }

    if (!empty($this->getFormStateValue('field_git_docroot'))) {
      $publish_path = $field_git_repository_path . '/' . $this->getFormStateValue('field_git_docroot');

      // Check to see if this publish path is unique.
      $exists = hosting_platform_path_exists($publish_path, $this->platform->getNid());
      if ($exists) {
        // Ensure that the visitor has access to view the other platform.
        if (($other_node = node_load($exists->nid)) && node_access('view', $other_node)) {
          form_set_error('publish_path', t('Publish path is already in use by platform: <a href="@link" target="_blank">%name</a>. Platform paths must be unique across all servers.',
            array(
              '%name' => $other_node->title,
              '@link' => url('node/' . $exists->nid),
            )));
        }
        else {
          form_set_error('publish_path', t('Publish path is already in use by another platform. Platform paths must be unique across all servers.'));
        }
      }
    }
  }

  public function submit() {
    $this->setRepositoryPath();
    $this->setPublishPath();
  }

  protected function setRepositoryPath() {
    if ($this->isANewNode() && !empty($this->getFormStateValue('field_git_repository_url'))) {
      $this->setFormStateValue('field_git_repository_path', $this->getFormStateValue('publish_path'));
    }
  }

  protected function setPublishPath() {
    if (!empty($this->getFormStateValue('field_git_docroot'))) {
      $publish_path = $this->getFormStateValue('field_git_repository_path') . '/' . $this->getFormStateValue('field_git_docroot');
      $this->setFormStateValue('publish_path', $publish_path);
    }
  }

}

