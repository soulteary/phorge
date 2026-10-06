<?php

// The pull and push event models are gone; their tables are not.
final class PhabricatorIPv6MigrationPullDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_pullevent';
  }

}

final class PhabricatorIPv6MigrationPushDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_pushevent';
  }

}

$pull = new PhabricatorIPv6MigrationPullDAO();
$push = new PhabricatorIPv6MigrationPushDAO();

$conn_w = $pull->establishConnection('w');

$log_types = array($pull, $push);
foreach ($log_types as $log) {
  $rows = new LiskRawMigrationIterator($conn_w, $log->getTableName());
  foreach ($rows as $row) {
    $addr = $row['remoteAddress'];

    $addr = (string)$addr;
    if (!strlen($addr)) {
      continue;
    }

    if (!ctype_digit($addr)) {
      continue;
    }

    if (!(int)$addr) {
      continue;
    }

    $ip = long2ip($addr);
    if (!is_string($ip) || !strlen($ip)) {
      continue;
    }

    $id = $row['id'];
    queryfx(
      $conn_w,
      'UPDATE %T SET remoteAddress = %s WHERE id = %d',
      $log->getTableName(),
      $ip,
      $id);
  }
}
