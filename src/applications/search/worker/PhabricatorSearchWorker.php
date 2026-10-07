<?php

final class PhabricatorSearchWorker extends PhabricatorWorker {

  public static function queueDocumentForIndexing(
    $phid,
    $parameters = null,
    $is_strict = false) {

    if ($parameters === null) {
      $parameters = array();
    }

    parent::scheduleTask(
      self::class,
      array(
        'documentPHID' => $phid,
        'parameters' => $parameters,
        'strict' => $is_strict,
      ),
      array(
        'priority' => parent::PRIORITY_INDEX,
        'objectPHID' => $phid,
      ));
  }

  protected function doWork() {
    $data = $this->getTaskData();
    $object_phid = idx($data, 'documentPHID');

    // See T12425. By the time we run an indexing task, the object it indexes
    // may have been deleted. This is unusual, but not concerning, and failing
    // to index these objects is correct.

    // Deleted objects need no further indexing. Strict "bin/search index"
    // reports their permanent failure; temporary backend/capture failures
    // always reach the retry protocol.

    $is_strict = idx($data, 'strict', false);

    try {
      $object = $this->loadObjectForIndexing($object_phid);
    } catch (PhabricatorWorkerPermanentFailureException $ex) {
      if ($is_strict) {
        throw $ex;
      } else {
        return;
      }
    }

    $engine = id(new PhabricatorIndexEngine())
      ->setObject($object);

    $parameters = idx($data, 'parameters', array());
    $engine->setParameters($parameters);

    if (!$engine->shouldIndexObject()) {
      return;
    }

    $lock = PhabricatorGlobalLock::newLock(
      'index',
      array(
        'objectPHID' => $object_phid,
      ));

    try {
      $lock->lock(1);
    } catch (PhutilLockException $ex) {
      // If we fail to acquire the lock, just yield. It's expected that we may
      // contend on this lock occasionally if a large object receives many
      // updates in a short period of time, and it's appropriate to just retry
      // rebuilding the index later.
      throw new PhabricatorWorkerYieldException(15);
    }

    $caught = null;
    try {
      // Reload the object now that we have a lock, to make sure we have the
      // most current version.
      $object = $this->loadObjectForIndexing($object->getPHID());

      $engine->setObject($object);
      // Re-evaluate versions after the locked reload, not against the old object.
      if ($engine->shouldIndexObject()) {
        $engine->indexObject();
      }
    } catch (Throwable $ex) {
      $caught = $ex;
    } finally {
      $lock->unlock();
    }

    if ($caught) {
      self::rethrowIndexingFailure($caught, $is_strict);
    }
  }

  public static function rethrowIndexingFailure(Throwable $caught, $is_strict) {
    // Missing/deleted objects explicitly declare a permanent failure. All
    // other failures, including aggregated backend and capture failures, must
    // reach the worker retry protocol instead of completing the task.
    if (!$is_strict &&
        $caught instanceof PhabricatorWorkerPermanentFailureException) {
      return;
    }
    throw $caught;
  }

  private function loadObjectForIndexing($phid) {
    $viewer = PhabricatorUser::getOmnipotentUser();

    $object = id(new PhabricatorObjectQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($phid))
      ->executeOne();

    if (!$object) {
      throw new PhabricatorWorkerPermanentFailureException(
        pht(
          'Unable to load object "%s" to rebuild indexes.',
          $phid));
    }

    return $object;
  }

}
