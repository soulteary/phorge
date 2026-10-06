<?php

// Advise installs to rebuild the repository identities.

// PhabricatorRepositoryCommit is gone, so the probe is a raw query against
// the retained table.
final class PhabricatorIdentitiesActivityMigrationDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_commit';
  }

}

$table = new PhabricatorIdentitiesActivityMigrationDAO();
$conn = $table->establishConnection('w');

// If the install has no commits (or no commits that lack an
// authorIdentityPHID), don't require a rebuild.
$commits = queryfx_one(
  $conn,
  'SELECT id FROM %T WHERE authorIdentityPHID IS NULL LIMIT 1',
  $table->getTableName());

if (!$commits) {
  return;
}

try {
  id(new PhabricatorConfigManualActivity())
    ->setActivityType(PhabricatorConfigManualActivity::TYPE_IDENTITIES)
    ->save();
} catch (AphrontDuplicateKeyQueryException $ex) {
  // If we've already noted that this activity is required, just move on.
}
