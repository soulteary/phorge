<?php

/** Pure event shape only: no caller data constitutes authorization. */
final class PhorgeGovernanceNoticeEventTestCase extends PhutilTestCase {
  public function testNoticeSwitchIsActuallyRegisteredFalseAndLocked(){
    $key='governance.notifications.enabled';$options=PhabricatorApplicationConfigOptions::loadAllOptions();
    $this->assertTrue(isset($options[$key]));$option=$options[$key];
    $this->assertEqual('bool',$option->getType());$this->assertTrue($option->getDefault()===false);
    $this->assertTrue($option->getLocked()===true);
    $this->assertEqual(PhorgeGovernanceNoticeConfigOptions::class,get_class($option->getGroup()));
    $defaults=(new PhabricatorConfigDefaultSource())->getKeys(array($key));
    $this->assertTrue(array_key_exists($key,$defaults)&&$defaults[$key]===false);
    // Exercise the real configuration stack, including a native proxy whose
    // missing backing source throws. Configuration failure is never OFF.
    $stack_property=new ReflectionProperty(PhabricatorEnv::class,'sourceStack');
    $cache_property=new ReflectionProperty(PhabricatorEnv::class,'cache');
    $old_stack=$stack_property->getValue();$old_cache=$cache_property->getValue();
    try{
      foreach(array(
        array('governance.notifications.enabled',PhorgeGovernanceNoticeOutbox::class,'governance_notice_control_unknown'),
        array('governance.private-mail.enabled',PhorgePrivateMailPolicy::class,'private_mail_control_unknown'))as$control){
        list($control_key,$reader,$reason)=$control;
        foreach(array(false,true)as$flag){
          $stack_property->setValue(null,(new PhabricatorConfigStackSource())->pushSource(
            new PhabricatorConfigDictionarySource(array($control_key=>$flag))));
          $cache_property->setValue(null,array());
          $this->assertEqual($flag,$reader::enabled());
        }
        foreach(array(new PhabricatorConfigDictionarySource(array()),
          (new ReflectionClass(PhabricatorConfigLocalSource::class))->newInstanceWithoutConstructor())as$source){
          $stack_property->setValue(null,(new PhabricatorConfigStackSource())->pushSource($source));
          $cache_property->setValue(null,array());$refused=false;
          try{$reader::enabled();}catch(Exception$error){$refused=true;}
          $this->assertTrue($refused);
          if($reader===PhorgePrivateMailPolicy::class){
            // The actual email authorization entry must propagate the broken
            // source before touching SQL; it must not take the OFF=true exit.
            $cache_property->setValue(null,array());$refused=false;
            $mail=(new PhabricatorMetaMTAMail())->setMessageType(PhabricatorMailEmailMessage::MESSAGETYPE);
            try{PhorgePrivateMailPolicy::authorize($mail);}catch(Exception$error){$refused=true;}
            $this->assertTrue($refused);
          }
        }
        foreach(array(null,0,'0','false')as$flag){
          $stack_property->setValue(null,(new PhabricatorConfigStackSource())->pushSource(
            new PhabricatorConfigDictionarySource(array($control_key=>$flag))));
          $cache_property->setValue(null,array());$refused=false;
          try{$reader::enabled();}catch(RuntimeException$error){$refused=$error->getMessage()===$reason;}
          $this->assertTrue($refused);
        }
      }
    }finally{$stack_property->setValue(null,$old_stack);$cache_property->setValue(null,$old_cache);}
  }
  public function testNoticeSchemaPreservesExactNativeVariableBinaryTypes(){
    $spec=(new PhorgeGovernanceNoticeSchemaSpec())->setUTF8Charset('utf8mb4')
      ->setUTF8BinaryCollation('utf8mb4_bin')->setUTF8SortingCollation('utf8mb4_unicode_ci');
    $method=new ReflectionMethod(PhabricatorConfigSchemaSpec::class,'getDetailsForDataType');
    foreach(array('varbytes64'=>'varbinary(64)','phid'=>'varbinary(64)','phid?'=>'varbinary(64)',
      'varbytes32'=>'varbinary(32)','varbytes16'=>'varbinary(16)','uint32'=>'int(10) unsigned',
      'uint64'=>'bigint(20) unsigned','text'=>'longtext')as$type=>$physical){
      $details=$method->invoke($spec,$type);$this->assertEqual($physical,$details['type']);
      $this->assertEqual($type==='phid?',$details['nullable']);
      $this->assertEqual($type==='text'?'utf8mb4':null,$details['charset']);
      $this->assertEqual($type==='text'?'utf8mb4_bin':null,$details['collation']);
    }
    // bytes64 is fixed-width native BINARY and must not describe VARBINARY DDL.
    $old=$method->invoke($spec,'bytes64');$this->assertEqual('binary(64)',$old['type']);
    $this->assertFalse($old['type']===$method->invoke($spec,'varbytes64')['type']);
  }
  private function row(){return array('source_kind'=>'organization','source_id'=>'1','operation_id'=>'synthetic-op',
    'actor_phid'=>'PHID-USER-'.str_repeat('a',20),'action'=>'unit.create','created_at'=>1);}
  public function testStableEventAndBoundedMetadataOnlyQueuePayload(){
    $row=$this->row();$a=PhorgeGovernanceNoticeEventValue::fromAudit($row);
    $this->assertEqual($a,PhorgeGovernanceNoticeEventValue::fromAudit(array_reverse($row,true)));
    $json=json_encode($a['metadata']);$this->assertTrue(!str_contains($json,$row['operation_id'])&&!str_contains($json,$row['actor_phid']));
    $queue=PhorgeGovernanceNoticeEventValue::queuePayload($a);$payload=json_decode($queue['task']['data'],true);
    $this->assertEqual(array('version'=>1,'event_id'=>$a['event_id']),$payload);
    $this->assertEqual('PhorgeGovernanceNoticeWorker',$queue['task']['taskClass']);
    $row['source_id']='2';$this->assertTrue($a['event_id']!==PhorgeGovernanceNoticeEventValue::fromAudit($row)['event_id']);
  }
  public function testUnknownFieldsSourcesAndNumericAliasesRefuse(){
    foreach(array(array('body'=>'private'),array('source_kind'=>'Organization'),array('source_id'=>'01'),
      array('source_id'=>1),array('source_id'=>'18446744073709551616'),array('action'=>'restore.complete'),
      array('created_at'=>true),array('email'=>'arbitrary@example.invalid'))as$delta){
      $failed=false;try{PhorgeGovernanceNoticeEventValue::fromAudit(array_replace($this->row(),$delta));}
      catch(InvalidArgumentException$error){$failed=true;}$this->assertTrue($failed);
    }
    $event=PhorgeGovernanceNoticeEventValue::fromAudit($this->row());$event['metadata']['audience']='anonymous';
    $failed=false;try{PhorgeGovernanceNoticeEventValue::queuePayload($event);}catch(InvalidArgumentException$error){$failed=true;}
    $this->assertTrue($failed);
  }
  public function testQueuedTaskCannotImportSourceAuthorityOrCoerceVersion(){
    $binding=array('event_id'=>str_repeat('a',64),'recipient'=>'PHID-USER-'.str_repeat('a',20),
      'email'=>PhabricatorPHID::generateNewPHID(PhabricatorPeopleUserEmailPHIDType::TYPECONST),
      'account_hash'=>str_repeat('b',64),'address_hash'=>str_repeat('c',64),'source_kind'=>'organization','target'=>null);
    PhorgeGovernanceNoticeMailPolicy::validateBinding($binding);
    $bad=$binding;$bad['email']='PHID-UEMA-'.str_repeat('a',20);$failed=false;
    try{PhorgeGovernanceNoticeMailPolicy::validateBinding($bad);}catch(InvalidArgumentException $error){$failed=true;}
    $this->assertTrue($failed);
    $data=array('version'=>1,'event_id'=>str_repeat('a',64));
    $this->assertEqual($data,PhorgeGovernanceNoticeEventValue::validateTask($data));
    foreach(array(array('version'=>true),array('version'=>'1'),array('event_id'=>str_repeat('A',64)),
      array('allowed'=>true),array('body'=>'private'),array('recipient'=>'untrusted'))as$delta){
      $failed=false;try{PhorgeGovernanceNoticeEventValue::validateTask(array_replace($data,$delta));}
      catch(InvalidArgumentException $error){$failed=true;}$this->assertTrue($failed);
    }
  }
}
