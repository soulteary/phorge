<?php

// The Paste application has been removed, so an application email routing to
// it can never deliver again. Leaving the row behind is worse than it looks:
// the unique key on "address" keeps the address reserved for an application
// that no longer exists, while PhabricatorMetaMTAApplicationEmailQuery
// discards rows whose application cannot be loaded -- including in the panel
// an administrator would use to delete one. The address ends up claimed and
// unreachable at the same time.
//
// Release them here. An installation only has such a row if it ran
// 20150129.pastefileapplicationemails.php before that patch stopped writing
// one; nothing refers to these rows once Paste is gone.

// PhabricatorApplication::getPHID() is 'PHID-APPS-'.get_class($this), so this
// is the literal the rows carry. The class is gone, so it cannot be asked.
$paste_application_phid = 'PHID-APPS-PhabricatorPasteApplication';

$table = new PhabricatorMetaMTAApplicationEmail();
$xaction_table = new PhabricatorMetaMTAApplicationEmailTransaction();
$conn = $table->establishConnection('w');

$rows = queryfx_all(
  $conn,
  'SELECT id, phid, address FROM %T WHERE applicationPHID = %s',
  $table->getTableName(),
  $paste_application_phid);

if (!$rows) {
  echo pht('No Paste application email addresses to release.')."\n";
} else {
  foreach ($rows as $row) {
    echo pht('Releasing Paste application email "%s"...', $row['address'])."\n";

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
