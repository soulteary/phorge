<?php

// PhabricatorRepository and PhabricatorRepositoryPushEvent are gone. This
// patch wanted the first for a connection and the second only to mint a PHID;
// push event PHIDs are of type "PSHE", which is the stored prefix.
final class PhabricatorPushGroupsMigrationDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository';
  }

}

$conn_w = id(new PhabricatorPushGroupsMigrationDAO())->establishConnection('w');

echo pht('Adding transaction log event groups...')."\n";

$logs = queryfx_all(
  $conn_w,
  'SELECT * FROM %T GROUP BY transactionKey ORDER BY id ASC',
  'repository_pushlog');
foreach ($logs as $log) {
  $id = $log['id'];
  echo pht('Migrating log %d...', $id)."\n";
  if ($log['pushEventPHID']) {
    continue;
  }

  $event_phid = PhabricatorPHID::generateNewPHID('PSHE');

  queryfx(
    $conn_w,
    'INSERT INTO %T (phid, repositoryPHID, epoch, pusherPHID, remoteAddress,
      remoteProtocol, rejectCode, rejectDetails)
     VALUES (%s, %s, %d, %s, %d, %s, %d, %s)',
    'repository_pushevent',
    $event_phid,
    $log['repositoryPHID'],
    $log['epoch'],
    $log['pusherPHID'],
    $log['remoteAddress'],
    $log['remoteProtocol'],
    $log['rejectCode'],
    $log['rejectDetails']);

  queryfx(
    $conn_w,
    'UPDATE %T SET pushEventPHID = %s WHERE transactionKey = %s',
    'repository_pushlog',
    $event_phid,
    $log['transactionKey']);
}

echo pht('Done.')."\n";
