<?php

abstract class PhabricatorAphlictManagementWorkflow
  extends PhabricatorManagementWorkflow {

  final protected function executeRetiredCommand() {
    throw new PhutilArgumentUsageException(
      pht(
        'The bundled Aphlict Node.js server has been removed. Use the '.
        'gorge-notification service and configure "notification.servers".'));
  }

}
