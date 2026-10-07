<?php

/**
 * Used by unit tests to build storage fixtures.
 */
final class PhabricatorStorageFixtureScopeGuard extends Phobject {

  private $name;

  public function __construct($name) {
    $this->name = $name;

    try {
      execx(
        'php %s upgrade --force --namespace %s',
        $this->getStorageBinPath(),
        $this->name);
    } catch (CommandException $ex) {
      // Retired applications leave surplus quickstart tables and indexes.
      // Accept only this condition after comparing live schemata.
      // Missing columns, unsupported types and migration failures still fail.
      if ($ex->getError() != 2 || !$this->hasOnlySurplusSchemata()) {
        throw $ex;
      }
    }

    PhabricatorLiskDAO::pushStorageNamespace($name);

    // Destructor is not called with fatal error.
    register_shutdown_function(array($this, 'destroy'));
  }

  public function destroy() {
    PhabricatorLiskDAO::popStorageNamespace();

    // NOTE: We need to close all connections before destroying the databases.
    // If we do not, the "DROP DATABASE ..." statements may hang, waiting for
    // our connections to close.
    PhabricatorLiskDAO::closeAllConnections();

    execx(
      'php %s destroy --force --namespace %s',
      $this->getStorageBinPath(),
      $this->name);
  }

  private function hasOnlySurplusSchemata() {
    $apis = array();
    try {
      foreach (PhabricatorDatabaseRef::getMasterDatabaseRefs() as $ref) {
        $apis[] = id(new PhabricatorStorageManagementAPI())
          ->setRef($ref)->setUser($ref->getUser())->setHost($ref->getHost())
          ->setPort($ref->getPort())->setPassword($ref->getPass())
          ->setNamespace($this->name);
      }
      $query = id(new PhabricatorConfigSchemaQuery())->setAPIs($apis);
      $actual = $query->loadActualSchemata();
      $expected = $query->loadExpectedSchemata();
      $surplus = false;
      foreach ($query->buildComparisonSchemata($expected, $actual) as $schema) {
        foreach ($schema->getAllIssues() as $issue) {
          if ($issue === PhabricatorConfigStorageSchema::ISSUE_SURPLUS ||
              $issue === PhabricatorConfigStorageSchema::ISSUE_SURPLUSKEY) {
            $surplus = true;
          } else if ($issue !== PhabricatorConfigStorageSchema::ISSUE_SUBWARN &&
                     $issue !== PhabricatorConfigStorageSchema::ISSUE_SUBFAIL) {
            return false;
          }
        }
      }
      return $surplus;
    } finally {
      foreach ($apis as $api) { PhabricatorLiskDAO::popStorageNamespace(); }
      PhabricatorLiskDAO::closeAllConnections();
    }
  }

  private function getStorageBinPath() {
    $root = dirname(phutil_get_library_root('phabricator'));
    return $root.'/scripts/sql/manage_storage.php';
  }

}
