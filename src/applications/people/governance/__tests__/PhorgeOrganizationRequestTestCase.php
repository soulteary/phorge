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
  private function bulkIntent() {
    return array('mode'=>'import','operation_id'=>'bulk-test','selection'=>array('PHID-USER-aaaaaaaaaaaaaaaaaaaa'),
      'unit_id'=>'team-a','user_phid'=>null,'valid_from'=>0,'valid_until'=>null);
  }

  public function testBulkSelectionIsDetachedSortedAndDeduplicated() {
    $user='PHID-USER-bbbbbbbbbbbbbbbbbbbb';$input=$this->bulkIntent();
    $input['selection']=array(&$user,'PHID-USER-aaaaaaaaaaaaaaaaaaaa','PHID-USER-aaaaaaaaaaaaaaaaaaaa');
    $value=PhorgeOrganizationRequest::bulk($input);$user='PHID-USER-cccccccccccccccccccc';
    $this->assertEqual(array('PHID-USER-aaaaaaaaaaaaaaaaaaaa','PHID-USER-bbbbbbbbbbbbbbbbbbbb'),$value['selection']);
    $this->assertEqual(false,array_key_exists('actor',$value));
  }

  public function testBulkRejectsAuthorityFieldsUnknownSourceAndUnboundedInput() {
    $base=$this->bulkIntent();$invalid=array();
    foreach(array('actor','is_admin','source','approval_id')as$key){$v=$base;$v[$key]='untrusted';$invalid[]=$v;}
    foreach(array('team','project','department','IMPORT')as$mode){$v=$base;$v['mode']=$mode;$invalid[]=$v;}
    foreach(array(array(),array('user@example.invalid'),array_fill(0,201,'PHID-USER-aaaaaaaaaaaaaaaaaaaa'),array('named'=>'PHID-USER-aaaaaaaaaaaaaaaaaaaa'))as$selection){$v=$base;$v['selection']=$selection;$invalid[]=$v;}
    foreach($invalid as$v){$this->assertException(InvalidArgumentException::class,function()use($v){PhorgeOrganizationRequest::bulk($v);});}
  }

  public function testReplacementIsExplicitAndSupportsEmptyDesiredSet() {
    $v=$this->bulkIntent();$v['mode']='replace';$v['unit_id']=null;
    $v['user_phid']='PHID-USER-aaaaaaaaaaaaaaaaaaaa';$v['selection']=array();
    $this->assertEqual(array(),PhorgeOrganizationRequest::bulk($v)['selection']);
    $v['selection']=array('team-b','team-a','team-a');
    $this->assertEqual(array('team-a','team-b'),PhorgeOrganizationRequest::bulk($v)['selection']);
    $v['user_phid']=null;
    $this->assertException(InvalidArgumentException::class,function()use($v){PhorgeOrganizationRequest::bulk($v);});
  }

  public function testBulkPeriodsAndOperationIDAreStrict() {
    foreach(array(true,'0',-1)as$from){$v=$this->bulkIntent();$v['valid_from']=$from;
      $this->assertException(InvalidArgumentException::class,function()use($v){PhorgeOrganizationRequest::bulk($v);});}
    $v=$this->bulkIntent();$v['valid_until']=0;
    $this->assertException(InvalidArgumentException::class,function()use($v){PhorgeOrganizationRequest::bulk($v);});
    $v=$this->bulkIntent();$v['operation_id']=str_repeat('x',81);
    $this->assertException(InvalidArgumentException::class,function()use($v){PhorgeOrganizationRequest::bulk($v);});
  }

  public function testBulkAdmitsTwoHundredDistinctExistingUserIdentifiers() {
    $v=$this->bulkIntent();$v['selection']=array();
    for($i=0;$i<200;$i++){$v['selection'][]='PHID-USER-'.sprintf('%020d',$i);}
    $this->assertEqual(200,count(PhorgeOrganizationRequest::bulk($v)['selection']));
  }

  public function testReplacementRetainsLiteralZeroTeamIdentifier() {
    $v=$this->bulkIntent();$v['mode']='replace';$v['unit_id']=null;
    $v['user_phid']='PHID-USER-aaaaaaaaaaaaaaaaaaaa';$v['selection']=array('team-a','0');
    $this->assertEqual(array('0','team-a'),PhorgeOrganizationRequest::bulk($v)['selection']);
  }

}
