<?php

final class PhabricatorAphlictManagementRestartWorkflow
  extends PhabricatorAphlictManagementWorkflow {

  protected function didConstruct() {
    $this
      ->setName('restart')
      ->setSynopsis(pht('Report that the Aphlict server is retired.'));
  }

  public function execute(PhutilArgumentParser $args) {
    return $this->executeRetiredCommand();
  }

}
