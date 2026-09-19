<?php

// This patch moved repository URIs out of the "details" blob and into
// "repository_uri".
//
// It wrote two kinds of row. The observed remote URI is real stored data: an
// administrator typed it, and it lives nowhere else once "remote-uri" stops
// being read. That part is preserved below, written directly, because
// PhabricatorRepositoryURI is gone.
//
// The other kind came from PhabricatorRepository::newBuiltinURIs(), which
// derived the SSH/HTTP/HTTPS clone URIs from the install's base URI, its
// enabled protocols and the repository's chosen identifier. Those rows were
// always regenerated from configuration rather than authored, and both the
// generator and every reader of the result are gone, so they are not
// reconstructed. Reproducing that derivation here would mean reimplementing
// repository hosting configuration which no longer exists.
//
// The literals are the stored values of the removed constants: ioType
// "observe", displayType "default", and a PHID of type "RURI".
final class PhabricatorURIMigrationRepositoryDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository';
  }

}

final class PhabricatorURIMigrationURIDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_uri';
  }

}

$table = new PhabricatorURIMigrationRepositoryDAO();
$uri_table = new PhabricatorURIMigrationURIDAO();
$conn_w = $table->establishConnection('w');

foreach (new LiskRawMigrationIterator($conn_w, $table->getTableName())
  as $repository) {

  $details = phutil_json_decode($repository['details']);

  // isHosted() read the "hosting-enabled" detail.
  $is_hosted = (bool)idx($details, 'hosting-enabled', false);
  if ($is_hosted) {
    continue;
  }

  $remote_uri = idx($details, 'remote-uri');
  if (!phutil_nonempty_string($remote_uri)) {
    continue;
  }

  $repository_phid = $repository['phid'];

  $already_exists = queryfx_one(
    $conn_w,
    'SELECT id FROM %T WHERE repositoryPHID = %s AND uri = %s LIMIT 1',
    $uri_table->getTableName(),
    $repository_phid,
    $remote_uri);
  if ($already_exists) {
    continue;
  }

  $now = PhabricatorTime::getNow();

  queryfx(
    $conn_w,
    'INSERT INTO %T
      (phid, repositoryPHID, uri, credentialPHID, ioType, displayType,
       isDisabled, dateCreated, dateModified)
     VALUES (%s, %s, %s, %ns, %s, %s, %d, %d, %d)',
    $uri_table->getTableName(),
    PhabricatorPHID::generateNewPHID('RURI'),
    $repository_phid,
    $remote_uri,
    idx($details, 'credentialPHID'),
    'observe',
    'default',
    0,
    $now,
    $now);

  echo tsprintf(
    "%s\n",
    pht(
      'Migrated URI "%s" for repository "%s".',
      $remote_uri,
      $repository['name']));
}
