<?php

final class PhabricatorAphlictManagementStatusWorkflow
  extends PhabricatorAphlictManagementWorkflow {

  protected function didConstruct() {
    $this
      ->setName('status')
      ->setSynopsis(pht('Report that the Aphlict server is retired.'));
  }

  public function execute(PhutilArgumentParser $args) {
    return $this->executeRetiredCommand();
  }

}
