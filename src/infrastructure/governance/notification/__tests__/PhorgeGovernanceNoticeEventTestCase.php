<?php

/** Pure event shape only: no caller data constitutes authorization. */
final class PhorgeGovernanceNoticeEventTestCase extends PhutilTestCase {
  public function testNativeRemovalAndNoopDoNotReadNoticeConfiguration(){
    $methods=array(new ReflectionMethod(PhabricatorUserTransactionEditor::class,'didApplyInternalEffects'),
      new ReflectionMethod(PhabricatorUserTransactionEditor::class,'applyFinalEffects'));
    $editor=new PhabricatorUserTransactionEditor();$object=new PhabricatorUser();
    $cases=array();foreach(array(array(true,false),array(true,true),array(false,false))as$values){
      $cases[]=(new PhabricatorUserTransaction())->setTransactionType(PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE)
        ->setOldValue($values[0])->setNewValue($values[1]);}
    $grant=(new PhabricatorUserTransaction())->setTransactionType(PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE)->setOldValue(false)->setNewValue(true);
    $stack=new ReflectionProperty(PhabricatorEnv::class,'sourceStack');$cache=new ReflectionProperty(PhabricatorEnv::class,'cache');
    $before_stack=$stack->getValue();$before_cache=$cache->getValue();
    try{
      $stack->setValue(null,(new PhabricatorConfigStackSource())->pushSource(new PhabricatorConfigDictionarySource(array())));$cache->setValue(null,array());
      foreach($methods as$method){
        foreach($cases as$xact){$this->assertEqual(array($xact),$method->invoke($editor,$object,array($xact)));}
        $refused=false;try{$method->invoke($editor,$object,array($grant));}catch(Exception$error){$refused=true;}
        $this->assertTrue($refused);
        $closed=false;try{$editor->assertNoticeAdminGrantActive($object,$grant);}
        catch(RuntimeException$error){$closed=$error->getMessage()==='notice_native_admin_phase_ended';}
        $this->assertTrue($closed);
      }
    }finally{$stack->setValue(null,$before_stack);$cache->setValue(null,$before_cache);}
  }
  public function testNativeAdminGrantPhaseCannotBorrowAfterItsOwnerEnds(){
    $editor=new PhabricatorUserTransactionEditor();$refused=false;
    try{$editor->assertNoticeAdminGrantActive(new PhabricatorUser(),new PhabricatorUserTransaction());}
    catch(RuntimeException$error){$refused=$error->getMessage()==='notice_native_admin_phase_ended';}
    $this->assertTrue($refused);
  }
  public function testNativeAdminGrantUsesStoredNativeTransactionAndSubjectBinding(){
    $row=array('id'=>'7','phid'=>'PHID-XACT-USER-'.str_repeat('x',15),
      'objectPHID'=>'PHID-USER-'.str_repeat('s',20),'authorPHID'=>'PHID-USER-'.str_repeat('a',20),
      'transactionType'=>PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE,'oldValue'=>'false','newValue'=>'true','dateCreated'=>'1700000000');
    $audit=PhorgeGovernanceNoticeSource::nativeAdminGrantAudit($row);
    $event=PhorgeGovernanceNoticeEventValue::fromAudit($audit);
    $this->assertEqual('native-admin-grant',$event['metadata']['source_kind']);
    $this->assertEqual('admin.granted',$event['metadata']['action']);
    $this->assertFalse(str_contains(json_encode($event),$row['objectPHID']));
    $changed=$row;$changed['objectPHID']='PHID-USER-'.str_repeat('t',20);
    $this->assertFalse($event['event_id']===PhorgeGovernanceNoticeEventValue::fromAudit(
      PhorgeGovernanceNoticeSource::nativeAdminGrantAudit($changed))['event_id']);
  }
  public function testNativeAdminGrantRejectsDemotionNoopUnknownAndNoncanonicalSource(){
    $base=array('id'=>'7','phid'=>'PHID-XACT-USER-'.str_repeat('x',15),
      'objectPHID'=>'PHID-USER-'.str_repeat('s',20),'authorPHID'=>'PHID-USER-'.str_repeat('a',20),
      'transactionType'=>PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE,'oldValue'=>'false','newValue'=>'true','dateCreated'=>'1700000000');
    foreach(array(array('oldValue','true'),array('newValue','false'),array('newValue','1'),array('newValue',true),
      array('id',true),array('dateCreated',false),array('transactionType','user.disable'),array('phid','PHID-XACT-USER-unknown'),
      array('authorPHID',$base['objectPHID']))as$mutation){
      $row=$base;$row[$mutation[0]]=$mutation[1];$refused=false;
      try{PhorgeGovernanceNoticeSource::nativeAdminGrantAudit($row);}catch(RuntimeException$error){$refused=true;}
      $this->assertTrue($refused);
    }
  }
  public function testNoticeIdentityGenerationHasStrictUnsignedBoundary(){
    foreach(array('0'=>'1','1'=>'2','9223372036854775807'=>'9223372036854775808',
      '18446744073709551614'=>'18446744073709551615')as$old=>$next){
      $this->assertEqual($next,PhorgeGovernanceNoticeIdentityScope::nextGeneration((string)$old));
    }
    foreach(array(true,false,'01','-1','18446744073709551615','18446744073709551616')as$value){
      $failed=false;try{PhorgeGovernanceNoticeIdentityScope::nextGeneration($value);}catch(RuntimeException$error){$failed=true;}
      $this->assertTrue($failed);
    }
  }
  public function testNoticeIneligibleFastPathUsesOnlyCanonicalNativePolicyFields(){
    $base=(new PhabricatorUser())->setIsAdmin(1)->setIsApproved(1)->setIsDisabled(0)->setIsSystemAgent(0)->setIsMailingList(0);
    $this->assertFalse(PhorgeGovernanceNoticeIdentityScope::definitelyIneligible($base));
    foreach(array('isDisabled'=>1,'isApproved'=>0,'isAdmin'=>0,'isSystemAgent'=>1,'isMailingList'=>1)as$field=>$value){
      foreach(array($value,(string)$value,(bool)$value)as$raw){$user=clone$base;$user->{'set'.ucfirst($field)}($raw);
        $this->assertTrue(PhorgeGovernanceNoticeIdentityScope::definitelyIneligible($user));}
      foreach(array('false','0.0','01',null)as$unknown){$user=clone$base;$user->{'set'.ucfirst($field)}($unknown);
        $this->assertFalse(PhorgeGovernanceNoticeIdentityScope::definitelyIneligible($user));}
    }
    $base->setIsEmailVerified(0);$this->assertFalse(PhorgeGovernanceNoticeIdentityScope::definitelyIneligible($base));
  }
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
