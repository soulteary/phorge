<?php

/** Durable pre-destruction intent; source absence is checked on its writer. */
final class PhabricatorSearchDeletionRecovery extends Phobject {

  public static function shouldCapture($object) {
    return PhabricatorEnv::getEnvConfig('gorge.search.projection-shadow') &&
      $object instanceof LiskDAO &&
      $object instanceof PhabricatorFulltextInterface && $object->getPHID();
  }

  /** Caller holds the per-object index lock throughout destruction. */
  public static function begin($object) {
    $table = new PhabricatorSearchGorgeDeletion();
    $conn = $table->establishConnection('w');
    if ($conn->isInsideTransaction() ||
        $object->establishConnection('w')->isInsideTransaction()) {
      throw new Exception(pht('Deletion intent requires committed source and search connections.'));
    }
    queryfx($conn,
      'INSERT INTO %R (namespace, objectPHID, objectClass, sourceVersion,
         completedEpoch, nextAttempt) VALUES (%s, %s, %s, %s, NULL, 0)
       ON DUPLICATE KEY UPDATE objectClass=VALUES(objectClass),
         sourceVersion=VALUES(sourceVersion), completedEpoch=NULL, nextAttempt=0',
      $table, PhabricatorEnv::getEnvConfig('storage.default-namespace'),
      $object->getPHID(), get_class($object),
      'destruction/'.bin2hex(random_bytes(16)));
  }

  /** Caller holds the same index lock; no permission-filtered object query. */
  public static function reconcile($phid) {
    $namespace = PhabricatorEnv::getEnvConfig('storage.default-namespace');
    $table = new PhabricatorSearchGorgeDeletion();
    $conn = $table->establishConnection('w');
    $row = queryfx_one($conn,
      'SELECT * FROM %R WHERE namespace=%s AND objectPHID=%s
       AND completedEpoch IS NULL', $table, $namespace, $phid);
    if (!$row) { return false; }
    $class = $row['objectClass'];
    if (!is_subclass_of($class, 'LiskDAO') ||
        !is_subclass_of($class, 'PhabricatorFulltextInterface')) {
      throw new Exception(pht('Deletion source class is unavailable.'));
    }
    $source = newv($class, array());
    $source_conn = $source->establishConnection('w');
    if ($source_conn->isInsideTransaction() || $conn->isInsideTransaction()) {
      return false;
    }
    $exists = queryfx_one($source_conn,
      'SELECT phid FROM %R WHERE phid=%s LIMIT 1', $source, $phid);
    if ($exists) {
      queryfx($conn,
        'UPDATE %R SET nextAttempt=UNIX_TIMESTAMP()+60
         WHERE id=%d AND sourceVersion=%s AND completedEpoch IS NULL',
        $table, $row['id'], $row['sourceVersion']);
      return false;
    }
    // Intent completion and projection/outbox use the same search connection.
    $table->openTransaction();
    try {
      $locked = queryfx_one($conn,
        'SELECT * FROM %R WHERE id=%d FOR UPDATE', $table, $row['id']);
      if (!$locked || $locked['completedEpoch'] !== null ||
          $locked['sourceVersion'] !== $row['sourceVersion']) {
        $table->saveTransaction();
        return false;
      }
      PhabricatorSearchProjectionPublisher::publishDeletion(
        $namespace, $phid, phid_get_type($phid), $row['sourceVersion']);
      queryfx($conn, 'UPDATE %R SET completedEpoch=UNIX_TIMESTAMP() WHERE id=%d',
        $table, $row['id']);
      $table->saveTransaction();
      return true;
    } catch (Throwable $ex) {
      $table->killTransaction();
      throw $ex;
    }
  }

  /** Bounded recovery; failed or still-present sources do not starve later rows. */
  public static function recoverBatch() {
    $table = new PhabricatorSearchGorgeDeletion();
    $conn = $table->establishConnection('w');
    $rows = queryfx_all($conn,
      'SELECT objectPHID FROM %R WHERE namespace=%s AND completedEpoch IS NULL
       AND nextAttempt<=UNIX_TIMESTAMP() ORDER BY nextAttempt,id LIMIT 32',
      $table, PhabricatorEnv::getEnvConfig('storage.default-namespace'));
    $completed = 0;
    foreach ($rows as $row) {
      $phid = $row['objectPHID'];
      $lock = PhabricatorGlobalLock::newLock('index', array('objectPHID' => $phid));
      try {
        $lock->lock(1);
        try { $completed += (int)self::reconcile($phid); }
        finally { $lock->unlock(); }
      } catch (Throwable $ex) {
        queryfx($conn,
          'UPDATE %R SET nextAttempt=UNIX_TIMESTAMP()+60
           WHERE namespace=%s AND objectPHID=%s AND completedEpoch IS NULL',
          $table, PhabricatorEnv::getEnvConfig('storage.default-namespace'), $phid);
        phlog(pht('Search deletion recovery remains pending (%s).', $phid));
      }
    }
    return $completed;
  }
}
