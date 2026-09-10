<?php

final class PhabricatorAphlictManagementStopWorkflow
  extends PhabricatorAphlictManagementWorkflow {

  protected function didConstruct() {
    $this
      ->setName('stop')
      ->setSynopsis(pht('Report that the Aphlict server is retired.'));
  }

  public function execute(PhutilArgumentParser $args) {
    return $this->executeRetiredCommand();
  }

}
