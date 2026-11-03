<?php

final class PhorgeRecoveryNativeWebFactsTestCase extends PhutilTestCase {
  private function subject() { return 'PHID-USER-aaaaaaaaaaaaaaaaaaaa'; }
  private function session($id=1) {
    return array('id'=>(string)$id,'phid'=>'PHID-SSSN-'.str_pad((string)$id,20,'a'),
      'userPHID'=>$this->subject(),'type'=>'web','sessionKey'=>hash('sha256','synthetic-session-'.$id),
      'sessionStart'=>'10','sessionExpires'=>'200','highSecurityUntil'=>null,
      'isPartial'=>'0','signedLegalpadDocuments'=>'0');
  }
  private function token($id=1) {
    return array('id'=>(string)$id,'tokenResource'=>$this->subject(),'tokenType'=>'login:onetime',
      'tokenExpires'=>'200','tokenCode'=>hash('sha256','synthetic-token-'.$id),'userPHID'=>null,
      'properties'=>'{"private":"synthetic-private@example.invalid"}');
  }
  private function describe($sessions,$tokens,$now=100) {
    return PhorgeRecoveryNativeWebFacts::describe($sessions,$tokens,$this->subject(),$now);
  }
  private function refuses($sessions,$tokens) {
    try{$this->describe($sessions,$tokens);}catch(RuntimeException$error){
      return strpos($error->getMessage(),'recovery_')===0;}
    return false;
  }
  public function testSessionStorageStatesAreNeverCredentialPermission() {
    $full=$this->session();$partial=$this->session(2);$partial['isPartial']='1';
    $expired=$this->session(3);$expired['sessionExpires']='100';
    $future=$this->session(4);$future['sessionStart']='101';
    $report=$this->describe(array($full,$partial,$expired,$future),array());
    $this->assertEqual(array('unexpired-stored-session','partial-stored-session','expired','future-start'),
      array_column($report['sessions'],'observation'));
    foreach($report['sessions']as$row){$this->assertEqual(false,$row['credential_permission']);}
    $this->assertEqual(false,$report['web_family_complete']);$this->assertEqual(false,$report['can_execute']);
    $this->assertEqual(false,strpos(json_encode($report),$full['sessionKey'])!==false);
  }
  public function testTemporaryOwnershipAndUnknownKindsAreExplicitAndRedacted() {
    $direct=$this->token();$foreign=$this->token(2);$foreign['userPHID']='PHID-USER-bbbbbbbbbbbbbbbbbbbb';
    $email=$this->token(3);$email['tokenResource']='synthetic-private@example.invalid';$email['userPHID']=$this->subject();
    $unknown=$this->token(4);$unknown['tokenType']='unknown.synthetic';
    $report=$this->describe(array(),array($direct,$foreign,$email,$unknown));
    $this->assertEqual(array('direct-subject','foreign-owner-conflict','ambiguous-resource','direct-subject'),
      array_column($report['temporary_tokens'],'association'));
    $this->assertTrue(in_array('unknown-token-kind',$report['unknown_reasons'],true));
    $encoded=json_encode($report);$this->assertEqual(false,strpos($encoded,'synthetic-private@')!==false);
    $this->assertEqual(false,strpos($encoded,$direct['tokenCode'])!==false);
    $this->assertEqual(false,$report['all_sources_complete']);
  }
  public function testForeignSessionDuplicateAndMalformedRowsAreRefused() {
    $row=$this->session();$row['userPHID']='PHID-USER-bbbbbbbbbbbbbbbbbbbb';
    $this->assertTrue($this->refuses(array($row),array()));
    $this->assertTrue($this->refuses(array($this->session(),$this->session()),array()));
    foreach(array(true,null,'01',2,0.0)as$bad){$row=$this->session();$row['isPartial']=$bad;
      $this->assertTrue($this->refuses(array($row),array()));}
    $token=$this->token();$token['tokenResource']='other-resource';
    $this->assertTrue($this->refuses(array(),array($token)));
    $row=$this->session();$row['sessionExpires']='4294967296';$this->assertTrue($this->refuses(array($row),array()));
  }
  public function testStorageDigestDetectsDeleteReissueAndPreservesTimeSemantics() {
    $key=str_repeat('a',64);$row=$this->session();$row['sessionKey']=&$key;
    $first=$this->describe(array($row),array($this->token()));
    $expired=$this->describe(array($row),array($this->token()),201);
    $this->assertEqual($first['storage_set_digest'],$expired['storage_set_digest']);
    $this->assertEqual('expired',$expired['sessions'][0]['observation']);
    $key=str_repeat('c',64);$reissued=$this->describe(array($row),array($this->token()));
    $this->assertTrue($first['storage_set_digest']!==$reissued['storage_set_digest']);
    $removed=$this->describe(array(),array($this->token()));
    $this->assertTrue($removed['storage_set_digest']!==$first['storage_set_digest']);
    $this->assertEqual('unexpired-stored-session',$first['sessions'][0]['observation']);
  }
  public function testSelectedRowsAndBytesHaveStrictBudgets() {
    $rows=array();for($i=1;$i<=65;$i++){$rows[]=$this->session($i);}
    $this->assertTrue($this->refuses($rows,array()));
    $token=$this->token();$token['properties']=str_repeat('x',4097);
    $this->assertTrue($this->refuses(array(),array($token)));
    $tokens=array();for($i=1;$i<=20;$i++){$row=$this->token($i);$row['properties']=str_repeat('x',4096);$tokens[]=$row;}
    $this->assertTrue($this->refuses(array(),$tokens));
    $row=$this->session();$row['unknown']='not-a-native-field';$this->assertTrue($this->refuses(array($row),array()));
  }
}
