<?php

final class PhorgeRecoveryRestoreRequestTestCase extends PhutilTestCase {
  private function input() {
    return array('native_stop_transaction'=>'42',
      'operation_id'=>'restore-request-0001','reason'=>'Synthetic review',
      'subject'=>'PHID-USER-'.str_repeat('a',20),'ttl_seconds'=>600);
  }

  public function testCallerCannotSupplyAuthorityOrCoercedLifetime() {
    $input=$this->input();
    $this->assertEqual('native.enable',PhorgeRecoveryRestoreRequest::normalize($input)['action']);
    foreach (array(array('complete',true),array('permit_state','approved'),
      array('ttl_seconds','600'),array('ttl_seconds',true),array('ttl_seconds',0),
      array('ttl_seconds',86401),array('reason',"bad\0reason"),
      array('native_stop_transaction','042')) as $mutation) {
      $changed=$input;$changed[$mutation[0]]=$mutation[1];$refused=false;
      try { PhorgeRecoveryRestoreRequest::normalize($changed); }
      catch (InvalidArgumentException $error) { $refused=true; }
      $this->assertTrue($refused);
    }
  }

  public function testStoredInputCannotRedefineSchemaOrInventCompletion() {
    $input=PhorgeRecoveryRestoreRequest::normalize($this->input());
    $this->assertEqual($input,PhorgeRecoveryRestoreRequest::storedInput($input));
    foreach (array(array('schema_version',2),array('action','native.approve'),
      array('all_sources_complete',true),array('ttl_seconds','600')) as $mutation) {
      $changed=$input;$changed[$mutation[0]]=$mutation[1];$refused=false;
      try { PhorgeRecoveryRestoreRequest::storedInput($changed); }
      catch (RuntimeException $error) { $refused=true; }
      $this->assertTrue($refused);
    }
  }

  public function testBindingHashPreservesTypeAndOrderedSourceFacts() {
    $a=array('z'=>true,'a'=>array('second','first'));
    $this->assertEqual(PhorgeRecoveryRestoreRequest::digest($a),
      PhorgeRecoveryRestoreRequest::digest(array('a'=>$a['a'],'z'=>true)));
    $this->assertTrue(PhorgeRecoveryRestoreRequest::digest($a)!==
      PhorgeRecoveryRestoreRequest::digest(array('z'=>1,'a'=>$a['a'])));
    $this->assertTrue(PhorgeRecoveryRestoreRequest::digest($a)!==
      PhorgeRecoveryRestoreRequest::digest(array('z'=>true,'a'=>array('first','second'))));
    foreach (array(1.0,new stdClass()) as $invalid) {
      $refused=false;
      try { PhorgeRecoveryRestoreRequest::digest(array('source'=>$invalid)); }
      catch (InvalidArgumentException $error) { $refused=true; }
      $this->assertTrue($refused);
    }
  }
}
