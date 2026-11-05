<?php
final class PhorgeRecoveryOwnedRRScopeTestCase extends PhutilTestCase {
  public function testNextTransactionIsolationPlanIsExact() {
    $this->assertEqual('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
      PhorgeRecoveryOwnedRRScope::isolationPlan('REPEATABLE-READ'));
    foreach(array(null,true,'REPEATABLE READ','READ-COMMITTED; COMMIT')as$value){
      $refused=false;try{PhorgeRecoveryOwnedRRScope::isolationPlan($value);}catch(RuntimeException$error){$refused=true;}
      $this->assertTrue($refused);
    }
  }
  public function testActualEventShapeCannotReviveAfterTransactionABA() {
    $expected=array('thread'=>'10','event'=>'20');
    $row=array('THREAD_ID'=>'10','EVENT_ID'=>'20','STATE'=>'ACTIVE','END_EVENT_ID'=>null,'ISOLATION_LEVEL'=>'REPEATABLE READ');
    $this->assertEqual('active',PhorgeRecoveryOwnedRRScope::classifyEvent(array($row),$expected));
    $next=$row;$next['EVENT_ID']='21';
    $this->assertEqual('unknown',PhorgeRecoveryOwnedRRScope::classifyEvent(array($next),$expected));
    $this->assertEqual('unknown',PhorgeRecoveryOwnedRRScope::classifyEvent(array(),$expected));
    $bad=$row;$bad['ISOLATION_LEVEL']='READ COMMITTED';
    $this->assertEqual('unknown',PhorgeRecoveryOwnedRRScope::classifyEvent(array($bad),$expected));
  }
  public function testCommitRollbackAndUnknownAreNotInterchangeable() {
    $expected=array('thread'=>'10','event'=>'20');
    $row=array('THREAD_ID'=>'10','EVENT_ID'=>'20','STATE'=>'COMMITTED','END_EVENT_ID'=>'30','ISOLATION_LEVEL'=>'REPEATABLE READ');
    $this->assertEqual('committed-observed',PhorgeRecoveryOwnedRRScope::classifyEvent(array($row),$expected));
    $row['STATE']='ROLLED BACK';
    $this->assertEqual('rollback-observed',PhorgeRecoveryOwnedRRScope::classifyEvent(array($row),$expected));
    $row['END_EVENT_ID']=true;
    $this->assertEqual('unknown',PhorgeRecoveryOwnedRRScope::classifyEvent(array($row),$expected));
  }
  public function testOldScopeCannotReleaseNewScopeOwnerToken() {
    // Ownership state control only: no live native writer is constructed.
    $class=new ReflectionClass(PhorgeRecoveryOwnedRRScope::class);
    $active=$class->getProperty('active');$saved=$active->getValue();
    $writer=new stdClass();$oldToken=new stdClass();$newToken=new stdClass();
    $scope=$class->newInstanceWithoutConstructor();
    foreach(array('writer'=>$writer,'ownerToken'=>$oldToken,'state'=>'committed')as$key=>$value){
      $class->getProperty($key)->setValue($scope,$value);}
    $owners=new WeakMap();$owners[$writer]=$newToken;$active->setValue(null,$owners);
    try{
      foreach(array('commit','rollback','getFacts')as$method){
        $refused=false;try{$scope->$method();}catch(RuntimeException$error){
          $refused=$error->getMessage()==='recovery_rr_scope_not_active';}
        $this->assertTrue($refused);$this->assertTrue($owners[$writer]===$newToken);
      }
      $scope->abort();$this->assertTrue($owners[$writer]===$newToken);
      $class->getProperty('state')->setValue($scope,'invalid');
      $facts=$scope->abort();
      $this->assertEqual('ownership-changed',$facts['failure_physical_state']);
      $this->assertFalse($facts['cleanup_proved']);$this->assertTrue($owners[$writer]===$newToken);
      // Positive control: the exact current owner can release its own token.
      $owners[$writer]=$oldToken;$class->getMethod('releaseOwnedWriter')->invoke($scope);
      $this->assertFalse(isset($owners[$writer]));
    }finally{$active->setValue(null,$saved);}
  }
}
