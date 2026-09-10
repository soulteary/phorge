<?php

final class PhabricatorAphlictManagementStartWorkflow
  extends PhabricatorAphlictManagementWorkflow {

  protected function didConstruct() {
    $this
      ->setName('start')
      ->setSynopsis(pht('Report that the Aphlict server is retired.'));
  }

  public function execute(PhutilArgumentParser $args) {
    return $this->executeRetiredCommand();
  }

}
