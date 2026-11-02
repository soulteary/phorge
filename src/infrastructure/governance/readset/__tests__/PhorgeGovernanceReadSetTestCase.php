<?php

final class PhorgeGovernanceReadSetTestCase extends PhutilTestCase {

  private function newBinding(array $change = array()) {
    return array_replace(array(
      'operation_id' => 'synthetic-readset-1',
      'actor' => 'synthetic-actor',
      'executor' => 'synthetic-executor',
      'approver' => 'synthetic-approver',
      'target' => 'synthetic-project',
      'operation' => 'project.description',
      'object_class' => 'PhabricatorProject',
      'parameters_digest' => hash('sha256',
        json_encode(array('description' => 'synthetic'))),
      'actor_session' => 'actor-session',
      'executor_session' => 'executor-session',
      'approver_session' => 'approver-session',
    ), $change);
  }

  private function newSources(array $binding = array()) {
    if (!$binding) {
      $binding = $this->newBinding();
    }
    $sources = array();
    foreach (PhorgeGovernanceReadSetValidator::getRequiredSources($binding)
      as $role => $required) {
      $sources[] = new PhorgeGovernanceSourceSnapshot(array(
        'role' => $role,
        'kind' => $required['kind'],
        'id' => $required['id'],
        'store' => 'synthetic-primary',
        'revision' => 'v1',
        'eligibility' => 'eligible',
      ));
    }
    return $sources;
  }

  private function newSnapshot($sources = null) {
    return new PhorgeGovernanceReadSetSnapshot(
      $this->newBinding(), $sources === null ? $this->newSources() : $sources);
  }

  private function replaceSource(array $sources, $role, array $change) {
    foreach ($sources as $key => $source) {
      $data = $source->getData();
      if ($data['role'] === $role) {
        $sources[$key] = new PhorgeGovernanceSourceSnapshot(
          array_replace($data, $change));
      }
    }
    return $sources;
  }

  public function testMatchingReadSetNeverAuthorizesExecution() {
    $expected = $this->newSnapshot();
    $current = $this->newSnapshot();
    $result = PhorgeGovernanceReadSetValidator::compare($expected, $current);
    $this->assertEqual('matched-fixture-only', $result['status']);
    $this->assertEqual('partial-fixture-only', $result['coverage']);
    $this->assertFalse($result['can_execute']);
    $this->assertEqual('partial-fixture-only', $current->getCoverage());
  }

  public function testEveryRequiredRoleIsRequiredNotJustItsKind() {
    $sources = $this->newSources();
    foreach (array_keys($sources) as $key) {
      $missing = $sources;
      unset($missing[$key]);
      $result = PhorgeGovernanceReadSetValidator::compare(
        $this->newSnapshot(), $this->newSnapshot($missing));
      $this->assertEqual('missing_source', $result['reason']);
    }
    $this->assertEqual('missing_source',
      PhorgeGovernanceReadSetValidator::compare(
        $this->newSnapshot(array()), $this->newSnapshot(array()))['reason']);
  }

  public function testSameKindCannotImpersonateAnotherRoleIdentity() {
    $sources = $this->replaceSource($this->newSources(),
      'executor.user.account', array('id' => 'synthetic-actor'));
    $result = PhorgeGovernanceReadSetValidator::compare(
      $this->newSnapshot(), $this->newSnapshot($sources));
    $this->assertEqual('source_identity_changed', $result['reason']);
  }

  public function testCurrentEligibilityAndEveryRevisionInvalidate() {
    foreach ($this->newSources() as $source) {
      $row = $source->getData();
      foreach (array(
        array('revision' => 'v2'),
        array('eligibility' => 'ineligible'),
        array('eligibility' => 'unknown')) as $change) {
        $result = PhorgeGovernanceReadSetValidator::compare(
          $this->newSnapshot(), $this->newSnapshot($this->replaceSource(
            $this->newSources(), $row['role'], $change)));
        $this->assertEqual('blocked', $result['status']);
      }
    }
  }

  public function testAddedAndRemovedManifestSourcesInvalidate() {
    $extra = new PhorgeGovernanceSourceSnapshot(array(
      'role' => 'dependency.named.extra',
      'kind' => 'policy.named',
      'id' => 'synthetic-additional-policy',
      'store' => 'synthetic-primary',
      'revision' => 'v1',
      'eligibility' => 'eligible',
    ));
    $sources = $this->newSources();
    $sources[] = $extra;
    $with_extra = $this->newSnapshot($sources);
    foreach (array(
      array($this->newSnapshot(), $with_extra),
      array($with_extra, $this->newSnapshot())) as $pair) {
      $result = PhorgeGovernanceReadSetValidator::compare($pair[0], $pair[1]);
      $this->assertEqual('sources_changed', $result['reason']);
    }
  }

  public function testDuplicateAndUnknownSourceKindsBlock() {
    $sources = $this->newSources();
    $sources[] = $sources[0];
    $this->assertEqual('duplicate_source_role',
      PhorgeGovernanceReadSetValidator::compare(
        $this->newSnapshot(), $this->newSnapshot($sources))['reason']);
    $sources = $this->replaceSource($this->newSources(),
      'actor.user.account', array('kind' => 'unknown.account'));
    $this->assertEqual('unsupported_source_kind',
      PhorgeGovernanceReadSetValidator::compare(
        $this->newSnapshot(), $this->newSnapshot($sources))['reason']);
  }

  public function testCrossStoreMatchingDoesNotImplyConsistency() {
    $sources = $this->replaceSource($this->newSources(),
      'actor.user.account', array('store' => 'synthetic-second-store'));
    $snapshot = $this->newSnapshot($sources);
    $result = PhorgeGovernanceReadSetValidator::compare($snapshot, $snapshot);
    $this->assertEqual('cross_store_consistency_unproven', $result['reason']);
    $this->assertFalse($result['can_execute']);
  }

  public function testOperationAndAllBindingIdentitiesAreBound() {
    foreach (array(
      array('operation' => 'project.members'),
      array('operation_id' => 'different-operation'),
      array('actor' => 'different-actor'),
      array('executor' => 'different-executor'),
      array('approver' => 'different-approver'),
      array('target' => 'different-target'),
      array('actor_session' => 'different-session'),
      array('parameters_digest' => hash('sha256', 'different')))
      as $change) {
      $current = new PhorgeGovernanceReadSetSnapshot(
        $this->newBinding($change), $this->newSources());
      $this->assertEqual('binding_changed',
        PhorgeGovernanceReadSetValidator::compare(
          $this->newSnapshot(), $current)['reason']);
    }
  }

  public function testUnsupportedOperationAndClassBlock() {
    foreach (array(
      array('operation' => 'unknown.operation'),
      array('object_class' => 'task'),
      array('object_class' => 'unknown')) as $change) {
      $snapshot = new PhorgeGovernanceReadSetSnapshot(
        $this->newBinding($change), array());
      $this->assertEqual('unsupported_operation',
        PhorgeGovernanceReadSetValidator::compare(
          $snapshot, $snapshot)['reason']);
    }
    $this->assertEqual(null,
      PhorgeGovernanceReadSetValidator::getRequiredSourceKinds(array(), 'ManiphestTask'));
  }

  public function testFixedCatalogIncludesNativeAutomaticDependencies() {
    foreach (array(
      array('ManiphestTask', 'task.title', array('task.policy', 'task.owner')),
      array('PhrictionDocument', 'wiki.content', array('wiki.policy', 'wiki.parent')),
      array('PhabricatorProject', 'project.description',
        array('project.policy', 'project.parent', 'project.member')))
      as $case) {
      $kinds = PhorgeGovernanceReadSetValidator::getRequiredSourceKinds(
        $case[1], $case[0]);
      foreach (array_merge($case[2],
        array('org.membership', 'org.appointment', 'policy.named',
          'project.member', 'project.parent')) as $kind) {
        $this->assertTrue(in_array($kind, $kinds, true));
      }
    }
  }

  public function testTaskAndWikiGroupDependenciesAreNotOmitted() {
    foreach (array(
      array('object_class' => 'ManiphestTask', 'operation' => 'task.title'),
      array('object_class' => 'PhrictionDocument', 'operation' => 'wiki.content'))
      as $change) {
      $binding = $this->newBinding($change);
      $sources = $this->newSources($binding);
      $expected = new PhorgeGovernanceReadSetSnapshot($binding, $sources);
      foreach (array('target.project.member', 'target.project.parent') as $role) {
        $current = new PhorgeGovernanceReadSetSnapshot(
          $binding, $this->replaceSource($sources, $role,
            array('revision' => 'v2')));
        $result = PhorgeGovernanceReadSetValidator::compare($expected, $current);
        $this->assertEqual('sources_changed', $result['reason']);
      }
    }
  }

  public function testFixedCatalogAgreesWithCapabilityMapping() {
    foreach (array('PhabricatorProject', 'ManiphestTask', 'PhrictionDocument') as $class) {
      foreach (PhorgeGovernanceCapabilityMap::getKnownOperations($class)
        as $operation) {
        $expected = PhorgeGovernanceCapabilityMap::getRequiredSourceKinds(
          $operation, $class);
        $actual = PhorgeGovernanceReadSetValidator::getRequiredSourceKinds(
          $operation, $class);
        sort($actual, SORT_STRING);
        $this->assertEqual($expected, $actual);
      }
    }
  }

  public function testSelfApprovalIsNotACompleteReadSet() {
    $binding = $this->newBinding(array('approver' => 'synthetic-actor'));
    $snapshot = new PhorgeGovernanceReadSetSnapshot(
      $binding, $this->newSources($binding));
    $this->assertEqual('approver_not_independent',
      PhorgeGovernanceReadSetValidator::compare(
        $snapshot, $snapshot)['reason']);
  }

  public function testSourceAndBindingReferencesAreDetached() {
    $revision = 'v1';
    $data = array(
      'role' => 'actor.user.account', 'kind' => 'user.account',
      'id' => 'synthetic-actor', 'store' => 'synthetic-primary',
      'revision' => &$revision, 'eligibility' => 'eligible',
    );
    $source = new PhorgeGovernanceSourceSnapshot($data);
    $actor = 'synthetic-actor';
    $binding = $this->newBinding();
    $binding['actor'] = &$actor;
    $sources = $this->replaceSource($this->newSources(),
      'actor.user.account', $source->getData());
    $snapshot = new PhorgeGovernanceReadSetSnapshot($binding, $sources);
    $digest = $snapshot->getDigest();
    $revision = 'v2';
    $actor = 'different-actor';
    $sources = array();
    $this->assertEqual('v1', $source->getData()['revision']);
    $this->assertEqual('synthetic-actor', $snapshot->getBinding()['actor']);
    $this->assertEqual($digest, $snapshot->getDigest());
    $this->assertTrue(count($snapshot->getSources()) > 0);
  }

  public function testReadSetToApprovalStillBlocksWithoutNativeAdapter() {
    $snapshot = $this->newSnapshot();
    $result = PhorgeGovernanceReadSetValidator::compare($snapshot, $snapshot);
    $this->assertEqual('matched-fixture-only', $result['status']);
    $binding = $snapshot->getBinding();
    $data = array(
      'operation_id' => $binding['operation_id'],
      'requester' => $binding['actor'],
      'executor' => $binding['executor'],
      'target' => $binding['target'],
      'operation' => $binding['operation'],
      'target_version' => 'target-v1',
      'parameters' => array('description' => 'synthetic'),
      'read_set' => array('fixture.readset' => $snapshot->getDigest()),
      'issued_at' => 100,
      'expires_at' => 200,
    );
    $this->assertEqual($binding['parameters_digest'],
      hash('sha256', json_encode($data['parameters'])));
    $request = new PhorgeGovernanceApprovalRequest($data);
    $state = new PhorgeGovernanceApprovalState($request);
    $context = array(
      'approver' => 'synthetic-approver',
      'approver_active' => true,
      'approver_is_system_admin' => true,
      'approver_can_approve' => true,
      'source_status' => 'unknown',
      'coverage_verified' => false,
      'technical_gates_verified' => false,
    );
    $approved = $state->submit(110)->approve(
      'synthetic-approver', $context, 120);
    $blocked = $approved->evaluateExecution($request, $context, 130);
    $this->assertEqual('blocked', $blocked->getState());
    $this->assertEqual('unknown_sources', $blocked->getReason());
    $this->assertFalse($blocked->canExecute());
    $changed = $this->newSnapshot($this->replaceSource($this->newSources(),
      'executor.org.appointment', array('revision' => 'v2')));
    $data['read_set']['fixture.readset'] = $changed->getDigest();
    $current = new PhorgeGovernanceApprovalRequest($data);
    $this->assertEqual('stale_binding', $approved->evaluateExecution(
      $current, $context, 130)->getReason());
  }

}
