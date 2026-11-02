<?php

final class PhorgeGovernanceNativeSourceReaderTestCase extends PhutilTestCase {

  private function newConfig() {
    return array(
      'host' => '127.0.0.1', 'port' => 1,
      'namespace' => 'governance_native_aaaaaaaaaaaaaaaa',
      'user' => 'synthetic-read-principal', 'password' => 'synthetic-only',
    );
  }

  public function testOnlyExplicitIsolatedNativeNamespaceAndInputsAccepted() {
    $valid = PhorgeGovernanceNativeSourceReader::validateConfig($this->newConfig());
    $this->assertEqual(64, $valid['row_limit']);
    $this->assertEqual(256, $valid['edge_limit']);
    foreach (array(
      array('host' => 'database'),
      array('namespace' => 'application'),
      array('namespace' => 'governance_native_aaaaaaaaaaaaaaaa;SELECT'),
      array('port' => '3306'), array('port' => 0),
      array('read_only' => true), array('connection' => new stdClass()),
    ) as $change) {
      $caught = false;
      try {
        PhorgeGovernanceNativeSourceReader::validateConfig(
          array_merge($this->newConfig(), $change));
      } catch (InvalidArgumentException $error) {
        $caught = true;
      }
      $this->assertTrue($caught);
    }
  }

  public function testBudgetsMayOnlyBeReduced() {
    foreach (array('row_limit' => 64, 'edge_limit' => 256,
      'byte_limit' => 262144, 'seconds' => 5) as $key => $maximum) {
      foreach (array(0, $maximum + 1, (string)$maximum, true) as $value) {
        $caught = false;
        try {
          PhorgeGovernanceNativeSourceReader::validateConfig(
            $this->newConfig() + array($key => $value));
        } catch (InvalidArgumentException $error) {
          $caught = true;
        }
        $this->assertTrue($caught);
      }
    }
    $reduced = PhorgeGovernanceNativeSourceReader::validateConfig(
      $this->newConfig() + array('row_limit' => 1, 'edge_limit' => 2,
        'byte_limit' => 1024, 'seconds' => 1));
    $this->assertEqual(1, $reduced['row_limit']);
  }

  public function testConfigurationReferencesAreDetached() {
    $namespace = 'governance_native_aaaaaaaaaaaaaaaa';
    $limit = 4;
    $config = $this->newConfig();
    $config['namespace'] = &$namespace;
    $config['row_limit'] = &$limit;
    $copy = PhorgeGovernanceNativeSourceReader::validateConfig($config);
    $namespace = 'changed';
    $limit = 1000000;
    $this->assertEqual('governance_native_aaaaaaaaaaaaaaaa', $copy['namespace']);
    $this->assertEqual(4, $copy['row_limit']);
  }

  public function testTablesAreNativeSourcesNotSyntheticEpochs() {
    $tables = PhorgeGovernanceNativeSourceReader::getNativeTableContract();
    $this->assertEqual(11, count($tables));
    $this->assertTrue(isset($tables['user.'.PhabricatorUser::SESSION_TABLE]));
    foreach (array('project.project' => 'viewPolicy',
      'project.edge' => 'dst', 'user.edge' => 'src',
      'maniphest.maniphest_task' => 'ownerPHID',
      'phriction.phriction_document' => 'contentPHID',
      'policy.policy' => 'rules', 'policy.policy_namedpolicy' => 'effectivePolicy',
      'spaces.spaces_namespace' => 'isArchived',
      'config.config_entry' => 'configKey') as $table => $column) {
      $this->assertTrue(in_array($column, $tables[$table], true));
    }
    foreach (array('projectPath', 'projectDepth', 'projectPathKey') as $column) {
      $this->assertTrue(in_array($column, $tables['project.project'], true));
    }
    $this->assertFalse(isset($tables['readset_source']));
    $this->assertFalse(isset($tables['fixture_user']));
  }

  public function testDeploymentSelectorsAreRefusedBeforeConnection() {
    $variables = array('PHORGE_GOVERNANCE_ISOLATED_TEST', 'PHABRICATOR_ENV',
      'PHORGE_DEPLOYMENT_CONFIG', 'PHORGE_CONTROL_PLANE');
    $old = array();
    foreach ($variables as $name) {
      $old[$name] = getenv($name);
      putenv($name);
    }
    try {
      putenv('PHORGE_GOVERNANCE_ISOLATED_TEST=1');
      foreach (array('PHABRICATOR_ENV', 'PHORGE_DEPLOYMENT_CONFIG',
        'PHORGE_CONTROL_PLANE') as $name) {
        // Even a falsey selector is explicit deployment input.
        putenv($name.'=0');
        $caught = false;
        try {
          PhorgeGovernanceNativeSourceReader::newFixture($this->newConfig());
        } catch (RuntimeException $error) {
          $caught = $error->getMessage() ===
            'native_source_deployment_scope_refused';
        }
        $this->assertTrue($caught);
        putenv($name);
      }
      putenv('PHORGE_GOVERNANCE_ISOLATED_TEST=0');
      $caught = false;
      try {
        PhorgeGovernanceNativeSourceReader::newFixture($this->newConfig());
      } catch (RuntimeException $error) {
        $caught = $error->getMessage() === 'native_source_scope_refused';
      }
      $this->assertTrue($caught);
    } finally {
      foreach ($old as $name => $value) {
        putenv($value === false ? $name : $name.'='.$value);
      }
    }
  }

  private function newSnapshot() {
    return new PhorgeGovernanceReadSetSnapshot(array(
      'operation_id' => 'synthetic-operation', 'actor' => 'actor',
      'executor' => 'executor', 'approver' => 'approver', 'target' => 'target',
      'operation' => 'project.description', 'object_class' => 'PhabricatorProject',
      'parameters_digest' => hash('sha256', 'synthetic-parameters'),
      'actor_session' => 'a', 'executor_session' => 'e', 'approver_session' => 'p',
    ), array());
  }

  public function testCaptureValuesNeverCertifyClosureOrExecution() {
    $summary = array('status' => 'native-observed-blocked',
      'native_closure_complete' => false, 'can_execute' => false,
      'execution_atomicity_proved' => false);
    foreach (array('native_closure_complete', 'can_execute',
      'execution_atomicity_proved') as $key) {
      $forged = $summary;
      $forged[$key] = true;
      $caught = false;
      try {
        new PhorgeGovernanceNativeSourceCapture($this->newSnapshot(), $forged);
      } catch (InvalidArgumentException $error) {
        $caught = true;
      }
      $this->assertTrue($caught);
    }
    $capture = new PhorgeGovernanceNativeSourceCapture($this->newSnapshot(), $summary);
    $copy = $capture->getSummary();
    $copy['can_execute'] = true;
    $this->assertFalse($capture->getSummary()['can_execute']);
    $this->assertEqual(array(), $capture->getSources());
  }

  public function testStoredNativeRuleSubjectsMustBePresentAndTyped() {
    // Pure parser/closure guard fixtures, not native SQL proof.
    $method = new ReflectionMethod(PhorgeGovernanceNativeSourceReader::class,
      'ruleSubjectReason');
    $user = 'PHID-USER-'.str_repeat('a', 20);
    $project = 'PHID-PROJ-'.str_repeat('b', 20);
    $accounts = array($user => array());
    $projects = array($project => array());
    foreach (array(PhabricatorUsersPolicyRule::class => $user,
      PhabricatorProjectsPolicyRule::class => $project,
      PhabricatorProjectsAllPolicyRule::class => $project) as $class => $subject) {
      foreach (array(array(), array($subject)) as $value) {
        $this->assertEqual(null, $method->invoke(null,
          array('rule' => $class, 'value' => $value), $accounts, $projects));
      }
      $this->assertEqual('missing_native_policy_rule_subject',
        $method->invoke(null, array('rule' => $class, 'value' => array($subject)),
          array(), array()));
      foreach (array(null, $subject, array('key' => $subject), array(true),
        array($class === PhabricatorUsersPolicyRule::class ? $project : $user))
        as $value) {
        $this->assertEqual('malformed_native_policy_rule_subjects',
          $method->invoke(null, array('rule' => $class, 'value' => $value),
            $accounts, $projects));
      }
      $this->assertEqual('native_policy_rule_subject_budget',
        $method->invoke(null, array('rule' => $class,
          'value' => array_fill(0, 17, $subject)), $accounts, $projects));
    }
    foreach (array(null, array(), '') as $empty) {
      $this->assertEqual(null, $method->invoke(null,
        array('rule' => PhabricatorProjectMembersPolicyRule::class,
          'value' => $empty), $accounts, $projects));
    }
    $this->assertEqual('malformed_native_policy_rule_subjects',
      $method->invoke(null, array('rule' => PhabricatorProjectMembersPolicyRule::class,
        'value' => array($project)), $accounts, $projects));
  }

}
