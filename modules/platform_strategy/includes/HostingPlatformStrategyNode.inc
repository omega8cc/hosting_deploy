<?php

/**
 * @file
 * The HostingPlatformStrategyNode class.
 */

class HostingPlatformStrategyNode extends HostingNode {
  protected $info_fields = ['field_deployment_strategy'];

  public function nodeView() {
    parent::nodeView();
    $strategies = HostingPlatformStrategyForm::getStrategies();
    $this->node->content['info']['field_deployment_strategy']['#markup'] = $strategies[$this->getFieldValue('field_deployment_strategy')];
  }

}
