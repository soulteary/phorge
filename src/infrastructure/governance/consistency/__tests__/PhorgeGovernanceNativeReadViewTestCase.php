<?php

final class PhorgeGovernanceNativeReadViewTestCase extends PhutilTestCase {

  public function testBridgeOnlyAllowsFixedReadOnlySqlShapes() {
    foreach (array("SELECT * FROM task WHERE phid IN ('PHID-TASK-synthetic')",
      "SELECT COUNT(*) FROM edge WHERE (type = 60 AND dst = 'user')",
      "SELECT 'SLEEP(1); @x INTO OUTFILE' AS title",
      'SELECT `value` FROM `config_entry` WHERE `id` = 1') as $query) {
      $this->assertTrue(PhorgeGovernanceSnapshotConnection::isSupportedSelect($query));
    }
    foreach (array('UPDATE task SET id=1', 'SELECT SLEEP(1)',
      "SELECT GET_LOCK('x', 1)", "SELECT LOAD_FILE('/fixture')",
      "SELECT 1 INTO OUTFILE '/fixture'", 'SELECT * FROM task FOR UPDATE',
      'SELECT * FROM task LOCK IN SHARE MODE', 'SELECT @secret',
      'SELECT @@version', 'SELECT 1; SELECT 2', 'SELECT 1 /* comment */',
      'SELECT 1 -- comment', 'SELECT arbitrary_function()',
      'SELECT 1 UNION SELECT 2', "SELECT 'unterminated") as $query) {
      $this->assertFalse(PhorgeGovernanceSnapshotConnection::isSupportedSelect($query));
    }
  }

  public function testBridgeDoesNotExposePublicConnectionConstruction() {
    $class = new ReflectionClass(PhorgeGovernanceSnapshotConnection::class);
    $this->assertTrue($class->getConstructor()->isPrivate());
    $this->assertFalse($class->hasMethod('getConnection'));
    $this->assertFalse($class->hasMethod('getRawConnection'));
    $this->assertFalse($class->isCloneable());
    $scope = new ReflectionClass(PhorgeGovernanceNativeReadView::class);
    $this->assertFalse($scope->isCloneable());
    $this->assertEqual(array('scope' => 'isolated-owned-read-only', 'closed' => false),
      $scope->newInstanceWithoutConstructor()->__debugInfo());
  }

  public function testReadViewInvalidInputCannotClaimExecutionFreshness() {
    $result = PhorgeGovernanceNativeReadView::observe(array(), array(), array());
    $this->assertEqual('blocked', $result['status']);
    foreach (array('same_physical_connection', 'same_consistent_read_view',
      'can_execute', 'execution_atomicity_proved', 'execution_freshness_proved') as $key) {
      $this->assertFalse($result[$key]);
    }
  }
  public function testBridgePinsSqlModeAndRejectsParserAmbiguities() {
    $this->assertTrue(PhorgeGovernanceSnapshotConnection::supportsSqlMode(''));
    $this->assertTrue(PhorgeGovernanceSnapshotConnection::supportsSqlMode(
      'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'));
    $this->assertTrue(PhorgeGovernanceSnapshotConnection::supportsSqlMode(
      'STRICT_ALL_TABLES'));
    foreach (array('NO_BACKSLASH_ESCAPES', 'ANSI_QUOTES', 'ANSI',
      'STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES', 'PIPES_AS_CONCAT', null)
      as $mode) {
      $this->assertFalse(PhorgeGovernanceSnapshotConnection::supportsSqlMode($mode));
    }
    $malicious = <<<'SQL'
SELECT 'abc\' INTO OUTFILE '/fixture'
SQL;
    foreach (array($malicious, 'SELECT "GET_LOCK(1,1)"',
      'SELECT COUNT(*) FROM task; COMMIT', 'SELECT 1 /*!50000 INTO OUTFILE */',
      'SELECT GET_LOCK/**/(1,1)') as $query) {
      $this->assertFalse(PhorgeGovernanceSnapshotConnection::isSupportedSelect($query));
    }
  }

  public function testNativeReadQueryProfileRejectsResultAmplification() {
    foreach (array('SELECT task.* FROM `maniphest_task` task WHERE task.id=1',
      "SELECT `user`.* FROM `user` `user` WHERE `user`.`phid` IN ('fixture')",
      'SELECT src, dst, type FROM edge WHERE type IN (13,14,60)',
      'SELECT COUNT(*) FROM edge WHERE type=60',
      'SELECT DISTINCT project.* FROM project project JOIN edge e ON e.src=project.phid AND e.type=60')
      as $query) {
      $this->assertTrue(is_array(PhorgeGovernanceSnapshotConnection::profileTables($query)));
    }
    $database = 'governance_native_'.str_repeat('a',16).'_project';
    $this->assertEqual(array('project'),
      PhorgeGovernanceSnapshotConnection::profileTables(
        'SELECT * FROM `'.$database.'`.`project` WHERE projectPath IN (\'path\')',
        $database));
    $this->assertEqual(null, PhorgeGovernanceSnapshotConnection::profileTables(
      'SELECT * FROM `'.$database.'`.`project`',
      'governance_native_'.str_repeat('b',16).'_project'));
    foreach (array('SELECT CONCAT(description,description) FROM maniphest_task',
      'SELECT description,description AS duplicate FROM maniphest_task',
      'SELECT * FROM user, maniphest_task',
      'SELECT DISTINCT user.* FROM user CROSS JOIN edge e',
      'SELECT DISTINCT project.* FROM project project JOIN edge e ON 1=1', 'SELECT * FROM user JOIN edge ON 1=1',
      'SELECT task.* FROM fixture.maniphest_task task',
      'SELECT task.* FROM fixture . maniphest_task task',
      'SELECT (SELECT description FROM maniphest_task) FROM user',
      'SELECT CAST(description AS CHAR(100000000)) FROM maniphest_task') as $query) {
      $this->assertEqual(null, PhorgeGovernanceSnapshotConnection::profileTables($query));
    }
  }

  public function testNativeRuntimeLibrariesRootsAndMapsFailClosed() {
    $root = dirname(__DIR__, 5);
    $libraries = array('arcanist', 'phorge');
    $roots = array('arcanist' => $root.'/support/runtime/src', 'phorge' => $root.'/src');
    $maps = array('arcanist' => array('class' => array()),
      'phorge' => array('class' => array('Synthetic' => 'fixture.php')));
    $valid = PhorgeGovernanceNativeRuntimeProfile::validate(
      $libraries, $roots, $maps, $maps);
    $this->assertTrue($valid['canonical_roots_verified']);
    $this->assertTrue($valid['library_maps_unextended']);
    $this->assertFalse(str_contains(json_encode($valid), $root));
    $cases = array(
      array(array('arcanist', 'phorge', 'foreign'), $roots, $maps, $maps, array()),
      array(array('phorge'), $roots, $maps, $maps, array()),
      array($libraries, array('arcanist' => $root.'/src', 'phorge' => $root.'/src'),
        $maps, $maps, array()),
      array($libraries, $roots, $maps,
        array('arcanist' => $maps['arcanist'], 'phorge' => array()), array()),
      array($libraries, $roots, $maps, $maps, array('phorge' => array())),
    );
    foreach ($cases as $case) {
      $caught = false;
      try {
        PhorgeGovernanceNativeRuntimeProfile::validate(...$case);
      } catch (RuntimeException $error) {
        $caught = true;
        $this->assertFalse(str_contains($error->getMessage(), $root));
      }
      $this->assertTrue($caught);
    }
  }

  public function testCleanupOwnershipUsesIdentityNotConnectionKey() {
    $owned = new stdClass();
    $foreign = new stdClass();
    $new_foreign = new stdClass();
    $method = new ReflectionMethod(PhorgeGovernanceNativeReadView::class, 'foreignPool');
    $kept = $method->invoke(null,
      array('replaced' => $foreign, 'new-key' => $new_foreign,
        'owned-moved' => $owned), array('replaced' => $owned));
    $this->assertEqual(array('replaced', 'new-key'), array_keys($kept));
    $this->assertTrue($kept['replaced'] === $foreign);
    $this->assertTrue($kept['new-key'] === $new_foreign);
  }

}
