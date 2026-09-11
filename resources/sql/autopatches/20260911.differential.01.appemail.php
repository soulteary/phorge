<?php

// The Differential application has been removed, so an application email
// routing to it can never deliver again. Leaving the row behind is worse than
// it looks: the unique key on "address" keeps the address reserved for an
// application that no longer exists, while
// PhabricatorMetaMTAApplicationEmailQuery discards rows whose application
// cannot be loaded -- including in the panel an administrator would use to
// delete one. The address ends up claimed and unreachable at the same time.
//
// Release them here, the same way the Paste removal does. Differential
// supported email integration, so an administrator may have created one or
// more of these addresses; nothing refers to the rows once the application is
// gone.

// PhabricatorApplication::getPHID() is 'PHID-APPS-'.get_class($this), so this
// is the literal the rows carry. The class is gone, so it cannot be asked.
$revision_application_phid = 'PHID-APPS-PhabricatorDifferentialApplication';

$table = new PhabricatorMetaMTAApplicationEmail();
$xaction_table = new PhabricatorMetaMTAApplicationEmailTransaction();
$conn = $table->establishConnection('w');

$rows = queryfx_all(
  $conn,
  'SELECT id, phid, address FROM %T WHERE applicationPHID = %s',
  $table->getTableName(),
  $revision_application_phid);

if (!$rows) {
  echo pht('No Differential application email addresses to release.')."\n";
} else {
  foreach ($rows as $row) {
    echo pht(
      'Releasing Differential application email "%s"...',
      $row['address'])."\n";

    queryfx(
      $conn,
      'DELETE FROM %T WHERE objectPHID = %s',
      $xaction_table->getTableName(),
      $row['phid']);

    queryfx(
      $conn,
      'DELETE FROM %T WHERE id = %d',
      $table->getTableName(),
      $row['id']);
  }

  echo pht('Done.')."\n";
}
