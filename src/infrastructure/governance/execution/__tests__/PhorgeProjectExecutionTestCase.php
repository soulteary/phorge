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
      'phabricator.show-prototypes'=>false,'security.require-multi-factor-auth'=>false,
      'policy.locked'=>array(),'phabricator.application-settings'=>array(),
      'phabricator.uninstalled-applications'=>array(),'load-libraries'=>array(),
      'cluster.databases'=>array(),'mysql.host'=>'fixture.invalid','mysql.user'=>'synthetic',
      'mysql.pass'=>'synthetic-only','storage.default-namespace'=>'synthetic',
      'mysql.port'=>3306,'phorge.product-profile'=>PhorgeProductProfile::PROFILE_FULL,'gorge.db.uri'=>null);
    PhorgeProjectExecutionConfigSource::validateControlValues($valid);
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

    foreach (array('security.require-multi-factor-auth','policy.allow-public','governance.project-approval.enabled') as $key) {
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
}
