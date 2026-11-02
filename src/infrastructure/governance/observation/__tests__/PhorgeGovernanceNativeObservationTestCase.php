<?php

final class PhorgeGovernanceNativeObservationTestCase extends PhutilTestCase {

  private $oldOptIn;
  private $oldSelectors;

  protected function willRunOneTest($name) {
    $this->oldOptIn = getenv('PHORGE_GOVERNANCE_ISOLATED_TEST');
    $this->oldSelectors = array();
    foreach (array('PHABRICATOR_ENV', 'PHORGE_DEPLOYMENT_CONFIG',
      'PHORGE_CONTROL_PLANE') as $selector) {
      $this->oldSelectors[$selector] = getenv($selector);
    }
    putenv('PHORGE_GOVERNANCE_ISOLATED_TEST=1');
  }

  protected function didRunOneTest($name) {
    $values = $this->oldSelectors;
    $values['PHORGE_GOVERNANCE_ISOLATED_TEST'] = $this->oldOptIn;
    foreach ($values as $key => $value) {
      putenv($value === false ? $key : $key.'='.$value);
    }
  }

  private function newViewer() {
    return (new PhabricatorUser())
      ->setPHID('PHID-USER-aaaaaaaaaaaaaaaaaaaa')
      ->setIsApproved(1)
      ->setIsDisabled(0)
      ->setIsSystemAgent(0);
  }

  private function newTask($owner = null) {
    return (new ManiphestTask())
      ->setID(1)
      ->setPHID('PHID-TASK-bbbbbbbbbbbbbbbbbbbb')
      ->setOwnerPHID($owner);
  }

  private function newWiki() {
    return (new PhrictionDocument())
      ->setID(1)
      ->setPHID('PHID-WIKI-cccccccccccccccccccc');
  }

  public function testFactoryAndRevokedScopeAreBlocked() {
    putenv('PHORGE_GOVERNANCE_ISOLATED_TEST');
    $caught = null;
    try {
      PhorgeGovernanceNativeObservationVerifier::newIsolated();
    } catch (LogicException $ex) {
      $caught = $ex;
    }
    $this->assertTrue($caught instanceof LogicException);
    putenv('PHORGE_GOVERNANCE_ISOLATED_TEST=1');
    $verifier = PhorgeGovernanceNativeObservationVerifier::newIsolated();
    putenv('PHORGE_GOVERNANCE_ISOLATED_TEST');
    $result = $verifier->observeWikiLocalExpansion($this->newWiki(), array());
    $this->assertEqual('isolated_scope_required', $result['reason']);
    $this->assertFalse($result['can_execute']);
  }

  public function testDeploymentEnvironmentIsRefused() {
    foreach (array('PHABRICATOR_ENV', 'PHORGE_DEPLOYMENT_CONFIG',
      'PHORGE_CONTROL_PLANE') as $selector) {
      foreach (array('0', 'synthetic-unapproved-environment') as $value) {
        putenv($selector.'='.$value);
        $caught = null;
        try {
          PhorgeGovernanceNativeObservationVerifier::newIsolated();
        } catch (LogicException $ex) {
          $caught = $ex;
        }
        $this->assertTrue($caught instanceof LogicException);
        putenv($selector);
      }
    }
  }

  public function testProjectDescriptionUsesNativeLocalMethodOnly() {
    $project = (new PhabricatorProject())->setID(1);
    $transaction = (new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_CUSTOMFIELD)
      ->setMetadataValue('customfield:key', 'std:project:internal:description')
      ->setNewValue('synthetic-description');
    $result = PhorgeGovernanceNativeObservationVerifier::newIsolated()
      ->observeProjectLocalExpansion($project, array($transaction));
    $this->assertEqual('observed-native-local-expansion', $result['status']);
    $this->assertEqual(
      'PhabricatorProjectTransactionEditor::expandTransactions', $result['method']);
    $this->assertEqual(1, count($result['transactions']));
    $this->assertEqual(hash('sha256', json_encode('synthetic-description')),
      $result['transactions'][0]['value_digest']);
    $this->assertFalse($result['full_expansion_verified']);
    $this->assertFalse($result['can_execute']);
  }

  public function testTaskReassignmentActuallyAddsOldOwnerSubscription() {
    $owner = 'PHID-USER-dddddddddddddddddddd';
    $task = $this->newTask($owner);
    $transaction = (new ManiphestTransaction())
      ->setTransactionType(ManiphestTaskOwnerTransaction::TRANSACTIONTYPE)
      ->setNewValue('PHID-USER-eeeeeeeeeeeeeeeeeeee');
    $result = PhorgeGovernanceNativeObservationVerifier::newIsolated()
      ->observeTaskLocalExpansion(
        $task, $this->newViewer(), array($transaction));
    $this->assertEqual('observed-native-local-expansion', $result['status']);
    $this->assertEqual(2, count($result['transactions']));
    $this->assertEqual(PhabricatorTransactions::TYPE_SUBSCRIBERS,
      $result['transactions'][1]['transaction_type']);
    $this->assertEqual(hash('sha256', json_encode(
      array('+' => array($owner => $owner)))),
      $result['transactions'][1]['value_digest']);
    $this->assertTrue($result['transactions'][1]['ignore_on_no_effect']);
    $this->assertEqual($owner, $task->getOwnerPHID());
    $this->assertEqual('PHID-USER-eeeeeeeeeeeeeeeeeeee',
      $transaction->getNewValue());
    $this->assertFalse($result['risk_complete']);
  }

  public function testTaskSameOwnerDoesNotAddSubscriber() {
    $owner = 'PHID-USER-dddddddddddddddddddd';
    $transaction = (new ManiphestTransaction())
      ->setTransactionType(ManiphestTaskOwnerTransaction::TRANSACTIONTYPE)
      ->setNewValue($owner);
    $result = PhorgeGovernanceNativeObservationVerifier::newIsolated()
      ->observeTaskLocalExpansion(
        $this->newTask($owner), $this->newViewer(), array($transaction));
    $this->assertEqual(1, count($result['transactions']));
    $this->assertFalse($result['can_execute']);
  }

  public function testTaskTextHasOnlyObservedSingleItemExpansion() {
    foreach (array(ManiphestTaskTitleTransaction::TRANSACTIONTYPE,
      ManiphestTaskDescriptionTransaction::TRANSACTIONTYPE) as $type) {
      $transaction = (new ManiphestTransaction())
        ->setTransactionType($type)->setNewValue('synthetic-text');
      $result = PhorgeGovernanceNativeObservationVerifier::newIsolated()
        ->observeTaskLocalExpansion(
          $this->newTask(), $this->newViewer(), array($transaction));
      $this->assertEqual('observed-native-local-expansion', $result['status']);
      $this->assertEqual(1, count($result['transactions']));
      $this->assertFalse($result['full_expansion_verified']);
    }
  }

  public function testTaskStatusAndBoardFullClosuresRemainUnsupported() {
    foreach (array(ManiphestTaskStatusTransaction::TRANSACTIONTYPE,
      ManiphestTaskColumnTransaction::TRANSACTIONTYPE) as $type) {
      $transaction = (new ManiphestTransaction())
        ->setTransactionType($type)->setNewValue('synthetic-value');
      $result = PhorgeGovernanceNativeObservationVerifier::newIsolated()
        ->observeTaskLocalExpansion(
          $this->newTask(), $this->newViewer(), array($transaction));
      $this->assertEqual('blocked', $result['status']);
      $this->assertEqual('unsupported_local_closure', $result['reason']);
    }
  }

  public function testWikiEmptyBodyActuallyExpandsToDelete() {
    $transaction = (new PhrictionTransaction())
      ->setTransactionType(PhrictionDocumentContentTransaction::TRANSACTIONTYPE)
      ->setNewValue('');
    $result = PhorgeGovernanceNativeObservationVerifier::newIsolated()
      ->observeWikiLocalExpansion($this->newWiki(), array($transaction));
    $this->assertEqual('observed-native-local-expansion', $result['status']);
    $this->assertEqual(2, count($result['transactions']));
    $this->assertEqual(PhrictionDocumentDeleteTransaction::TRANSACTIONTYPE,
      $result['transactions'][1]['transaction_type']);
    $this->assertTrue($result['transactions'][1]['content_delete']);
    $this->assertFalse($result['full_expansion_verified']);
    $this->assertFalse($result['can_execute']);
  }

  public function testWikiNonemptyBodyDoesNotClaimPublicationValidated() {
    $transaction = (new PhrictionTransaction())
      ->setTransactionType(PhrictionDocumentContentTransaction::TRANSACTIONTYPE)
      ->setNewValue('synthetic-wiki-body');
    $result = PhorgeGovernanceNativeObservationVerifier::newIsolated()
      ->observeWikiLocalExpansion($this->newWiki(), array($transaction));
    $this->assertEqual(1, count($result['transactions']));
    $this->assertFalse($result['risk_complete']);
  }

  public function testWikiMoveActuallyAddsDestinationPolicies() {
    $destination = $this->newWiki()->setID(2)
      ->setViewPolicy(PhabricatorPolicies::POLICY_USER)
      ->setEditPolicy(PhabricatorPolicies::POLICY_ADMIN);
    $transaction = (new PhrictionTransaction())
      ->setTransactionType(PhrictionDocumentMoveToTransaction::TRANSACTIONTYPE)
      ->setNewValue($destination);
    $result = PhorgeGovernanceNativeObservationVerifier::newIsolated()
      ->observeWikiLocalExpansion($this->newWiki(), array($transaction));
    $this->assertEqual('observed-native-local-expansion', $result['status']);
    $this->assertEqual(3, count($result['transactions']));
    $this->assertEqual(PhabricatorTransactions::TYPE_VIEW_POLICY,
      $result['transactions'][1]['transaction_type']);
    $this->assertEqual(PhabricatorTransactions::TYPE_EDIT_POLICY,
      $result['transactions'][2]['transaction_type']);
    $this->assertEqual(hash('sha256', json_encode(
      PhabricatorPolicies::POLICY_ADMIN)),
      $result['transactions'][2]['value_digest']);
    $this->assertEqual(PhabricatorPolicies::POLICY_ADMIN,
      $destination->getEditPolicy());
  }

  public function testWikiMoveCannotInjectAnArbitraryObject() {
    $transaction = (new PhrictionTransaction())
      ->setTransactionType(PhrictionDocumentMoveToTransaction::TRANSACTIONTYPE)
      ->setNewValue(new stdClass());
    $result = PhorgeGovernanceNativeObservationVerifier::newIsolated()
      ->observeWikiLocalExpansion($this->newWiki(), array($transaction));
    $this->assertEqual('unsupported_move_destination', $result['reason']);
  }

  public function testCreationWrongEnvelopeAndUnknownBatchBlock() {
    $transaction = (new PhrictionTransaction())
      ->setTransactionType(PhrictionDocumentTitleTransaction::TRANSACTIONTYPE)
      ->setNewValue('synthetic-title');
    $verifier = PhorgeGovernanceNativeObservationVerifier::newIsolated();
    $this->assertEqual('unsupported_existing_object_or_batch',
      $verifier->observeWikiLocalExpansion(
        new PhrictionDocument(), array($transaction))['reason']);
    $this->assertEqual('unsupported_transaction_envelope',
      $verifier->observeWikiLocalExpansion(
        $this->newWiki(), array(new ManiphestTransaction()))['reason']);
    $unknown = (new PhrictionTransaction())
      ->setTransactionType('synthetic-unknown')->setNewValue('value');
    $result = $verifier->observeWikiLocalExpansion(
      $this->newWiki(), array($transaction, $unknown));
    $this->assertEqual('blocked', $result['status']);
    $this->assertFalse(isset($result['transactions']));
  }

  public function testNonCurrentTransactionTargetIsBlocked() {
    $transaction = (new ManiphestTransaction())
      ->setTransactionType(ManiphestTaskTitleTransaction::TRANSACTIONTYPE)
      ->setObjectPHID('PHID-TASK-ffffffffffffffffffff')
      ->setNewValue('synthetic');
    $result = PhorgeGovernanceNativeObservationVerifier::newIsolated()
      ->observeTaskLocalExpansion(
        $this->newTask(), $this->newViewer(), array($transaction));
    $this->assertEqual('transaction_target_mismatch', $result['reason']);
  }

  public function testCapabilityObservationRequiresObjectIdentity() {
    $result = PhorgeGovernanceNativeObservationVerifier::newIsolated()
      ->observeCapabilities($this->newViewer(), new ManiphestTask(),
        array(PhabricatorPolicyCapability::CAN_EDIT));
    $this->assertEqual('native_object_identity_required', $result['reason']);
    $this->assertFalse($result['can_execute']);
    $wrong = $this->newTask()->setPHID('PHID-PROJ-aaaaaaaaaaaaaaaaaaaa');
    $result = PhorgeGovernanceNativeObservationVerifier::newIsolated()
      ->observeCapabilities($this->newViewer(), $wrong,
        array(PhabricatorPolicyCapability::CAN_EDIT));
    $this->assertEqual('native_object_identity_required', $result['reason']);
  }

  public function testMalformedCapabilityValuesBlockBeforeNativeQueries() {
    $verifier = PhorgeGovernanceNativeObservationVerifier::newIsolated();
    foreach (array(
      array(array('nested')), array(new stdClass()),
      array(PhabricatorPolicyCapability::CAN_EDIT,
        PhabricatorPolicyCapability::CAN_EDIT),
      array('synthetic-unsupported-capability')) as $capabilities) {
      $result = $verifier->observeCapabilities(
        $this->newViewer(), $this->newTask(), $capabilities);
      $this->assertEqual('unsupported_capabilities', $result['reason']);
      $this->assertFalse($result['can_execute']);
    }
  }

  public function testLocalPayloadBudgetsAndUnsupportedValuesBlock() {
    $cycle = array();
    $cycle['self'] = &$cycle;
    $amplified = array_fill(0, 100,
      array_fill(0, 100, str_repeat('x', 100)));
    foreach (array($cycle, $amplified, str_repeat('x', 1048577),
      "invalid-utf8-\xff", new stdClass()) as $value) {
      $transaction = (new ManiphestTransaction())
        ->setTransactionType(ManiphestTaskDescriptionTransaction::TRANSACTIONTYPE)
        ->setNewValue($value);
      $result = PhorgeGovernanceNativeObservationVerifier::newIsolated()
        ->observeTaskLocalExpansion(
          $this->newTask(), $this->newViewer(), array($transaction));
      $this->assertEqual('blocked', $result['status']);
      $this->assertFalse($result['can_execute']);
    }
  }

  public function testWrongPrimitivePayloadShapesBlockBeforeExpansion() {
    $verifier = PhorgeGovernanceNativeObservationVerifier::newIsolated();
    foreach (array(
      array(ManiphestTaskTitleTransaction::TRANSACTIONTYPE, 123),
      array(ManiphestTaskDescriptionTransaction::TRANSACTIONTYPE, array('body')),
      array(ManiphestTaskOwnerTransaction::TRANSACTIONTYPE, array('owner')),
      array(ManiphestTaskOwnerTransaction::TRANSACTIONTYPE, 'not-a-user-phid'))
      as $case) {
      $transaction = (new ManiphestTransaction())
        ->setTransactionType($case[0])->setNewValue($case[1]);
      $result = $verifier->observeTaskLocalExpansion(
        $this->newTask(), $this->newViewer(), array($transaction));
      $this->assertEqual('unsupported_local_value', $result['reason']);
    }
    foreach (array(
      array(PhabricatorTransactions::TYPE_VIEW_POLICY, array('policy')),
      array(PhabricatorProjectParentTransaction::TRANSACTIONTYPE,
        'PHID-USER-aaaaaaaaaaaaaaaaaaaa')) as $case) {
      $transaction = (new PhabricatorProjectTransaction())
        ->setTransactionType($case[0])->setNewValue($case[1]);
      $result = $verifier->observeProjectLocalExpansion(
        (new PhabricatorProject())->setID(1), array($transaction));
      $this->assertEqual('unsupported_local_value', $result['reason']);
    }
    $transaction = (new PhrictionTransaction())
      ->setTransactionType(PhrictionDocumentContentTransaction::TRANSACTIONTYPE)
      ->setNewValue(false);
    $result = $verifier->observeWikiLocalExpansion(
      $this->newWiki(), array($transaction));
    $this->assertEqual('unsupported_local_value', $result['reason']);
    $this->assertFalse($result['can_execute']);
  }

  private function withNativeRouting(array $dictionary, $callback) {
    $old = (new ReflectionClass(PhabricatorEnv::class))->getStaticProperties();
    $stack = new PhabricatorConfigStackSource();
    $stack->pushSource(new PhabricatorConfigDefaultSource());
    $stack->pushSource(new PhabricatorConfigDictionarySource($dictionary));
    (new ReflectionProperty(PhabricatorEnv::class, 'sourceStack'))
      ->setValue(null, $stack);
    (new ReflectionProperty(PhabricatorEnv::class, 'cache'))
      ->setValue(null, array());
    try {
      $callback($stack);
    } finally {
      foreach ($old as $key => $value) {
        (new ReflectionProperty(PhabricatorEnv::class, $key))
          ->setValue(null, $value);
      }
    }
  }

  public function testCapabilityObservationDoesNotTrustAbsentSourceStack() {
    $old = PhabricatorEnv::getConfigSourceStack();
    $property = new ReflectionProperty(PhabricatorEnv::class, 'sourceStack');
    $property->setValue(null, null);
    try {
      $result = PhorgeGovernanceNativeObservationVerifier::newIsolated()
        ->observeCapabilities($this->newViewer(), $this->newTask(),
          array(PhabricatorPolicyCapability::CAN_EDIT));
      $this->assertEqual('native_context_unverified', $result['reason']);
      $this->assertFalse(isset($result['observations']));
    } finally {
      $property->setValue(null, $old);
    }
  }

  public function testInheritedNativePoolsAreRefusedWithoutCleanupOwnership() {
    $verifier = PhorgeGovernanceNativeObservationVerifier::newIsolated();
    foreach (array(
      array(LiskDAO::class, 'connections', array('foreign' => new stdClass())),
      array(PhabricatorLiskDAO::class, 'namespaceStack', array('unowned')))
      as $case) {
      $property = new ReflectionProperty($case[0], $case[1]);
      $old = $property->getValue();
      $property->setValue(null, $case[2]);
      try {
        $result = $verifier->observeCapabilities(
          $this->newViewer(), $this->newTask(),
          array(PhabricatorPolicyCapability::CAN_EDIT));
        $this->assertEqual('native_context_unverified', $result['reason']);
        $this->assertEqual($case[2], $property->getValue());
      } finally {
        $property->setValue(null, $old);
      }
    }
    foreach (array('processIsolationLevel', 'transactionIsolationLevel') as $key) {
      $property = new ReflectionProperty(LiskDAO::class, $key);
      $old = $property->getValue();
      $property->setValue(null, 1);
      try {
        $result = $verifier->observeCapabilities(
          $this->newViewer(), $this->newTask(),
          array(PhabricatorPolicyCapability::CAN_EDIT));
        $this->assertEqual('native_context_unverified', $result['reason']);
        $this->assertEqual(1, $property->getValue());
      } finally {
        $property->setValue(null, $old);
      }
    }
    $forced = $this->newTask()->setForcedConnection(
      new AphrontIsolatedDatabaseConnection(array()));
    $result = $verifier->observeCapabilities(
      $this->newViewer(), $forced,
      array(PhabricatorPolicyCapability::CAN_EDIT));
    $this->assertEqual('native_context_unverified', $result['reason']);
    $result = $verifier->observeCapabilities($this->newViewer(),
      $this->newTask()->setForcedStorageNamespace('unowned'),
      array(PhabricatorPolicyCapability::CAN_EDIT));
    $this->assertEqual('native_context_unverified', $result['reason']);
  }

  public function testRoutingAndCacheDriftRefuseBeforeAnyProbeConnection() {
    // Invalid namespace is a second fail-closed barrier; no test credentials
    // can reach a server even if an earlier routing check regresses.
    $base = array('mysql.host' => '127.0.0.1', 'mysql.port' => 1,
      'mysql.user' => 'synthetic', 'mysql.pass' => 'synthetic',
      'storage.default-namespace' => 'not-an-owned-native-namespace',
      'cluster.databases' => array(), 'gorge.db.uri' => '');
    foreach (array(
      array('cluster.databases' => array('synthetic-alternate-route')),
      array('gorge.db.uri' => 'synthetic-alternate-route')) as $change) {
      $this->withNativeRouting(array_replace($base, $change), function($stack) {
        $caught = null;
        try {
          PhorgeGovernanceNativeCapabilityContext::newIsolated($this->newTask());
        } catch (RuntimeException $error) {
          $caught = $error->getMessage();
        }
        $this->assertEqual(
          'native_capability_alternate_routing_refused', $caught);
      });
    }
    $this->withNativeRouting($base, function($stack) {
      PhabricatorEnv::getEnvConfig('mysql.host');
      $stack->getStack()[0]->setKeys(array('mysql.host' => 'changed-source'));
      $caught = null;
      try {
        PhorgeGovernanceNativeCapabilityContext::newIsolated($this->newTask());
      } catch (RuntimeException $error) {
        $caught = $error->getMessage();
      }
      $this->assertEqual('native_capability_stale_config_cache', $caught);
    });
    $this->withNativeRouting($base, function($stack) {
      $stack->pushSource(new PhabricatorConfigDictionarySource(array()));
      $caught = null;
      try {
        PhorgeGovernanceNativeCapabilityContext::newIsolated($this->newTask());
      } catch (RuntimeException $error) {
        $caught = $error->getMessage();
      }
      $this->assertEqual('native_capability_dynamic_sources_refused', $caught);
    });
  }

  public function testIneligibleOwnerCannotBecomeAnAdmissionGrant() {
    foreach (array(
      $this->newViewer()->setIsDisabled(1),
      $this->newViewer()->setIsApproved(0),
      $this->newViewer()->setIsSystemAgent(1),
      $this->newViewer()->setIsMailingList(1)) as $viewer) {
      $task = $this->newTask($viewer->getPHID());
      $result = PhorgeGovernanceNativeObservationVerifier::newIsolated()
        ->observeCapabilities($viewer, $task,
          array(PhabricatorPolicyCapability::CAN_EDIT));
      $this->assertEqual('ineligible_viewer', $result['reason']);
      $this->assertFalse(isset($result['observations']));
      $this->assertFalse($result['can_execute']);
    }
  }

}
