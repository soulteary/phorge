<?php

// PhabricatorRepositoryURI is gone, so the new rows are written directly.
// The literals are the values initializeNewURI() and the IO/display constants
// produced: ioType "mirror", displayType "default", isDisabled 0, and a PHID
// of type "RURI".
final class PhabricatorMirrorMigrationURIDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_uri';
  }

}

$table = new PhabricatorMirrorMigrationURIDAO();
$conn_w = $table->establishConnection('w');

$mirrors = queryfx_all(
  $conn_w,
  'SELECT * FROM %T',
  'repository_mirror');

foreach ($mirrors as $mirror) {
  $repository_phid = $mirror['repositoryPHID'];
  $uri = $mirror['remoteURI'];

  $already_exists = queryfx_one(
    $conn_w,
    'SELECT id FROM %T WHERE repositoryPHID = %s AND uri = %s',
    $table->getTableName(),
    $repository_phid,
    $uri);
  if ($already_exists) {
    // Decline to migrate stuff that looks like it was already migrated.
    continue;
  }

  queryfx(
    $conn_w,
    'INSERT INTO %T
      (phid, repositoryPHID, uri, credentialPHID, ioType, displayType,
       isDisabled, dateCreated, dateModified)
     VALUES (%s, %s, %s, %ns, %s, %s, %d, %d, %d)',
    $table->getTableName(),
    PhabricatorPHID::generateNewPHID('RURI'),
    $repository_phid,
    $uri,
    $mirror['credentialPHID'],
    'mirror',
    'default',
    0,
    $mirror['dateCreated'],
    $mirror['dateModified']);

  echo tsprintf(
    "%s\n",
    pht(
      'Migrated mirror "%s".',
      $uri));
}
