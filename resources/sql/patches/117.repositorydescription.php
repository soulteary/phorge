<?php

// As elsewhere: the model is gone, only the connection was wanted.
final class PhabricatorRepositoryDescriptionMigrationDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository';
  }

}

$conn = id(new PhabricatorRepositoryDescriptionMigrationDAO())
  ->establishConnection('w');
if (queryfx_one($conn, "SHOW COLUMNS FROM `repository` LIKE 'description'")) {
  queryfx($conn, 'ALTER TABLE `repository` DROP `description`');
}
