<?php

final class PhorgeOrganizationRequestTestCase extends PhutilTestCase {

  private function mutation() {
    return array('action' => 'member.add', 'approval_id' => null,
      'expected_appointment_version' => 0, 'expected_member_version' => 0,
      'expected_team_version' => 1, 'target_user' => 'PHID-USER-aaaaaaaaaaaaaaaaaaaa',
      'team' => 'team-a', 'valid_from' => 0, 'valid_until' => null);
  }

  public function testDetachedNativeInputHasNoActorField() {
    $team = 'team-a'; $mutation = $this->mutation(); $mutation['team'] =& $team;
    $value = PhorgeOrganizationRequest::normalize(array('operation_id' => 'op-1',
      'mutations' => array($mutation)));
    $team = 'team-b';
    $this->assertEqual('team-a', $value['mutations'][0]['team']);
    $this->assertEqual(false, array_key_exists('actor', $value));
    $this->assertException(InvalidArgumentException::class, function() use ($value) {
      $value['actor'] = 'PHID-USER-bbbbbbbbbbbbbbbbbbbb';
      PhorgeOrganizationRequest::normalize($value);
    });
  }

  public function testMalformedBatchAndVersionRefused() {
    $mutation = $this->mutation();
    foreach (array(true, -1, '1', PHP_INT_MAX) as $version) {
      $invalid = $mutation; $invalid['expected_team_version'] = $version;
      $this->assertException(InvalidArgumentException::class, function() use ($invalid) {
        PhorgeOrganizationRequest::normalize(array('operation_id' => 'op-2',
          'mutations' => array($invalid)));
      });
    }
    $this->assertException(InvalidArgumentException::class, function() use ($mutation) {
      PhorgeOrganizationRequest::normalize(array('operation_id' => 'op-3',
        'mutations' => array($mutation, $mutation)));
    });
  }

  public function testUnitShapeAndDetachedReferences() {
    $name = 'Department A';
    $input = array('kind' => 'department', 'name' => &$name,
      'operation_id' => 'create-1', 'parent_id' => null, 'unit_id' => 'dept-a');
    $value = PhorgeOrganizationRequest::unit($input); $name = 'Department B';
    $this->assertEqual('Department A', $value['name']);
    foreach (array('project', 'Team', '') as $kind) {
      $input['kind'] = $kind;
      $this->assertException(InvalidArgumentException::class, function() use ($input) {
        PhorgeOrganizationRequest::unit($input);
      });
    }
    $input['kind'] = 'team'; $input['parent_id'] = 'dept-a';
    $this->assertException(InvalidArgumentException::class, function() use ($input) {
      PhorgeOrganizationRequest::unit($input);
    });
  }

  public function testPrimaryDepartmentRequiresExplicitVersion() {
    $input = array('expected_version' => 0, 'operation_id' => 'department-1',
      'unit_id' => 'department-a', 'user_phid' => 'PHID-USER-aaaaaaaaaaaaaaaaaaaa');
    $this->assertEqual($input, PhorgeOrganizationRequest::primaryDepartment($input));
    foreach (array(true, '0', -1) as $version) {
      $input['expected_version'] = $version;
      $this->assertException(InvalidArgumentException::class, function() use ($input) {
        PhorgeOrganizationRequest::primaryDepartment($input);
      });
    }
  }

  public function testCanonicalORMVersionAndPeriods() {
    $this->assertEqual(3, PhorgeOrganizationService::version('3'));
    foreach (array('03', '-1', true, '18446744073709551616') as $value) {
      $this->assertException(RuntimeException::class, function() use ($value) {
        PhorgeOrganizationService::version($value);
      });
    }
    $row = array('state' => 'active', 'validFrom' => '10', 'validUntil' => '20');
    $this->assertEqual(true, PhorgeOrganizationService::isCurrentRelation($row, 10));
    $this->assertEqual(false, PhorgeOrganizationService::isCurrentRelation($row, 20));
  }

  public function testUnitUpdateRejectsSelfParentAndImplicitVersions() {
    $input = array('expected_version' => 1, 'name' => 'A', 'operation_id' => 'update-1',
      'parent_id' => null, 'state' => 'archived', 'unit_id' => 'team-a');
    $this->assertEqual($input, PhorgeOrganizationRequest::updateUnit($input));
    $input['parent_id'] = 'team-a';
    $this->assertException(InvalidArgumentException::class, function() use ($input) {
      PhorgeOrganizationRequest::updateUnit($input);
    });
  }


  public function testApprovalProposalRejectsActorAndImplicitTime() {
    $subject = 'PHID-USER-aaaaaaaaaaaaaaaaaaaa';
    $input = array('action' => 'appointment.grant', 'approval_id' => 'approval-1',
      'operation_id' => 'op-grant-1', 'target_user' => &$subject, 'team' => 'team-a',
      'ttl_seconds' => 300, 'valid_from' => 0, 'valid_until' => null);
    $copy = PhorgeOrganizationRequest::approval($input);
    $subject = 'PHID-USER-bbbbbbbbbbbbbbbbbbbb';
    $this->assertEqual('PHID-USER-aaaaaaaaaaaaaaaaaaaa', $copy['target_user']);
    foreach (array(true, 0, '300', 86401) as $ttl) {
      $input['ttl_seconds'] = $ttl;
      $this->assertException(InvalidArgumentException::class, function() use ($input) {
        PhorgeOrganizationRequest::approval($input);
      });
    }
    $input['ttl_seconds'] = 300; $input['actor'] = $subject;
    $this->assertException(InvalidArgumentException::class, function() use ($input) {
      PhorgeOrganizationRequest::approval($input);
    });
    unset($input['actor']); $input['action'] = 'appointment.revoke';
    $this->assertException(InvalidArgumentException::class, function() use ($input) {
      PhorgeOrganizationRequest::approval($input);
    });
    $input['valid_from'] = null;
    $this->assertEqual('appointment.revoke',
      PhorgeOrganizationRequest::approval($input)['action']);
  }


  public function testAdminPredicateIsTypedQueryFragment() {
    // Formatting only: constructing this connection opens no socket and is not
    // database, authentication or authorization evidence.
    $connection = new AphrontMySQLiDatabaseConnection(array());
    $method = new ReflectionMethod(PhorgeOrganizationService::class, 'adminPredicate');
    $predicate = $method->invoke(null, $connection);
    $this->assertEqual(PhutilQueryString::class, get_class($predicate));
    $this->assertEqual('1=1', (string)$predicate);
    $this->assertEqual('SELECT COUNT(*) FROM unit WHERE (1=1)',
      (string)qsprintf($connection, 'SELECT COUNT(*) FROM unit WHERE (%Q)', $predicate));
  }
}
