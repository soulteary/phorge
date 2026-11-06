<?php
/** Native model/input contracts, never full FileQuery/physical SQL authorization. */
final class PhorgeRecoveryFileSourceFactsTestCase extends PhutilTestCase {
  public function testNativeFileAuthorAutomaticIsNotAccountOrPolicyAdmission(){
    $author=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('a',20));$other=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('o',20));
    $file=(new PhabricatorFile())->setAuthorPHID($author->getPHID())->setViewPolicy('no-one')->attachObjects(array())->attachOriginalFile(null);
    foreach(array(PhabricatorPolicyCapability::CAN_VIEW,PhabricatorPolicyCapability::CAN_EDIT,'synthetic-unlisted-capability')as$cap){$this->assertTrue($file->hasAutomaticCapability($cap,$author));$this->assertFalse($file->hasAutomaticCapability($cap,$other));}
    $author->setIsDisabled(1);$this->assertTrue($file->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_VIEW,$author));$this->assertEqual(PhabricatorPolicies::POLICY_NOONE,$file->getPolicy(PhabricatorPolicyCapability::CAN_EDIT));
  }
  public function testNativeFileAttachmentModesAndAlreadyAuthorizedViewInputs(){
    foreach(array('attach'=>true,'reference'=>false,'detach'=>false,'unknown'=>false)as$mode=>$expected){$this->assertEqual($expected,(new PhabricatorFileAttachment())->setAttachmentMode($mode)->isPolicyAttachment());}
    $viewer=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('v',20));$file=(new PhabricatorFile())->setAuthorPHID(null)->attachOriginalFile(null)->attachObjects(array());
    $this->assertFalse($file->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_VIEW,$viewer));
    $file->attachObjects(array((new ManiphestTask())->setPHID('PHID-TASK-'.str_repeat('t',20))));$this->assertTrue($file->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_VIEW,$viewer));$this->assertFalse($file->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_EDIT,$viewer));
    $file->attachObjects(array())->attachOriginalFile(new PhabricatorFile());$this->assertTrue($file->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_VIEW,$viewer));$this->assertFalse($file->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_EDIT,$viewer));
  }
  public function testNativeFileIndexesAndMetadataBuiltinProfileDistinction(){
    $file=new PhabricatorFile();$keys=(new ReflectionMethod(PhabricatorFile::class,'getConfiguration'))->invoke($file)[LiskDAO::CONFIG_KEY_SCHEMA];$this->assertEqual(null,$keys['key_phid']);$this->assertEqual(array('columns'=>array('phid'),'unique'=>true),$keys['phid']);$this->assertEqual(array('columns'=>array('authorPHID')),$keys['authorPHID']);
    $keys=(new ReflectionMethod(PhabricatorFileAttachment::class,'getConfiguration'))->invoke(new PhabricatorFileAttachment())[LiskDAO::CONFIG_KEY_SCHEMA];$this->assertEqual(array('columns'=>array('filePHID')),$keys['key_file']);
    $keys=(new ReflectionMethod(PhabricatorTransformedFile::class,'getConfiguration'))->invoke(new PhabricatorTransformedFile())[LiskDAO::CONFIG_KEY_SCHEMA];$this->assertEqual(array('columns'=>array('originalPHID','transform'),'unique'=>true),$keys['originalPHID']);
    $native=(new PhabricatorFile())->generatePHID();$this->assertTrue((bool)preg_match('/\APHID-FILE-[a-zA-Z0-9]{20}\z/D',$native));$identity=new ReflectionMethod(PhorgeRecoveryFileSourceFacts::class,'phid');$this->assertEqual($native,$identity->invoke(null,$native,'FILE'));$refused=false;try{$identity->invoke(null,'PHID-FILE-'.str_repeat('x',15),'FILE');}catch(RuntimeException$error){$refused=true;}$this->assertTrue($refused);
    $file->setBuiltinKey('not-the-native-builtin-name');$this->assertFalse($file->isBuiltin());$file->setBuiltinName('synthetic');$this->assertTrue($file->isBuiltin());$file->setIsProfileImage(true);$this->assertTrue((bool)$file->getIsProfileImage());
  }
}
