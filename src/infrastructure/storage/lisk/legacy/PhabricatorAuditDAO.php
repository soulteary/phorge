<?php

/**
 * Accessor for the legacy `audit` database.
 *
 * The same situation as @{class:PhabricatorRepositoryDAO}: the Audit
 * application is gone, its schema history is retained, and the historical PHP
 * patches which migrate old audit rows still need a connection and a table
 * name.
 *
 * `PhabricatorAuditTransaction` used to serve this role. It survived the Audit
 * removal by moving into Diffusion with its name and application intact, and
 * has now gone with Diffusion, so the role is given to a class that belongs to
 * no application and cannot be removed by retiring one.
 */
abstract class PhabricatorAuditDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'audit';
  }

}
