<?php

/**
 * Accessor for the legacy `repository` database.
 *
 * Tracked repositories are gone, but the schema history which created this
 * database and its tables is retained, so the tables and their rows still
 * exist. The historical PHP schema patches under `resources/sql` still have to
 * run against installations whose schema predates them, and
 * @{class:PhabricatorStorageManagementAPI} requires those files with no
 * compatibility guard -- a missing class there aborts `bin/storage upgrade`.
 *
 * Those patches wanted the removed models only for a connection and a table
 * name, so each declares a small subclass of this with the table it migrates.
 *
 * Keeping the application name is the point: `repository` is the database the
 * retained patches created. Renaming it would aim this at a database which was
 * never created.
 */
abstract class PhabricatorRepositoryDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'repository';
  }

}
