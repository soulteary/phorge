<?php

/** Read-only clock planning. Queue/event mutation belongs to Gorge MySQL. */
final class PhabricatorTriggerPlanConduitAPIMethod extends ConduitAPIMethod {

  public function getAPIMethodName() {
    return 'trigger.plan';
  }

  public function getMethodDescription() {
    return pht('Compute a versioned trigger plan without executing its action.');
  }

  public function shouldRequireAuthentication() {
    return false;
  }

  protected function defineParamTypes() {
    return array(
      'phase' => 'required string',
      'protocol' => 'required int',
      'databaseID' => 'required string',
      'triggerID' => 'optional int',
      'version' => 'optional int',
      'scheduledVersion' => 'optional int',
      'lastEpoch' => 'optional int',
      'nextEpoch' => 'optional int',
      'now' => 'optional int',
    );
  }

  protected function defineReturnType() {
    return 'map<string, wild>';
  }

  protected function defineErrorTypes() {
    return array(
      'ERR-TRIGGER-AUTH' => pht('A configured service token is required.'),
      'ERR-TRIGGER-PLAN' => pht('The trigger plan is unsupported or stale.'),
      'ERR-TRIGGER-READONLY' => pht('Trigger scheduling is paused in read-only mode.'),
    );
  }

  public static function loadPlanningTrigger($id) {
    $table = new PhabricatorWorkerTrigger();
    // Writer reads avoid computing a new clock from a lagging replica.
    $row = queryfx_one(
      $table->establishConnection('w'),
      'SELECT * FROM %T WHERE id = %d',
      $table->getTableName(),
      $id);
    if (!$row) {
      throw new Exception(pht('Trigger no longer exists.'));
    }
    $trigger = $table->loadFromArray($row);
    if ($trigger->getActionClass() !==
        PhabricatorScheduleTaskTriggerAction::class) {
      throw new Exception(pht('Only task scheduling actions support takeover.'));
    }
    $clock_class = $trigger->getClockClass();
    if (!is_subclass_of($clock_class, PhabricatorTriggerClock::class)) {
      throw new Exception(pht('Invalid trigger clock.'));
    }
    $clock = newv($clock_class, array($trigger->getClockProperties()));
    $action = new PhabricatorScheduleTaskTriggerAction(
      $trigger->getActionProperties());
    $properties = $action->getProperties();
    PhutilTypeSpec::checkMap($properties['options'], array(
      'priority' => 'optional int|null',
      'objectPHID' => 'optional string|null',
      'containerPHID' => 'optional string|null',
      'delayUntil' => 'optional int|null',
    ));
    if (!is_subclass_of($properties['class'], PhabricatorWorker::class)) {
      throw new Exception(pht('Invalid scheduled worker class.'));
    }
    return $trigger->attachClock($clock)->attachAction($action);
  }

  public static function computePlanningEpoch($trigger, $last, $is_reschedule) {
    $next = $trigger->getNextEventEpoch($last, $is_reschedule);
    if ($next !== null &&
        (!is_int($next) || $next <= 0 || $next > 4294967295 ||
         ($is_reschedule && $next <= $last))) {
      throw new Exception(pht('Trigger clocks must return a valid, advancing epoch.'));
    }
    return $next;
  }

  public static function preflightPlanningTrigger($id) {
    $trigger = self::loadPlanningTrigger($id);
    $event = queryfx_one(
      $trigger->establishConnection('w'),
      'SELECT lastEventEpoch, nextEventEpoch FROM %T WHERE triggerID = %d',
      id(new PhabricatorWorkerTriggerEvent())->getTableName(),
      $id);
    $last = $event ? $event['lastEventEpoch'] : null;
    $first = self::computePlanningEpoch($trigger, $last, false);
    // Check both the newly calculated occurrence and an existing pending
    // occurrence. No action executes and no event is advanced by this probe.
    $epochs = array($first, $event ? $event['nextEventEpoch'] : null);
    foreach ($epochs as $epoch) {
      if ($epoch !== null) {
        self::computePlanningEpoch($trigger, (int)$epoch, true);
      }
    }
  }

  protected function execute(ConduitAPIRequest $request) {
    $expected = PhabricatorGorgeServiceRegistry::getService('conduit')
      ->getConfiguredToken();
    $presented = AphrontRequest::getHTTPHeader('X-Service-Token');
    if (!phutil_nonempty_string($expected) ||
        !phutil_nonempty_string($presented) ||
        !hash_equals($expected, $presented)) {
      throw new ConduitException('ERR-TRIGGER-AUTH');
    }
    if (PhabricatorEnv::isReadOnly()) {
      throw new ConduitException('ERR-TRIGGER-READONLY');
    }
    if ($request->getValue('protocol') !== 1) {
      throw new ConduitException('ERR-TRIGGER-PLAN');
    }
    $control_table = new PhabricatorWorkerGorgeSchedulerControl();
    $control = queryfx_one(
      $control_table->establishConnection('w'),
      'SELECT databaseID FROM %T WHERE id = 1',
      $control_table->getTableName());
    if (!$control || !phutil_nonempty_string($control['databaseID']) ||
        $request->getValue('databaseID') !== $control['databaseID']) {
      throw new ConduitException('ERR-TRIGGER-PLAN');
    }
    if ($request->getValue('phase') === 'capabilities') {
      return array('protocol' => 1, 'atomicEnqueue' => true,
        'databaseID' => $control['databaseID']);
    }
    if ($request->getValue('phase') !== 'plan') {
      throw new ConduitException('ERR-TRIGGER-PLAN');
    }
    try {
      $trigger = self::loadPlanningTrigger($request->getValue('triggerID'));
      $version = $request->getValue('version');
      $now = $request->getValue('now');
      if ($trigger->getTriggerVersion() != $version || !$now) {
        throw new Exception(pht('Stale trigger version or invalid time.'));
      }
      $last = $request->getValue('lastEpoch');
      $next = $request->getValue('nextEpoch');
      $fire = ($request->getValue('scheduledVersion') == $version &&
        $next !== null && $next <= $now);
      $time_guard = PhabricatorTime::pushTime($now, date_default_timezone_get());
      $new_next = self::computePlanningEpoch(
        $trigger, $fire ? $next : $last, $fire);
      $task = null;
      if ($fire) {
        $properties = $trigger->getAction()->getProperties();
        $data = $properties['data'] + array(
          'trigger.last-epoch' => $last,
          'trigger.this-epoch' => $next,
        );
        $task = array(
          'taskClass' => $properties['class'],
          'data' => phutil_json_encode($data),
        );
        foreach ($properties['options'] as $key => $value) {
          if ($value !== null) {
            $task[$key] = $value;
          }
        }
      }
      return array(
        'protocol' => 1,
        'triggerID' => (int)$trigger->getID(),
        'version' => (int)$version,
        'fire' => $fire,
        'nextEpoch' => $new_next === null ? null : (int)$new_next,
        'task' => $task,
      );
    } catch (Exception $ex) {
      throw new ConduitException('ERR-TRIGGER-PLAN');
    }
  }
}
