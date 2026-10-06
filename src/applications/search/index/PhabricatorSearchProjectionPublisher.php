<?php

/**
 * Atomically allocate a materialization revision and persist its outbox event.
 * Callers building live snapshots must hold the existing per-object index lock;
 * this transaction serializes publication, not reads of all business databases.
 */
final class PhabricatorSearchProjectionPublisher extends Phobject {

  public static function publishDocument(
    $namespace,
    PhabricatorSearchAbstractDocument $document,
    $source_version = null,
    $force = false) {
    $hash = PhabricatorSearchDocumentSerializer::getDocumentHash($document);
    $spec = PhabricatorSearchDocumentSerializer::newDocumentSpec($document);
    if (strlen(phutil_json_encode($spec)) > 2097152) {
      throw new InvalidArgumentException(pht('Search projection exceeds 2 MiB.'));
    }
    return self::publish(
      $namespace, $document->getPHID(), $document->getDocumentType(),
      'upsert', $hash, $spec, $source_version === null ? $hash : $source_version,
      $force);
  }

  /** Explicit authoritative deletion only: never called for export "missing". */
  public static function publishDeletion($namespace, $phid, $type, $source_version) {
    $hash = hash('sha256', json_encode(array(
      'operation' => 'delete', 'phid' => $phid, 'type' => $type),
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return self::publish($namespace, $phid, $type, 'delete', $hash, null,
      $source_version, false);
  }

  private static function publish(
    $namespace, $phid, $type, $operation, $hash, $document, $source_version, $force) {
    if (PHP_INT_SIZE < 8 || !is_string($namespace) ||
        !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/', $namespace) ||
        !is_string($phid) || strlen($phid) > 64 ||
        !preg_match('/\APHID-[A-Z0-9]{4}-[a-zA-Z0-9]+\z/', $phid) ||
        $type !== phid_get_type($phid) || !is_string($source_version) ||
        !strlen($source_version) || strlen($source_version) > 512 ||
        !phutil_is_utf8($source_version)) {
      throw new InvalidArgumentException(pht('Invalid search projection identity.'));
    }
    $serializer = PhabricatorSearchDocumentSerializer::VERSION;
    $state = new PhabricatorSearchGorgeProjection();
    $outbox = new PhabricatorSearchGorgeOutbox();
    $state->openTransaction();
    try {
      $conn = $state->establishConnection('w');
      queryfx($conn,
        'INSERT INTO %R (namespace, objectPHID, revision, serializerVersion,
          sourceVersion, payloadHash, operation, lastEventID)
         VALUES (%s, %s, 0, %s, %s, %s, %s, %s)
         ON DUPLICATE KEY UPDATE objectPHID = objectPHID',
        $state, $namespace, $phid, '', '', '', '', '');
      $row = queryfx_one($conn,
        'SELECT * FROM %R WHERE namespace = %s AND objectPHID = %s FOR UPDATE',
        $state, $namespace, $phid);
      if (!$force && $row['lastEventID'] !== '' &&
          $row['payloadHash'] === $hash && $row['operation'] === $operation &&
          $row['serializerVersion'] === $serializer &&
          $row['sourceVersion'] === $source_version) {
        $existing = queryfx_one($conn,
          'SELECT payload FROM %R WHERE eventID = %s', $outbox, $row['lastEventID']);
        if (!$existing) {
          throw new Exception(pht('Search projection receipt was removed prematurely.'));
        }
        $event = phutil_json_decode($existing['payload']);
      } else {
        $previous = (int)$row['revision'];
        if ($previous < 0 || $previous >= PHP_INT_MAX) {
          throw new Exception(pht('Search projection revision exhausted.'));
        }
        $revision = $previous + 1;
        $event_id = 'search/'.hash('sha256', $namespace."\0".$phid).'/'.$revision;
        $event = array(
          'projectionVersion' => 1, 'eventID' => $event_id,
          'namespace' => $namespace, 'phid' => $phid, 'type' => $type,
          'revision' => (string)$revision, 'operation' => $operation,
          'serializerVersion' => $serializer, 'sourceVersion' => $source_version,
          'payloadHash' => $hash);
        if ($document !== null) { $event['document'] = $document; }
        $outbox->setEventID($event_id)->setPayload(phutil_json_encode($event))->save();
        queryfx($conn,
          'UPDATE %R SET revision = %d, serializerVersion = %s, sourceVersion = %s,
            payloadHash = %s, operation = %s, lastEventID = %s
           WHERE namespace = %s AND objectPHID = %s',
          $state, $revision, $serializer, $source_version, $hash, $operation,
          $event_id, $namespace, $phid);
      }
      $state->saveTransaction();
      return $event;
    } catch (Throwable $ex) {
      $state->killTransaction();
      throw $ex;
    }
  }
}
