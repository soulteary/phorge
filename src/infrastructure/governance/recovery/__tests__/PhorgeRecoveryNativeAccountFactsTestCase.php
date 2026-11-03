<?php

final class PhorgeRecoveryNativeAccountFactsTestCase extends PhutilTestCase {
  private function row() {
    return array('phid'=>'PHID-USER-aaaaaaaaaaaaaaaaaaaa','isAdmin'=>'0','isApproved'=>'1',
      'isDisabled'=>'0','isSystemAgent'=>'0','isMailingList'=>'0',
      'accountSecret'=>'Synthetic sensitive value','conduitCertificate'=>null);
  }
  private function refuses($row,$generation='0',$legacy=null) {
    try { PhorgeRecoveryNativeAccountFacts::decode($row,$generation,$legacy); }
    catch (RuntimeException $exception) { return strpos($exception->getMessage(),'recovery_') === 0; }
    return false;
  }
  public function testCanonicalFactsAreNonAuthorityAndDoNotExposeSecrets() {
    $row=$this->row(); $facts=PhorgeRecoveryNativeAccountFacts::decode($row,'0',null);
    $this->assertEqual(array('state'=>'observed-absence','value'=>null),$facts['legacy_epoch']);
    $this->assertEqual(false,$facts['authority']);
    $this->assertEqual(false,strpos(json_encode($facts),$row['accountSecret'])!==false);
    $this->assertEqual(false,isset($facts['eligible']));
    $row['isDisabled']='1';
    $this->assertEqual(true,PhorgeRecoveryNativeAccountFacts::decode($row,'2','3')['flags']['isDisabled']);
  }
  public function testRejectsNonCanonicalFlagsAndOverflow() {
    foreach(array(null,true,false,'01','',2,1.0,array()) as $bad){
      $row=$this->row();$row['isAdmin']=$bad;$this->assertTrue($this->refuses($row));
    }
    foreach(array('01','-1','1.0','18446744073709551616',true,null) as $bad){
      $this->assertTrue($this->refuses($this->row(),$bad));
    }
    $this->assertTrue($this->refuses($this->row(),'0','0'));
    $this->assertEqual('18446744073709551615',
      PhorgeRecoveryNativeAccountFacts::decode($this->row(),'18446744073709551615','1')['native_transaction_id']);
  }
  public function testDefensiveScalarCopyAndGenerationABA() {
    $secret='old';$row=$this->row();$row['accountSecret']=&$secret;
    $before=PhorgeRecoveryNativeAccountFacts::decode($row,'7','1');$secret='new';
    $after=PhorgeRecoveryNativeAccountFacts::decode($row,'7','1');
    $this->assertTrue($before['security_fields_digest']!==$after['security_fields_digest']);
    $aba=PhorgeRecoveryNativeAccountFacts::decode($this->row(),'9','1');
    $this->assertTrue($before!==$aba);
    $this->assertTrue($this->refuses($row+array('unknown'=>'source')));
    $row['accountSecret']=str_repeat('x',4097);$this->assertTrue($this->refuses($row));
  }
}
