<?php

final class PhabricatorConfigRetiredSchemaTestCase extends PhabricatorTestCase {

  private function newServer() {
    return id(new PhabricatorConfigServerSchema())
      ->setRef(id(new PhabricatorDatabaseRef())->setHost('db1')->setPort(3306));
  }

  private function newDatabase($application) {
    return id(new PhabricatorConfigDatabaseSchema())
      ->setName(PhabricatorLiskDAO::getDefaultStorageNamespace().'_'.$application)
      ->setCharacterSet('utf8mb4')
      ->setCollation('utf8mb4_bin');
  }

  private function newTable($name) {
    return id(new PhabricatorConfigTableSchema())
      ->setName($name)
      ->setCollation('utf8mb4_bin')
      ->setEngine('InnoDB')
      ->addColumn(
        id(new PhabricatorConfigColumnSchema())
          ->setName('id')
          ->setColumnType('int(10) unsigned')
          ->setNullable(false)
          ->setAutoIncrement(false))
      ->addKey(
        id(new PhabricatorConfigKeySchema())
          ->setName('PRIMARY')
          ->setColumnNames(array('id'))
          ->setUnique(true)
          ->setIndexType('BTREE'));
  }

  private function compare($expect, $actual) {
    $key = 'db1:3306';
    $result = id(new PhabricatorConfigSchemaQuery())->buildComparisonSchemata(
      array($key => $expect), array($key => $actual));
    return $result[$key];
  }

  public function testRetiredManifestKeepsCurrentRequirements() {
    $tables = PhabricatorConfigRetiredSchema::getRetiredTables();
    $this->assertEqual(1, PhabricatorConfigRetiredSchema::VERSION);
    $this->assertEqual(117, count(array_mergev(array_values($tables))));
    foreach (array(
      'edge', 'edgedata', 'differential_changeset_parse_cache',
      'differential_revisionhash', 'differential_changeset',
      'differential_diff', 'differential_diffproperty', 'differential_hunk',
      'differential_viewstate', 'gorge_gc_control',
    ) as $name) {
      $this->assertFalse(in_array($name, $tables['differential'], true));
    }
    foreach (array('harbormaster_object', 'harbormaster_scratchtable') as $name) {
      $this->assertFalse(in_array($name, $tables['harbormaster'], true));
    }
    $this->assertFalse(PhabricatorConfigRetiredSchema::isRetiredTable(
      'another_repository', 'repository', 'current'));
  }

  public function testSixRetiredDatabasesStayBrowsableWithoutFailures() {
    $expect = $this->newServer();
    $actual = $this->newServer();
    $manifest = PhabricatorConfigRetiredSchema::getRetiredTables();
    foreach (array('audit', 'diviner', 'drydock', 'owners', 'paste',
      'repository') as $application) {
      $database = $this->newDatabase($application);
      foreach ($manifest[$application] as $table) {
        $database->addTable($this->newTable($table));
      }
      $actual->addDatabase($database);
    }
    $comp = $this->compare($expect, $actual);
    $this->assertEqual(array(), $comp->getAllIssues());
    $this->assertEqual(83, $comp->getRetainedTableCount());
    foreach ($actual->getDatabases() as $name => $actual_database) {
      $database = $comp->getDatabase($name);
      $this->assertTrue($database->getIsRetained());
      $this->assertFalse($actual_database->getIsRetained());
      $this->assertEqual(
        array_keys($actual_database->getTables()),
        array_keys($database->getTables()));
      foreach ($database->getTables() as $table) {
        $this->assertTrue($table->getIsRetained());
        $this->assertTrue($table->getColumn('id')->getIsRetained());
        $this->assertTrue($table->getKey('PRIMARY')->getIsRetained());
        $actual_table = $actual_database->getTable($table->getName());
        $this->assertEqual($actual_table,
          $actual_table->getKey('PRIMARY')->getTable());
      }
    }
  }

  public function testRetentionUsesTheActiveManagementNamespace() {
    PhabricatorLiskDAO::pushStorageNamespace('isolated_fixture');
    try {
      $database = $this->newDatabase('audit')
        ->setName('isolated_fixture_audit')
        ->addTable($this->newTable('audit_transaction'));
      $comp = $this->compare($this->newServer(),
        $this->newServer()->addDatabase($database));
      $this->assertEqual(array(), $comp->getAllIssues());
      $this->assertEqual(1, $comp->getRetainedTableCount());
    } finally {
      PhabricatorLiskDAO::popStorageNamespace();
    }
  }

  public function testPartialRetirementPreservesLiveTablesAndChecks() {
    $expect = $this->newServer();
    $actual = $this->newServer();
    foreach (array(
      'differential' => array('differential_diff', 'gorge_gc_control'),
      'harbormaster' => array('harbormaster_object', 'harbormaster_scratchtable'),
    ) as $application => $live_tables) {
      $expected = $this->newDatabase($application);
      $observed = $this->newDatabase($application);
      foreach ($live_tables as $name) {
        $expected->addTable($this->newTable($name));
        $observed->addTable($this->newTable($name));
      }
      foreach (PhabricatorConfigRetiredSchema::getRetiredTables()[$application]
        as $name) {
        $observed->addTable($this->newTable($name));
      }
      $expect->addDatabase($expected);
      $actual->addDatabase($observed);
    }
    $comp = $this->compare($expect, $actual);
    $this->assertEqual(array(), $comp->getAllIssues());
    $this->assertEqual(34, $comp->getRetainedTableCount());
    foreach ($expect->getDatabases() as $name => $expected) {
      $database = $comp->getDatabase($name);
      $this->assertFalse($database->getIsRetained());
      foreach ($expected->getTables() as $table_name => $ignored) {
        $this->assertFalse($database->getTable($table_name)->getIsRetained());
        $actual_table = $actual->getDatabase($name)->getTable($table_name);
        $this->assertEqual($actual_table,
          $actual_table->getKey('PRIMARY')->getTable());
      }
    }

    $name = $this->newDatabase('differential')->getName();
    $actual->getDatabase($name)->getTable('differential_diff')
      ->getColumn('id')->setNullable(true);
    $comp = $this->compare($expect, $actual);
    $column = $comp->getDatabase($name)->getTable('differential_diff')
      ->getColumn('id');
    $this->assertTrue($column->hasIssue(
      PhabricatorConfigStorageSchema::ISSUE_NULLABLE));
    $this->assertEqual(PhabricatorConfigStorageSchema::STATUS_FAIL,
      $comp->getStatus());
  }

  public function testUnknownSurplusStillFailsInRetiredDatabase() {
    $expect = $this->newServer();
    $actual = $this->newServer();
    $database = $this->newDatabase('repository')
      ->addTable($this->newTable('repository'))
      ->addTable($this->newTable('repository_unreviewed'));
    $actual->addDatabase($database);
    $comp = $this->compare($expect, $actual);
    $database = $comp->getDatabase($database->getName());
    $this->assertEqual(1, $database->getRetainedTableCount());
    $this->assertEqual(array(),
      $database->getTable('repository')->getAllIssues());
    $unknown = $database->getTable('repository_unreviewed');
    $this->assertFalse($unknown->getIsRetained());
    $this->assertTrue($unknown->hasIssue(
      PhabricatorConfigStorageSchema::ISSUE_SURPLUS));
    $this->assertEqual(PhabricatorConfigStorageSchema::STATUS_FAIL,
      $comp->getStatus());

    // The CLI adjustment workflow still treats unknown surplus as an error.
    $method = new ReflectionMethod(
      PhabricatorStorageManagementWorkflow::class, 'findErrors');
    $workflow = new PhabricatorStorageManagementUpgradeWorkflow();
    $this->assertEqual(array(),
      $method->invoke($workflow, $database->getTable('repository')));
    $this->assertEqual(array(PhabricatorConfigStorageSchema::ISSUE_SURPLUS),
      $method->invoke($workflow, $unknown));

    $other = $this->newServer()->addDatabase(
      $this->newDatabase('unreviewed')->addTable($this->newTable('repository')));
    $this->assertEqual(PhabricatorConfigStorageSchema::STATUS_FAIL,
      $this->compare($expect, $other)->getStatus());
  }

  public function testActiveSurplusColumnsAndIndexesRemainIssues() {
    $expect = $this->newServer()->addDatabase(
      $this->newDatabase('differential')
        ->addTable($this->newTable('differential_diff')));
    $actual_table = $this->newTable('differential_diff')
      ->addColumn(id(new PhabricatorConfigColumnSchema())->setName('unexpected'))
      ->addKey(id(new PhabricatorConfigKeySchema())->setName('unexpected_key'));
    $actual = $this->newServer()->addDatabase(
      $this->newDatabase('differential')->addTable($actual_table));
    $database_name = $this->newDatabase('differential')->getName();
    $table = $this->compare($expect, $actual)->getDatabase($database_name)
      ->getTable('differential_diff');
    $this->assertTrue($table->getColumn('unexpected')->hasIssue(
      PhabricatorConfigStorageSchema::ISSUE_SURPLUS));
    $this->assertTrue($table->getKey('unexpected_key')->hasIssue(
      PhabricatorConfigStorageSchema::ISSUE_SURPLUSKEY));
    $this->assertFalse($table->getColumn('unexpected')->getIsRetained());

    $missing = $this->compare($expect, $this->newServer());
    $this->assertTrue($missing->getDatabase($database_name)
      ->getTable('differential_diff')->hasIssue(
        PhabricatorConfigStorageSchema::ISSUE_MISSING));
  }

  public function testCurrentSpecTakesPrecedenceAndAccessDeniedIsNotRetained() {
    $expect = $this->newServer()->addDatabase($this->newDatabase('repository')
      ->addTable($this->newTable('repository')));
    $actual_database = $this->newDatabase('repository')
      ->addTable($this->newTable('repository'));
    $actual_database->getTable('repository')->getColumn('id')->setNullable(true);
    $comp = $this->compare($expect,
      $this->newServer()->addDatabase($actual_database));
    $this->assertEqual(0, $comp->getRetainedTableCount());
    $this->assertEqual(PhabricatorConfigStorageSchema::STATUS_FAIL,
      $comp->getStatus());

    $denied = $this->newDatabase('repository')->setAccessDenied(true);
    $comp = $this->compare($expect, $this->newServer()->addDatabase($denied));
    $database = $comp->getDatabase($denied->getName());
    $this->assertFalse($database->getIsRetained());
    $this->assertTrue($database->hasIssue(
      PhabricatorConfigStorageSchema::ISSUE_ACCESSDENIED));
    $this->assertTrue((bool)PhabricatorConfigStorageSchema::getIssueDescription(
      PhabricatorConfigStorageSchema::ISSUE_ACCESSDENIED));
    $comp = $this->compare($this->newServer(),
      $this->newServer()->addDatabase($denied));
    $database = $comp->getDatabase($denied->getName());
    $this->assertFalse($database->getIsRetained());
    $this->assertEqual(array(PhabricatorConfigStorageSchema::ISSUE_ACCESSDENIED),
      array_values($database->getLocalIssues()));
  }

  public function testRetainedDetailsRenderInformationalStatusAtEveryLevel() {
    $actual = $this->newServer()->addDatabase($this->newDatabase('audit')
      ->addTable($this->newTable('audit_transaction')));
    $comp = $this->compare($this->newServer(), $actual);
    $database = head($comp->getDatabases());
    $table = $database->getTable('audit_transaction');
    $controller = id(new PhabricatorConfigDatabaseStatusController())
      ->setRequest(id(new AphrontRequest('localhost', '/config/database/'))
        ->setUser(new PhabricatorUser()));
    $method = new ReflectionMethod($controller, 'buildProperties');
    foreach (array($database, $table, $table->getColumn('id'),
      $table->getKey('PRIMARY')) as $schema) {
      $html = (string)$method->invoke($controller, array(),
        $schema->getIssues(), $schema);
      $this->assertTrue(strpos($html, 'fa-archive') !== false);
      $this->assertFalse(strpos($html, 'fa-times-circle') !== false);
    }
    $html = (string)$method->invoke($controller, array(),
      array(PhabricatorConfigStorageSchema::ISSUE_ACCESSDENIED));
    $this->assertTrue(strpos($html, 'fa-times-circle') !== false);
  }

}
