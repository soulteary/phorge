<?php

/** Fixed single-table cleanup contract. Domain destruction is deliberately absent. */
final class PhabricatorGorgeCleanup extends Phobject {

  public static function getRegistry() {
    return array(
      'cache.general.ttl' => array(new PhabricatorKeyValueDatabaseCache(), 'cache', 'cacheExpires'),
      'cache.general' => array(new PhabricatorKeyValueDatabaseCache(), 'cache', 'cacheCreated'),
      'cache.markup' => array(new PhabricatorMarkupCache(), 'cache', 'dateCreated'),
      'conduit.logs' => array(new PhabricatorConduitMethodCallLog(), 'conduit', 'dateCreated'),
      'daemon.processes' => array(new PhabricatorDaemonLogEvent(), 'daemon', 'epoch'),
      'daemon.lock-log' => array(new PhabricatorDaemonLockLog(), 'daemon', 'dateCreated'),
    );
  }

  /** Hold the execution-owner row and deletion on the same cached connection. */
  public static function runGuarded($collector, $callback) {
    $id = $collector->getCollectorConstant();
    $spec = idx(self::getRegistry(), $id);
    if (!$spec) {
      return call_user_func($callback);
    }
    if (PhabricatorEnv::isReadOnly()) {
      return false;
    }
    $conn = $spec[0]->establishConnection('w');
    $tables = queryfx_all(
      $conn,
      'SELECT ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%Ls)',
      array('gorge_gc_control', $spec[0]->getTableName()));
    if (count($tables) !== 2) {
      throw new Exception(pht('Run storage upgrade before cleanup.'));
    }
    foreach ($tables as $table) {
      if (strtolower($table['ENGINE']) !== 'innodb') {
        throw new Exception(pht('Guarded cleanup requires InnoDB tables.'));
      }
    }
    $conn->openTransaction();
    try {
      // Materialize the lock row before checking it. This also works under
      // READ COMMITTED and serializes first import with a legacy deletion.
      queryfx(
        $conn,
        'INSERT IGNORE INTO gorge_gc_control
          (collectorID, policyJSON, policyHash, lastError) VALUES (%s, %s, %s, %s)',
        $id, '{}', '', '');
      $control = queryfx_one(
        $conn,
        'SELECT owner FROM gorge_gc_control WHERE collectorID = %s FOR UPDATE',
        $id);
      if ($control && $control['owner'] !== 'php') {
        $conn->saveTransaction();
        return false;
      }
      $result = call_user_func($callback);
      $conn->saveTransaction();
      return $result;
    } catch (Throwable $ex) {
      $conn->killTransaction();
      throw $ex;
    }
  }

  /** Invalidate the exported policy before changing nontransactional local config. */
  public static function changePolicy($collector, $callback) {
    $id = $collector->getCollectorConstant();
    $spec = idx(self::getRegistry(), $id);
    if (!$spec) {
      return call_user_func($callback);
    }
    $conn = $spec[0]->establishConnection('w');
    $conn->openTransaction();
    try {
      queryfx($conn,
        'INSERT IGNORE INTO gorge_gc_control
          (collectorID, policyJSON, policyHash, lastError) VALUES (%s, %s, %s, %s)',
        $id, '{}', '', '');
      $row = queryfx_one($conn,
        'SELECT owner, lastState FROM gorge_gc_control WHERE collectorID = %s FOR UPDATE',
        $id);
      if ($row['lastState'] === 'policy_changing') {
        throw new Exception(pht('A policy change is already in progress; reconcile local configuration first.'));
      }
      if ($row['owner'] !== 'php' && $row['owner'] !== 'paused') {
        throw new Exception(pht('Pause Gorge cleanup before changing this policy.'));
      }
      queryfx($conn,
        'UPDATE gorge_gc_control SET policyHash = %s,
          ownerEpoch = ownerEpoch + 1, fence = fence + 1,
          leaseOwner = %s, leaseExpires = 0, lastState = %s
          WHERE collectorID = %s',
        '', '', 'policy_changing', $id);
      $conn->saveTransaction();
    } catch (Throwable $ex) {
      $conn->killTransaction();
      throw $ex;
    }
    // A committed changing marker blocks imports while the local file is written.
    // On process death it stays blocked; an operator must reconcile local config.
    try {
      return call_user_func($callback);
    } finally {
      queryfx($conn,
        'UPDATE gorge_gc_control SET lastState = %s WHERE collectorID = %s',
        'policy_pending', $id);
    }
  }

  public static function getExecutionOwner($collector) {
    $spec = idx(self::getRegistry(), $collector->getCollectorConstant());
    if (!$spec) {
      return null;
    }
    $row = queryfx_one(
      $spec[0]->establishConnection('w'),
      'SELECT owner FROM gorge_gc_control WHERE collectorID = %s',
      $collector->getCollectorConstant());
    return $row ? $row['owner'] : 'php';
  }

  public static function exportPolicies() {
    $collectors = PhabricatorGarbageCollector::getAllCollectors();
    $policies = array();
    foreach (self::getRegistry() as $id => $spec) {
      $collector = $collectors[$id];
      $mode = 'expires_at';
      $ttl = 0;
      if (!$collector->hasAutomaticPolicy()) {
        $ttl = $collector->getRetentionPolicy();
        if ($ttl === null || $ttl === 0) {
          $mode = 'indefinite';
          $ttl = 0;
        } else {
          if (!is_int($ttl) || $ttl < 1) {
            throw new Exception(pht('Invalid retention policy for %s.', $id));
          }
          $mode = 'retention_seconds';
        }
      }
      $policy = array(
        'id' => $id,
        'mode' => $mode,
        'retentionSeconds' => $ttl,
        'intervalSeconds' => 14400,
        'batchRows' => 100,
        'batchTimeoutMS' => 2000,
        'runSeconds' => 30,
        'runRows' => 10000,
      );
      $policy['revision'] = hash('sha256', phutil_json_encode($policy));
      $policies[] = $policy;
    }
    return array(
      'version' => 1,
      'guardEnabled' => (bool)PhabricatorEnv::getEnvConfig('phd.gorge-cleanup'),
      'readOnly' => PhabricatorEnv::isReadOnly(),
      'policies' => $policies,
    );
  }

}
