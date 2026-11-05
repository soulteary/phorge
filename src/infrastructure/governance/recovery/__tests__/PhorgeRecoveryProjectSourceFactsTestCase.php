<?php

final class PhorgeRecoveryProjectSourceFactsTestCase extends PhutilTestCase {
  private function rows($retained=false){
    require_once dirname(__DIR__,5).'/support/governance/recovery/tests/project-source-row-controls.php';
    return governance_project_source_control_rows($retained);
  }
  private function refused($subject,array$x){
    try{PhorgeRecoveryProjectSourceFacts::describeUntrustedRows($subject,$x);}
    catch(RuntimeException$error){return str_starts_with($error->getMessage(),'recovery_project_');}
    return false;
  }
  public function testRealNativeUserPHIDIndexOverridesInheritedSchemaName(){
    $config=(new ReflectionMethod(PhabricatorUser::class,'getConfiguration'))->invoke(new PhabricatorUser());
    $schema=$config[LiskDAO::CONFIG_KEY_SCHEMA];
    $this->assertTrue(array_key_exists('key_phid',$schema));
    $this->assertEqual(null,$schema['key_phid']);
    $this->assertEqual(array('columns'=>array('phid'),'unique'=>true),$schema['phid']);
    $project=(new ReflectionMethod(PhabricatorProject::class,'getConfiguration'))->invoke(new PhabricatorProject());
    $this->assertEqual(array('columns'=>array('parentProjectPHID','milestoneNumber'),'unique'=>true),$project[LiskDAO::CONFIG_KEY_SCHEMA]['key_milestone']);
  }
  public function testRealNativeProjectStorageAndMilestoneDelegation(){
    $r=$this->rows();$rows=$r['snapshot']['projects'];
    $root=(new PhabricatorProject())->loadFromArray($rows[$r['root']]);
    $mile=(new PhabricatorProject())->loadFromArray($rows[$r['milestone']]);
    $leaf=(new PhabricatorProject())->loadFromArray($rows[$r['leaf']]);
    // Native loadFromArray does not attach membership/parents on its own.
    $this->assertEqual(PhabricatorProject::ATTACHABLE,(new ReflectionProperty(PhabricatorProject::class,'memberPHIDs'))->getValue($root));
    $this->assertEqual(PhabricatorProject::ATTACHABLE,(new ReflectionProperty(PhabricatorProject::class,'parentProject'))->getValue($mile));
    $root->attachParentProject(null)->setIsUserMember($r['subject'],false)->attachMemberPHIDs(array($r['subject']));
    $mile->attachParentProject($root)->attachMemberPHIDs(array())->setViewPolicy('no-one');
    $leaf->attachParentProject($root);
    $viewer=(new PhabricatorUser())->setPHID($r['subject']);
    $this->assertTrue($root->isUserMember($r['subject'])); // Attached list takes native precedence over sparse hint.
    $this->assertTrue($leaf->isArchived());$this->assertTrue($mile->isMilestone());
    $this->assertEqual(array($root),$mile->getAncestorProjects());
    $this->assertEqual(array('rrrr'),$mile->getAncestorProjectPaths());
    $this->assertEqual('users',$mile->getPolicy(PhabricatorPolicyCapability::CAN_VIEW));
    $this->assertTrue($mile->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_VIEW,$viewer));
    $this->assertEqual(13,PhabricatorProjectProjectHasMemberEdgeType::EDGECONST);
    $this->assertEqual(14,PhabricatorProjectMemberOfProjectEdgeType::EDGECONST);
    $this->assertEqual(null,(new PhabricatorProjectMaterializedMemberEdgeType())->getInverseEdgeConstant());
  }
  public function testDistinctCurrentRowsDoNotImportHistoricalMembership(){
    foreach(array(false,true)as$retained){$r=$this->rows($retained);$out=PhorgeRecoveryProjectSourceFacts::describeUntrustedRows($r['subject'],$r['snapshot']);
      $this->assertFalse($out['actual_SQL_queries_executed']);$this->assertFalse($out['complete']);$this->assertFalse(str_contains(json_encode($out),'PHID-'));}
    $r=$this->rows();$x=$r['snapshot'];$x['history'][]=array('id'=>'1','phid'=>'PHID-XACT-PROJ-'.str_repeat('x',20),
      'objectPHID'=>$r['root'],'authorPHID'=>$r['subject'],'transactionType'=>'edge','oldValue'=>'[]',
      'newValue'=>'opaque former membership','metadata'=>'{}','dateCreated'=>'1');
    $this->assertFalse(PhorgeRecoveryProjectSourceFacts::describeUntrustedRows($r['subject'],$x)['selected_history_is_current_membership']);
    array_pop($x['project_edges']);$this->assertTrue($this->refused($r['subject'],$x));
    $x=$r['snapshot'];$x['user_edges']=array();$this->assertTrue($this->refused($r['subject'],$x));
    $x=$r['snapshot'];$x['projects'][$r['root']]['parentProjectPHID']=$r['leaf'];$this->assertTrue($this->refused($r['subject'],$x));
    $x=$r['snapshot'];$x['project_edges'][0]['dataID']=0.0;$this->assertTrue($this->refused($r['subject'],$x));
  }
  public function testPrimitiveAndLengthBoundsCannotInvokeSerializationCallbacks(){
    $r=$this->rows();$sentinel=new class{public $called=false;public function __serialize(){ $this->called=true;return array(); }};
    $x=$r['snapshot'];$x['history'][]=$sentinel;$this->assertTrue($this->refused($r['subject'],$x));$this->assertFalse($sentinel->called);
    $x=$r['snapshot'];$x['history'][]=static function(){throw new Exception('callback_must_not_execute');};$this->assertTrue($this->refused($r['subject'],$x));
    $method=new ReflectionMethod(PhorgeRecoveryProjectSourceFacts::class,'historyLength');
    foreach(array(0,'0',16384,'16384')as$size){$this->assertEqual((int)$size,$method->invoke(null,$size));}
    foreach(array(null,false,true,0.0,'00','1e3','16385',16385)as$size){$refused=false;
      try{$method->invoke(null,$size);}catch(RuntimeException$error){$refused=$error->getMessage()==='recovery_project_history_length_unknown';}
      $this->assertTrue($refused);}
  }
}
