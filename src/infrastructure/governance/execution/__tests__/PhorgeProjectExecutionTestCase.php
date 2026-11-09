<?php

final class PhorgeProjectExecutionTestCase extends PhutilTestCase {
  private function refused($callable,$reason) {
    $caught=null;
    try { $callable(); } catch (RuntimeException $error) { $caught=$error->getMessage(); }
    $this->assertEqual($reason,$caught);
  }
  public function testCanonicalDetachedReferencesAndOrdering() {
    $value='old'; $list=array(&$value); $map=array('z'=>&$value,'a'=>array('nested'=>&$list));
    $before=PhorgeProjectExecutionValue::canonical($map);
    $hash=PhorgeProjectExecutionValue::digest($map);
    $this->assertEqual('{"a":{"nested":["old"]},"z":"old"}',$before);
    $value='new';
    $this->assertEqual('{"a":{"nested":["old"]},"z":"old"}',$before);
    $this->assertTrue($hash!==PhorgeProjectExecutionValue::digest($map));
  }
  public function testStrictValueAndBudgets() {
    $this->refused(function() { PhorgeProjectExecutionValue::canonical(new stdClass()); },'project_execution_value_type');
    $this->refused(function() { PhorgeProjectExecutionValue::canonical(1.5); },'project_execution_value_type');
    $this->refused(function() { PhorgeProjectExecutionValue::canonical(str_repeat('x',262145)); },'project_execution_value_budget');
    $this->refused(function() { PhorgeProjectExecutionValue::canonical(array_fill(0,513,null)); },'project_execution_value_budget');
    $this->refused(function() { $v=null; for ($i=0;$i<12;$i++) { $v=array($v); } PhorgeProjectExecutionValue::canonical($v); },'project_execution_value_budget');
  }
  public function testTicketIdentifiersAreExactBytes() {
    $value=str_repeat('a',64);
    $this->assertEqual($value,PhorgeProjectExecutionValue::identifier($value));
    foreach (array(str_repeat('A',64),$value.' ',substr($value,0,31),true,null,array()) as $bad) {
      $this->refused(function() use ($bad) { PhorgeProjectExecutionValue::identifier($bad); },'project_execution_identifier_refused');
    }
  }
  public function testBoundedDirectPolicyTypes() {
    foreach (array(PhabricatorPolicies::POLICY_USER,PhabricatorPolicies::POLICY_ADMIN,
      PhabricatorPolicies::POLICY_NOONE,PhabricatorPolicies::POLICY_PUBLIC,
      'PHID-USER-'.str_repeat('a',20)) as $policy) {
      $this->assertEqual($policy,PhorgeProjectExecutionCatalog::policy($policy));
    }
    foreach (array('PHID-PLCY-'.str_repeat('a',20),'user ',null,array(),false) as $bad) {
      $this->refused(function() use ($bad) { PhorgeProjectExecutionCatalog::policy($bad); },'project_execution_policy_unsupported');
    }
  }
  public function testInvalidConfigurationControlsNeverDegradeToFalse() {
    $valid=array('governance.project-approval.enabled'=>true,'policy.allow-public'=>false,
      'governance.project-content-grants.enabled'=>false,'governance.organization.enabled'=>false,
      'phabricator.show-prototypes'=>false,'security.require-multi-factor-auth'=>false,
      'policy.locked'=>array(),'phabricator.application-settings'=>array(),
      'phabricator.uninstalled-applications'=>array(),'load-libraries'=>array(),
      'cluster.databases'=>array(),'mysql.host'=>'fixture.invalid','mysql.user'=>'synthetic',
      'mysql.pass'=>'synthetic-only','storage.default-namespace'=>'synthetic',
      'mysql.port'=>3306,'phorge.product-profile'=>PhorgeProductProfile::PROFILE_FULL,'gorge.db.uri'=>null);
    PhorgeProjectExecutionConfigSource::validateControlValues($valid);
    foreach (array('governance.project-content-grants.enabled','governance.organization.enabled') as $key) {
      $missing=$valid; unset($missing[$key]);
      $this->refused(function() use ($missing) {
        PhorgeProjectExecutionConfigSource::validateControlValues($missing);
      },'project_execution_configuration_control_type_refused');
    }
    foreach (array(null,'',3306,'3306','65535',1) as $port) {
      $configured=$valid; $configured['mysql.port']=$port;
      PhorgeProjectExecutionConfigSource::validateControlValues($configured);
      $this->assertEqual(($port===null || $port==='')?3306:(int)$port,
        PhorgeProjectExecutionConfigSource::normalizePort($port));
    }
    foreach (array(false,true,array(),0,'0','03306','3306 ','+3306','65536',65536,3.5) as $port) {
      $this->refused(function() use ($port) {
        PhorgeProjectExecutionConfigSource::normalizePort($port);
      },'project_execution_configuration_control_type_refused');
    }

    foreach (array('security.require-multi-factor-auth','policy.allow-public','governance.project-approval.enabled',
      'governance.project-content-grants.enabled','governance.organization.enabled') as $key) {
      foreach (array(null,0,'false',array()) as $value) {
        $bad=$valid; $bad[$key]=$value;
        $this->refused(function() use ($bad) { PhorgeProjectExecutionConfigSource::validateControlValues($bad); },'project_execution_configuration_control_type_refused');
      }
    }
  }
  public function testNativeConfigUniqueReplacementProfileAndEpochWriteRefusal() {
    foreach (array('save','insert','update','replace','delete') as $operation) {
      $this->refused(function() use ($operation) { (new PhorgeProjectConfigEpoch())->$operation(); },'configuration_generation_internal_writer_required');
    }
    $row=function($name,$seq,$column) { return array('INDEX_NAME'=>$name,'NON_UNIQUE'=>'0',
      'SEQ_IN_INDEX'=>(string)$seq,'COLUMN_NAME'=>$column,'SUB_PART'=>null); };
    $rows=array($row('PRIMARY',1,'id'),$row('key_phid',1,'phid'),
      $row('key_name',1,'namespace'),$row('key_name',2,'configKey'));
    PhorgeProjectConfigEpochScope::validateUniqueIndexProfile($rows);
    $this->assertTrue(true);
    $extra=$rows; $extra[]=$row('unexpected',1,'value');
    $this->refused(function() use ($extra) { PhorgeProjectConfigEpochScope::validateUniqueIndexProfile($extra); },'configuration_generation_unique_profile_unsupported');
    $prefix=$rows; $prefix[1]['SUB_PART']='16';
    $this->refused(function() use ($prefix) { PhorgeProjectConfigEpochScope::validateUniqueIndexProfile($prefix); },'configuration_generation_unique_profile_unsupported');
    $malformed=$rows; $malformed[1]['SEQ_IN_INDEX']=true;
    $this->refused(function() use ($malformed) { PhorgeProjectConfigEpochScope::validateUniqueIndexProfile($malformed); },'configuration_generation_unique_profile_unsupported');
  }
  public function testNativeConfigEphemeralWritesRefuseBeforeConnection() {
    foreach (array('insert','update','replace','delete') as $operation) {
      $entry=(new PhabricatorConfigEntry())->makeEphemeral();
      $caught=null;
      try { $entry->$operation(); } catch (LiskEphemeralObjectException $error) { $caught=get_class($error); }
      $this->assertEqual(LiskEphemeralObjectException::class,$caught);
    }
  }
  public function testConfigurationGenerationPatchIsEarlyNativePrerequisite() {
    $patches=(new PhabricatorBuiltinPatchList())->getPatches();
    $keys=array_keys($patches);
    $key='20261103.project.configepoch.sql';
    $this->assertEqual(array('db.config'),$patches[$key]['after']);
    $this->assertEqual(array_search('db.config',$keys,true)+1,array_search($key,$keys,true));
    $this->assertTrue(array_search($key,$keys,true)<array_search('20140722.appname.php',$keys,true));
    $this->assertTrue(array_search($key,$keys,true)<array_search('20140914.betaproto.php',$keys,true));
    $all=PhabricatorSQLPatchList::buildAllPatches();
    $this->assertEqual(array('phabricator:db.config'),$all['phabricator:'.$key]->getAfter());
    $this->assertTrue(in_array('phabricator:'.$key,$all['phabricator:db.token']->getAfter(),true));
  }
  public function testConfigurationEpochStrictUnsignedCanonicalValues() {
    foreach (array(1,'1','18446744073709551615') as $value) {
      $this->assertEqual((string)$value,PhorgeProjectConfigEpochScope::canonicalEpoch($value));
    }
    foreach (array(0,'0','01','1.0',true,null,'18446744073709551616',array()) as $value) {
      $this->refused(function() use ($value) { PhorgeProjectConfigEpochScope::canonicalEpoch($value); },'configuration_generation_unknown');
    }
  }
  public function testDefaultOptionAndPrivateShapeClassifier() {
    $options=(new PhorgeProjectExecutionConfigOptions())->getOptions();
    $this->assertFalse($options[0]->getDefault());
    $classifier=new ReflectionMethod(PhorgeProjectExecutionCatalog::class,'classify');
    $classify=function($editor,$project,$transactions) use ($classifier) {
      return $classifier->invoke(null,$editor,$project,$transactions);
    };
    $project=(new PhabricatorProject())->setID(10);
    $actor=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('a',20));
    $editor=(new PhabricatorProjectTransactionEditor())->setActor($actor);
    $bad=(new PhabricatorProjectTransaction())->setTransactionType('unknown');
    $this->refused(function() use ($classify,$editor,$project,$bad) {
      $classify($editor,$project,array($bad));
    },'project_execution_expansion_unsupported');
    $title=(new PhabricatorProjectTransaction())->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)->setNewValue('Synthetic');
    $this->assertEqual('native',$classify($editor,$project,array($title)));
    $self=(new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
      ->setMetadataValue('edge:type',PhabricatorProjectProjectHasMemberEdgeType::EDGECONST)
      ->setNewValue(array('+'=>array($actor->getPHID()=>$actor->getPHID())));
    $this->assertEqual('self-join',$classify($editor,$project,array($self)));
    $leave=clone $self;$leave->setNewValue(array('-'=>array($actor->getPHID()=>$actor->getPHID())));
    $this->assertEqual('self-leave',$classify($editor,$project,array($leave)));
    $this->refused(function() use ($classify,$editor,$project,$self,$title) {
      $classify($editor,$project,array($self,$title));
    },'project_execution_membership_envelope_unsupported');
    $policy=(new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_EDIT_POLICY)
      ->setNewValue(PhabricatorPolicies::POLICY_USER);
    $this->assertEqual('edit-policy',$classify($editor,$project,array($policy)));
    $view=clone$policy;$view->setTransactionType(PhabricatorTransactions::TYPE_VIEW_POLICY);
    $this->assertEqual('view-policy',$classify($editor,$project,array($view)));
    $this->refused(function() use($classify,$editor,$project,$view,$policy){
      $classify($editor,$project,array($view,$policy));
    },'project_execution_expansion_unsupported');
    $tagged=clone $policy;
    $tagged->setMetadataValue('herald:transcriptID',1);
    $this->refused(function() use ($classify,$editor,$project,$tagged) {
      $classify($editor,$project,array($tagged));
    },'project_execution_envelope_unsupported');
    $spoofed=clone $policy; $spoofed->setAuthorPHID($actor->getPHID());
    $this->refused(function() use ($classify,$editor,$project,$spoofed) {
      $classify($editor,$project,array($spoofed));
    },'project_execution_envelope_unsupported');
    $this->refused(function() use ($classify,$editor,$project,$policy,$title) {
      $classify($editor,$project,array($policy,$title));
    },'project_execution_expansion_unsupported');
  }
  public function testNativeStoredMembershipRecordUsesSlimDeltaLists() {
    $first='PHID-USER-'.str_repeat('a',20);$second='PHID-USER-'.str_repeat('b',20);
    $third='PHID-USER-'.str_repeat('c',20);
    $transaction=(new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
      ->setMetadataValue('edge:type',PhabricatorProjectProjectHasMemberEdgeType::EDGECONST)
      ->setOldValue(array($first=>array('dst'=>$first),$second=>array('dst'=>$second)))
      ->setNewValue(array($second=>array('dst'=>$second),$third=>array('dst'=>$third)));
    $native=PhabricatorEdgeChangeRecord::newFromTransaction($transaction);
    $stored=clone $transaction;
    $stored->setOldValue($native->getModernOldEdgeTransactionData())
      ->setNewValue($native->getModernNewEdgeTransactionData());
    $this->assertEqual(array($first),$stored->getOldValue());
    $this->assertEqual(array($third),$stored->getNewValue());
    $read=PhabricatorEdgeChangeRecord::newFromTransaction($stored);
    $this->assertEqual(array($first),$read->getRemovedPHIDs());
    $this->assertEqual(array($third),$read->getAddedPHIDs());
    $this->assertEqual(array($third,$first),$read->getChangedPHIDs());
  }
  private function refusedIntent($callback, $reason) {
    $caught=null;
    try{$callback();}catch(InvalidArgumentException $error){$caught=$error->getMessage();}
    $this->assertEqual($reason,$caught);
  }
  public function testVersionedMembershipIntentIsDetachedAndCanonical() {
    $a='PHID-USER-'.str_repeat('a',20);$b='PHID-USER-'.str_repeat('b',20);
    $external=$b;$values=array(&$external,$a);
    $intent=PhorgeProjectExecutionIntent::newMembers('project.members.add',$values);
    $external='PHID-USER-'.str_repeat('c',20);$values[1]=$external;
    $this->assertEqual(array($a,$b),$intent->getMembers());
    $copy=$intent->getMembers();$copy[0]=$external;
    $this->assertEqual(array($a,$b),$intent->getMembers());
    $this->assertEqual(array('version'=>2,'operation'=>'project.members.add','members'=>array($a,$b)),$intent->getDescriptor());
    $legacy=PhorgeProjectExecutionIntent::newFromBinding(array('operation'=>'project.edit-policy','newPolicy'=>PhabricatorPolicies::POLICY_USER));
    $this->assertEqual(array('version'=>1,'operation'=>'project.edit-policy','policy'=>PhabricatorPolicies::POLICY_USER),$legacy->getDescriptor());
    $descriptor=$intent->getDescriptor();$descriptor['version']=3;
    $this->refusedIntent(function() use($descriptor){PhorgeProjectExecutionIntent::newFromDescriptor($descriptor);},'project_execution_intent_version_refused');
    $this->refusedIntent(function() use($a){PhorgeProjectExecutionIntent::newMembers('project.members.add',array($a,$a));},'project_execution_member_identity_refused');
    $this->refusedIntent(function() use($a){PhorgeProjectExecutionIntent::newMembers('project.members.add',array_fill(0,17,$a));},'project_execution_member_intent_refused');
  }
  public function testMembershipBindingCannotDowngradeOrChangeOperation() {
    $a='PHID-USER-'.str_repeat('a',20);
    $intent=PhorgeProjectExecutionIntent::newMembers('project.members.remove',array($a));
    $binding=array('operation'=>'project.members.remove','newPolicy'=>null,'intent'=>$intent->getDescriptor());
    $this->assertEqual($intent->getDescriptor(),PhorgeProjectExecutionIntent::newFromBinding($binding)->getDescriptor());
    $binding['operation']='project.members.add';
    $this->refusedIntent(function() use($binding){PhorgeProjectExecutionIntent::newFromBinding($binding);},'project_execution_member_intent_refused');
    $binding['operation']='project.edit-policy';$binding['newPolicy']=PhabricatorPolicies::POLICY_USER;
    $this->refusedIntent(function() use($binding){PhorgeProjectExecutionIntent::newFromBinding($binding);},'project_execution_intent_version_refused');
    $this->refusedIntent(function() use($a){PhorgeProjectExecutionIntent::newFromDescriptor(array('version'=>'2','operation'=>'project.members.add','members'=>array($a)));},'project_execution_intent_version_refused');
  }
  public function testNativeThirdPartyMembershipEnvelopeIsFixedNotAuthority() {
    $a='PHID-USER-'.str_repeat('a',20);$b='PHID-USER-'.str_repeat('b',20);
    $transaction=(new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
      ->setMetadataValue('edge:type',PhabricatorProjectProjectHasMemberEdgeType::EDGECONST)
      ->setNewValue(array('+'=>array($b=>$b,$a=>$a)));
    $intent=PhorgeProjectExecutionIntent::newFromNativeMembership($transaction);
    $this->assertEqual(array($a,$b),$intent->getMembers());
    foreach(array(array('+'=>array($a=>array('data'=>array()))),array('+'=>array($a=>$a),'-'=>array($b=>$b)),array('='=>array($a=>$a))) as$value){
      $bad=clone$transaction;$bad->setNewValue($value);
      $this->refusedIntent(function() use($bad){PhorgeProjectExecutionIntent::newFromNativeMembership($bad);},'project_execution_member_envelope_refused');
    }
    $tagged=clone$transaction;$tagged->setMetadataValue('producer',true);
    $this->refusedIntent(function() use($tagged){PhorgeProjectExecutionIntent::newFromNativeMembership($tagged);},'project_execution_member_envelope_refused');
    $classifier=new ReflectionMethod(PhorgeProjectExecutionCatalog::class,'classify');
    $editor=(new PhabricatorProjectTransactionEditor())->setActor((new PhabricatorUser())->setPHID($a));
    $this->assertEqual('members-add',$classifier->invoke(null,$editor,(new PhabricatorProject())->setID(1),array($transaction)));
  }

  public function testViewPolicyIntentIsExplicitAndCannotReinterpretOldTickets() {
    $policy='PHID-USER-'.str_repeat('v',20);
    $view=PhorgeProjectExecutionIntent::newViewPolicy($policy);
    $this->assertEqual(false,$view->isMembership());
    $this->assertEqual('viewPolicy',$view->getPolicyField());
    $this->assertEqual(PhabricatorTransactions::TYPE_VIEW_POLICY,$view->getTransactionType());
    $descriptor=array('version'=>3,'operation'=>'project.view-policy','policy'=>$policy);
    $this->assertEqual($descriptor,$view->getDescriptor());
    $binding=array('operation'=>'project.view-policy','newPolicy'=>$policy,'intent'=>$descriptor);
    $this->assertEqual($descriptor,PhorgeProjectExecutionIntent::newFromBinding($binding)->getDescriptor());
    $binding['newPolicy']=PhabricatorPolicies::POLICY_USER;
    $this->refusedIntent(function() use($binding){PhorgeProjectExecutionIntent::newFromBinding($binding);},'project_execution_member_intent_refused');
    $binding['operation']='project.edit-policy';
    $this->refusedIntent(function() use($binding){PhorgeProjectExecutionIntent::newFromBinding($binding);},'project_execution_intent_version_refused');
    foreach(array('project.join-policy','project.space','project.edit-policy') as$operation){
      $bad=$descriptor;$bad['operation']=$operation;
      $this->refusedIntent(function() use($bad){PhorgeProjectExecutionIntent::newFromDescriptor($bad);},'project_execution_intent_version_refused');
    }
  }

  public function testWebFormOperationIsExplicitAndLegacyEditStaysEdit() {
    $target='PHID-PROJ-'.str_repeat('p',20);
    $edit=PhorgeProjectPolicyApprovalController::newFormPayload('edit',$target,'users','reason',120);
    $view=PhorgeProjectPolicyApprovalController::newFormPayload('view',$target,'users','reason',120);
    $this->assertEqual('users',$edit['new_policy']);
    $this->assertFalse(array_key_exists('intent',$edit));
    $this->assertEqual(array('version'=>3,'operation'=>'project.view-policy','policy'=>'users'),$view['intent']);
    $this->assertFalse(array_key_exists('new_policy',$view));
    foreach(array('',null,true,'VIEW','project.view-policy','history')as$bad){
      $caught=false;
      try{PhorgeProjectPolicyApprovalController::newFormPayload($bad,$target,'users','reason',120);}
      catch(PhorgeProjectPolicyRefusalException$error){$caught=$error->getRefusalReason()==='request-input';}
      $this->assertTrue($caught);
    }
  }

  public function testCallerDefaultMetadataIsNotNativeFormProvenance() {
    $project=(new PhabricatorProject())->setID(1);
    $editor=new PhabricatorProjectTransactionEditor();
    $view=(new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_VIEW_POLICY)->setNewValue('users');
    $name=(new PhabricatorProjectTransaction())->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)
      ->setNewValue('Untrusted changed name')->setIsDefaultTransaction(true);
    $classify=new ReflectionMethod(PhorgeProjectExecutionCatalog::class,'classify');
    $this->refused(function()use($classify,$editor,$project,$view,$name){$classify->invoke(null,$editor,$project,array($view,$name));},
      'project_execution_expansion_unsupported');
    $form=new ReflectionMethod(PhorgeProjectExecutionCatalog::class,'classifyNativeForm');
    $this->assertEqual(null,$form->invoke(null,$editor,$project,array($view,$name)));
    $space=(new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_SPACE)
      ->setNewValue(null)->setIsDefaultTransaction(true);
    $this->refused(function()use($classify,$editor,$project,$view,$space){$classify->invoke(null,$editor,$project,array($view,$space));},
      'project_execution_expansion_unsupported');
    $this->assertEqual(null,$form->invoke(null,$editor,$project,array($view,$space)));
  }
  public function testNativeFormProvenanceRejectsAnOffPathFactoryCall() {
    $assert=new ReflectionMethod(PhorgeProjectExecutionCatalog::class,'assertNativeFormCall');
    $this->refused(function()use($assert){$assert->invoke(null,array(),new stdClass(),new stdClass(),new stdClass(),null,new stdClass());},
      'project_execution_native_form_provenance_refused');
  }

  public function testNativeTextareaBrowserLeadingNewlineSemantics() {
    require_once dirname(__DIR__,5).'/support/governance/native-execution/project-view-edit-route-cases.php';
    foreach (array(array("\n",''),array("\nvalue",'value'),array("\n\nvalue","\nvalue"),
      array('value','value'),array("\n&amp;lt;",'&lt;'),array("\r\n\r\nvalue","\nvalue"),
      array("\n&lt;value&gt;",'<value>')) as $case) {
      $this->assertEqual($case[1],governance_project_native_textarea_value($case[0]));
    }
  }

}
