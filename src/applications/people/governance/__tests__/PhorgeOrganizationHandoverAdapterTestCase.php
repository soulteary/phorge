<?php

/** Pure negative/shape/calendar checks, not native SQL or approval proof. */
final class PhorgeOrganizationHandoverAdapterTestCase extends PhutilTestCase {
  private function row($start,$end,$timezone,$days){
    $z=new DateTimeZone($timezone);$a=(new DateTimeImmutable('@'.$start))->setTimezone($z);
    $b=(new DateTimeImmutable('@'.$end))->setTimezone($z);
    return array('startAt'=>(string)$start,'deadlineAt'=>(string)$end,'timezone'=>$timezone,
      'startLocal'=>$a->format('Y-m-d H:i:s'),'deadlineLocal'=>$b->format('Y-m-d H:i:s'),
      'startOffset'=>(string)$a->getOffset(),'deadlineOffset'=>(string)$b->getOffset(),'cumulativeDays'=>(string)$days);
  }
  public function testCumulativeCalendarUsesOriginalLocalDatesAcrossDST(){
    $a=(new DateTimeImmutable('2026-03-01 12:00:00',new DateTimeZone('America/New_York')))->getTimestamp();
    $b=(new DateTimeImmutable('2026-03-31 12:00:00',new DateTimeZone('America/New_York')))->getTimestamp();
    $row=$this->row($a,$b,'America/New_York',30);
    $this->assertEqual(30,PhorgeOrganizationHandoverAdapter::storedCalendar($row));
    $this->assertFalse($b-$a===30*86400);
    $extended=PhorgeOrganizationCalendar::extension($b,1,$row['timezone'],
      PhorgeOrganizationHandoverAdapter::storedCalendar($row),'More handover','Explicit above-30 exception');
    $this->assertEqual(31,$extended['cumulative_days']);
    $refused=false;try{PhorgeOrganizationCalendar::extension($b,1,$row['timezone'],30,'More handover');}
    catch(InvalidArgumentException$error){$refused=$error->getMessage()==='organization_handover_cumulative_exception_required';}
    $this->assertTrue($refused);
  }
  public function testStoredCalendarRejectsForgedTotalOffsetsAndWallTime(){
    $a=(new DateTimeImmutable('2026-10-01 12:00:00',new DateTimeZone('America/New_York')))->getTimestamp();
    $b=(new DateTimeImmutable('2026-11-01 12:00:00',new DateTimeZone('America/New_York')))->getTimestamp();
    $row=$this->row($a,$b,'America/New_York',31);
    $this->assertEqual(31,PhorgeOrganizationHandoverAdapter::storedCalendar($row));
    foreach(array(array('cumulativeDays'=>'30'),array('deadlineOffset'=>'0'),array('deadlineLocal'=>'2026-11-01 13:00:00'),
      array('timezone'=>'untrusted'),array('startAt'=>'01'),array('deadlineAt'=>true))as$change){
      $refused=false;try{PhorgeOrganizationHandoverAdapter::storedCalendar(array_replace($row,$change));}
      catch(RuntimeException$error){$refused=true;}$this->assertTrue($refused);
    }
  }
  public function testFixedActionsRejectCallerAuthorityCumulativeAndUnknownFields(){
    $base=array('operation_id'=>'extend-op','transfer_id'=>'extend-ticket','handover_id'=>'handover',
      'expected_version'=>1,'reason'=>'Reviewed handover','acknowledge_no_project_permission_change'=>true);
    $extend=$base+array('handover_days'=>14);
    $checked=PhorgeOrganizationTransferRequest::handoverEnvelope('extend',array('request'=>$extend,'ttl_seconds'=>300),true);
    $this->assertEqual(null,$checked['request']['exception_reason']);
    $replace=$base+array('responsible_phid'=>'PHID-USER-'.str_repeat('a',20));
    $this->assertEqual($replace['responsible_phid'],PhorgeOrganizationTransferRequest::responsibility($replace)['responsible_phid']);
    foreach(array(array('cumulative_days'=>1),array('approved'=>true),array('actor_phid'=>'forged'))as$change){
      $refused=false;try{PhorgeOrganizationTransferRequest::extension(array_replace($extend,$change));}
      catch(InvalidArgumentException$error){$refused=true;}$this->assertTrue($refused);
    }
    foreach(array('join','grant','arbitrary')as$action){$refused=false;
      try{PhorgeOrganizationTransferRequest::handoverEnvelope($action,array('request'=>$base),false);}
      catch(InvalidArgumentException$error){$refused=true;}$this->assertTrue($refused);}
    $refused=false;try{PhorgeOrganizationTransferRequest::handoverEnvelope('terminate',array('request'=>$base,'deadline_offset'=>0),false);}
    catch(InvalidArgumentException$error){$refused=true;}$this->assertTrue($refused);
  }
  public function testClosedNativeCapabilityFailsBeforeBorrowingSQL(){
    $class=new ReflectionClass(PhorgeOrganizationHandoverAdapter::class);
    $this->assertTrue($class->getConstructor()->isPrivate());$this->assertTrue($class->getMethod('__clone')->isPrivate());
    $this->assertFalse($class->hasMethod('getConnection'));
    $stack=new ReflectionProperty(PhabricatorEnv::class,'sourceStack');$cache=new ReflectionProperty(PhabricatorEnv::class,'cache');
    $old_stack=$stack->getValue();$old_cache=$cache->getValue();$source=new PhabricatorConfigStackSource();
    $source->pushSource(new PhabricatorConfigDictionarySource(array('governance.organization.enabled'=>true)));
    try{$stack->setValue(null,$source);$cache->setValue(null,array());
      $service=(new ReflectionClass(PhorgeOrganizationService::class))->newInstanceWithoutConstructor();
      $adapter=$class->newInstanceWithoutConstructor();
      $class->getProperty('service')->setValue($adapter,WeakReference::create($service));
      $class->getProperty('nonce')->setValue($adapter,str_repeat('a',32));
      $class->getProperty('final')->setValue($adapter,array('ticket'=>array('transferID'=>'expired')));
      $refused=false;try{$adapter->assertFinal();}
      catch(RuntimeException$error){$refused=$error->getMessage()==='organization_transfer_locked_transaction_required';}
      $this->assertTrue($refused);
      $refused=false;try{serialize($adapter);}catch(RuntimeException$error){$refused=true;}$this->assertTrue($refused);
    }finally{$stack->setValue(null,$old_stack);$cache->setValue(null,$old_cache);}
  }
}
