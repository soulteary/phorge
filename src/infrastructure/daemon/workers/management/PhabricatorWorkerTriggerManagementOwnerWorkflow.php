<?php

final class PhabricatorWorkerTriggerManagementOwnerWorkflow
  extends PhabricatorWorkerTriggerManagementWorkflow {

  protected function didConstruct() {
    $this->setName('owner')
      ->setSynopsis(pht('Inspect or switch persistent scheduler ownership.'))
      ->setArguments(array(array(
        'name' => 'set',
        'param' => 'owner',
        'help' => pht('Switch to php, paused, or gorge. Changes require paused.'),
      )));
  }

  public function execute(PhutilArgumentParser $args) {
    $table = new PhabricatorWorkerGorgeSchedulerControl();
    $conn = $table->establishConnection('w');
    $table->openTransaction();
    try {
      $control = PhabricatorWorkerGorgeSchedulerControl::lockOwner();
      if (!$control) {
        throw new Exception(pht('Run storage upgrade before switching owners.'));
      }
      $owner = $args->getArg('set');
      if ($owner !== null && $owner !== '') {
        if (!in_array($owner, array('php', 'paused', 'gorge'), true)) {
          throw new PhutilArgumentUsageException(pht('Invalid owner.'));
        }
        if ($owner !== $control['owner']) {
          if ($owner !== 'paused' && $control['owner'] !== 'paused') {
            throw new PhutilArgumentUsageException(pht('Pause before switching.'));
          }
          if ($owner === 'gorge') {
            if (!PhabricatorGorgeTaskQueueClient::isConfigured() ||
                !PhabricatorGorgeServiceRegistry::getService('conduit')
                  ->getConfiguredToken()) {
              throw new Exception(pht('Configure Gorge queue and Conduit token.'));
            }
            $capabilities = id(new PhabricatorGorgeTaskQueueClient())
              ->getSchedulerCapabilities();
            if (idx($capabilities, 'schedulerProtocol') !== 1 ||
                !idx($capabilities, 'schedulerAtomicEnqueue')) {
              throw new Exception(pht('Enable the migrated MySQL scheduler first.'));
            }
            if (!phutil_nonempty_string($control['databaseID']) ||
                idx($capabilities, 'schedulerDatabaseID') !== $control['databaseID']) {
              throw new Exception(pht('Gorge scheduler worker database identity does not match Phorge.'));
            }
            $trigger_table = new PhabricatorWorkerTrigger();
            $after_id = 0;
            do {
              $rows = queryfx_all($conn,
                'SELECT id FROM %T WHERE id > %d ORDER BY id LIMIT 100',
                $trigger_table->getTableName(), $after_id);
              foreach ($rows as $row) {
                PhabricatorTriggerPlanConduitAPIMethod::preflightPlanningTrigger($row['id']);
                $after_id = (int)$row['id'];
              }
            } while (count($rows) === 100);
          }
          if ($owner === 'gorge') {
            // Preserve pending events which the legacy daemon has already
            // scheduled. A metronomic first event must not be reset to now.
            $cursor = LiskDAO::loadCurrentCounterValue(
              $conn, PhabricatorTriggerDaemon::COUNTER_CURSOR);
            queryfx($conn,
              'INSERT IGNORE INTO %T
                (triggerID, scheduledVersion, retryAfter, lastEvaluatedEpoch)
                SELECT t.id, t.triggerVersion, 0, 0 FROM %T t
                JOIN %T e ON e.triggerID = t.id WHERE t.triggerVersion < %d',
              id(new PhabricatorWorkerGorgeSchedule())->getTableName(),
              id(new PhabricatorWorkerTrigger())->getTableName(),
              id(new PhabricatorWorkerTriggerEvent())->getTableName(),
              $cursor);
          }
          // The epoch invalidates all plans without erasing event history.
          queryfx($conn, 'UPDATE %T SET owner = %s, epoch = epoch + 1 WHERE id = 1',
            $table->getTableName(), $owner);
          $control = PhabricatorWorkerGorgeSchedulerControl::lockOwner();
        }
      }
      $table->saveTransaction();
    } catch (Throwable $ex) {
      $table->killTransaction();
      throw $ex;
    }
    PhutilConsole::getConsole()->writeOut("%s (epoch %s)\n",
      $control['owner'], $control['epoch']);
    return 0;
  }
}
