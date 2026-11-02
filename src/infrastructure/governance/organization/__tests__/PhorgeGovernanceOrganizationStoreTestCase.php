<?php

final class PhorgeGovernanceOrganizationStoreTestCase extends PhutilTestCase {

  private function config() {
    return array('host' => '127.0.0.1', 'port' => 3306,
      'dbname' => 'governance_org_'.str_repeat('a', 16),
      'user' => 'synthetic-writer', 'password' => 'synthetic-only',
      'owner_nonce' => str_repeat('b', 32));
  }

  private function request() {
    return array('operation_id' => 'synthetic-operation',
      'actor' => 'PHID-USER-'.str_repeat('a', 20), 'mutations' => array(array(
        'action' => 'member.add', 'team' => 'team-a',
        'target_user' => 'PHID-USER-'.str_repeat('b', 20),
        'expected_team_version' => 1, 'expected_member_version' => 0,
        'expected_appointment_version' => 0, 'valid_from' => 1,
        'valid_until' => null, 'approval_id' => null)));
  }

  public function testOnlyExplicitOwnedFixtureConfigAccepted() {
    $valid = PhorgeGovernanceOrganizationStore::validateFixtureConfig($this->config());
    $this->assertEqual(3306, $valid['port']);
    foreach (array(array('host' => 'database'), array('dbname' => 'production'),
      array('port' => '3306'), array('owner_nonce' => 'unknown'),
      array('is_admin' => true), array('dsn' => 'arbitrary')) as $change) {
      $caught = false;
      try {
        PhorgeGovernanceOrganizationStore::validateFixtureConfig(
          array_merge($this->config(), $change));
      } catch (InvalidArgumentException $error) { $caught = true; }
      $this->assertTrue($caught);
    }
  }

  public function testFixtureAndMutationReferencesDetached() {
    $database = $this->config()['dbname'];
    $config = $this->config(); $config['dbname'] = &$database;
    $copy = PhorgeGovernanceOrganizationStore::validateFixtureConfig($config);
    $database = 'changed';
    $this->assertEqual($this->config()['dbname'], $copy['dbname']);
    $action = 'member.add'; $request = $this->request();
    $request['mutations'][0]['action'] = &$action;
    $copy = PhorgeGovernanceOrganizationRequest::normalize($request);
    $action = 'appointment.grant';
    $this->assertEqual('member.add', $copy['mutations'][0]['action']);
  }

  public function testUnknownCallerAuthorityAndInvalidVersionsRejected() {
    foreach (array('extra', 'actor', 'action', 'teamversion', 'memberversion', 'period') as $axis) {
      $request = $this->request();
      switch ($axis) {
        case 'extra': $request['is_admin'] = true; break;
        case 'actor': $request['actor'] = true; break;
        case 'action': $request['mutations'][0]['action'] = 'unit.archive'; break;
        case 'teamversion': $request['mutations'][0]['expected_team_version'] = 0; break;
        case 'memberversion': $request['mutations'][0]['expected_member_version'] = '0'; break;
        case 'period': $request['mutations'][0]['valid_until'] = 1; break;
      }
      $caught = false;
      try { PhorgeGovernanceOrganizationRequest::normalize($request); }
      catch (InvalidArgumentException $error) { $caught = true; }
      $this->assertTrue($caught);
    }
    $this->assertEqual(0, PhorgeGovernanceOrganizationRequest::normalize(
      $this->request())['mutations'][0]['expected_member_version']);
  }

  public function testAmbiguousBatchAndUngatedAppointmentRejected() {
    $request = $this->request(); $request['mutations'][] = $request['mutations'][0];
    $caught = false;
    try { PhorgeGovernanceOrganizationRequest::normalize($request); }
    catch (InvalidArgumentException $error) { $caught = true; }
    $this->assertTrue($caught);
    $request = $this->request(); $request['mutations'][0]['action'] = 'appointment.grant';
    $caught = false;
    try { PhorgeGovernanceOrganizationRequest::normalize($request); }
    catch (InvalidArgumentException $error) { $caught = true; }
    $this->assertTrue($caught);
    $request['mutations'][0]['approval_id'] = 'synthetic-approval';
    $copy = PhorgeGovernanceOrganizationRequest::normalize($request);
    $this->assertEqual('appointment.grant', $copy['mutations'][0]['action']);
  }

  public function testEligibilityRequiresTypedCurrentSourceAndVersion() {
    $row = array('user_id' => 'PHID-USER-'.str_repeat('a',20), 'version' => '1',
      'is_admin' => '0', 'is_approved' => '1', 'is_disabled' => '0',
      'is_system_agent' => '0', 'is_mailing_list' => '0');
    $this->assertTrue(PhorgeGovernanceOrganizationStore::eligible($row));
    foreach (array(array('is_disabled' => '1'), array('is_approved' => true),
      array('is_admin' => '1 garbage'), array('version' => '01'),
      array('version' => 0)) as $change) {
      $this->assertFalse(PhorgeGovernanceOrganizationStore::eligible(
        array_merge($row, $change)));
    }
    $this->assertFalse(PhorgeGovernanceOrganizationStore::eligible(array('is_admin' => true)));
    $class = new ReflectionClass(PhorgeGovernanceOrganizationStore::class);
    $this->assertTrue($class->getConstructor()->isPrivate());
    $this->assertFalse($class->hasMethod('getConnection'));
  }
}
