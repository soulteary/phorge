<?php

/**
 * Accessor for the legacy `harbormaster` database.
 *
 * The Harbormaster application is gone, but its schema history is retained, so
 * the database and its tables still exist. Two things still reach them: the
 * generic Lisk test fixtures @{class:HarbormasterObject} and
 * @{class:HarbormasterScratchTable}, which are not Harbormaster functionality
 * and never were, and the historical PHP schema patches which migrate old
 * Harbormaster rows.
 *
 * Keeping the application name means those are the tables the retained
 * patches created. Renaming it would point this at a database which was never
 * created.
 */
abstract class HarbormasterDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'harbormaster';
  }

}
