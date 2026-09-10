<?php

final class PhabricatorAphlictManagementDebugWorkflow
  extends PhabricatorAphlictManagementWorkflow {

  protected function didConstruct() {
    $this
      ->setName('debug')
      ->setSynopsis(pht('Report that the Aphlict server is retired.'));
  }

  public function execute(PhutilArgumentParser $args) {
    return $this->executeRetiredCommand();
  }

}
