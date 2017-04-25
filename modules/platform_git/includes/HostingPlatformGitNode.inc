<?php

/**
 * @file
 * The HostingPlatformGitNode class.
 */

class HostingPlatformGitNode extends HostingNode {

  protected $info_fields = [
    'field_git_repository_url',
    'field_git_repository_path',
    'field_git_reference',
    'field_git_docroot',
  ];

}
