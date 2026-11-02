<?php

final class PhorgeGovernanceOrganizationApprovalTestCase extends PhutilTestCase {
  private function rejects($callable) {
    $caught = false;
    try { $callable(); }
    catch (Throwable $error) { $caught = true; }
    $this->assertTrue($caught);
  }

  public function testOnlyExplicitAdministratorIntentsAreAccepted() {
    $user = 'PHID-USER-'.str_repeat('a', 20);
    $valid = array('subject' => $user, 'valid_from' => 10, 'valid_until' => 20);
    $this->assertEqual($valid,
      PhorgeGovernanceOrganizationApprovalAdapter::validateParameters('team.admin.grant', $valid));
    $revoke = array('subject' => $user, 'valid_from' => null, 'valid_until' => null);
    $this->assertEqual($revoke,
      PhorgeGovernanceOrganizationApprovalAdapter::validateParameters('team.admin.revoke', $revoke));
    foreach (array('member.add', 'team.admin.unknown', '', true) as $operation) {
      $this->rejects(static function() use ($operation, $valid) {
        PhorgeGovernanceOrganizationApprovalAdapter::validateParameters($operation, $valid);
      });
    }
    foreach (array($valid + array('authorized' => true),
      array('subject' => $user, 'valid_from' => 10),
      array('subject' => 'not-a-user', 'valid_from' => 10, 'valid_until' => 20),
      array('subject' => $user, 'valid_from' => '10', 'valid_until' => 20),
      array('subject' => $user, 'valid_from' => true, 'valid_until' => 20),
      array('subject' => $user, 'valid_from' => 20, 'valid_until' => 20),
      array('subject' => $user, 'valid_from' => -1, 'valid_until' => null)) as $invalid) {
      $this->rejects(static function() use ($invalid) {
        PhorgeGovernanceOrganizationApprovalAdapter::validateParameters('team.admin.grant', $invalid);
      });
    }
    $this->rejects(static function() use ($valid) {
      PhorgeGovernanceOrganizationApprovalAdapter::validateParameters('team.admin.revoke', $valid);
    });
  }

  public function testIntentDetachesExternalIdentityAndTimingReferences() {
    $subject = 'PHID-USER-'.str_repeat('a', 20); $from = 10; $until = 20;
    $input = array('subject' => &$subject, 'valid_from' => &$from, 'valid_until' => &$until);
    $copy = PhorgeGovernanceOrganizationApprovalAdapter::validateParameters('team.admin.grant', $input);
    $subject = 'PHID-USER-'.str_repeat('b', 20); $from = 1; $until = 100;
    $this->assertEqual(array('subject' => 'PHID-USER-'.str_repeat('a', 20),
      'valid_from' => 10, 'valid_until' => 20), $copy);
    $this->assertEqual('{"a":1,"b":2}',
      PhorgeGovernanceOrganizationApprovalAdapter::canonicalJSON(array('b' => 2, 'a' => 1)));
  }

  public function testTtlAndSourceVersionsHaveNoCoerciveDefaults() {
    foreach (array(1, 86400) as $valid) {
      $this->assertEqual($valid, PhorgeGovernanceOrganizationApprovalAdapter::validateTTL($valid));
    }
    foreach (array(0, -1, 86401, '10', true, null, 1.0) as $invalid) {
      $this->rejects(static function() use ($invalid) {
        PhorgeGovernanceOrganizationApprovalAdapter::validateTTL($invalid);
      });
    }
    foreach (array(1, '1', '18446744073709551615') as $valid) {
      $this->assertEqual((string)$valid,
        PhorgeGovernanceOrganizationApprovalAdapter::validateVersion($valid));
    }
    foreach (array(0, '0', '01', '-1', '1.0', true, null, '18446744073709551616') as $invalid) {
      $this->rejects(static function() use ($invalid) {
        PhorgeGovernanceOrganizationApprovalAdapter::validateVersion($invalid);
      });
    }
  }

  public function testBindingBudgetsRejectReferenceAmplificationAndUnknownValues() {
    $leaf = array_fill(0, 32, 'synthetic');
    $branches = array_fill(0, 32, $leaf);
    $this->rejects(static function() use ($branches) {
      PhorgeGovernanceOrganizationApprovalAdapter::canonicalJSON($branches);
    });
    foreach (array(new stdClass(), 1.5, str_repeat('x', 4097), array(str_repeat('k', 257) => 1)) as $invalid) {
      $this->rejects(static function() use ($invalid) {
        PhorgeGovernanceOrganizationApprovalAdapter::canonicalJSON($invalid);
      });
    }
    $cycle = array(); $cycle['cycle'] = &$cycle;
    $this->rejects(static function() use ($cycle) {
      PhorgeGovernanceOrganizationApprovalAdapter::canonicalJSON($cycle);
    });
  }

  public function testCallerProofObjectsCannotCreateOwnedApprovalScope() {
    $this->rejects(static function() {
      PhorgeGovernanceOrganizationApprovalAdapter::forLockedStore((object)array(
        'authorized' => true, 'transactionActive' => true, 'is_admin' => true));
    });
    $class = new ReflectionClass(PhorgeGovernanceOrganizationApprovalAdapter::class);
    $this->assertTrue($class->getConstructor()->isPrivate());
    $this->assertTrue($class->getMethod('__clone')->isPrivate());
    $uninitialized = $class->newInstanceWithoutConstructor();
    $this->rejects(static function() use ($uninitialized) { serialize($uninitialized); });
    $this->assertEqual(array('scope' => 'isolated-org-approval'), $uninitialized->__debugInfo());
  }
}
