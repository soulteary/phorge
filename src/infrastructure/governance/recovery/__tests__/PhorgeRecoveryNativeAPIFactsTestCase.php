<?php

final class PhorgeRecoveryNativeAPIFactsTestCase extends PhutilTestCase {
  private function groups() { return array_fill_keys(array('conduit','oauth_tokens','oauth_authorizations','oauth_codes','oauth_clients','legacy_sessions'),array()); }
  private function target() { return 'PHID-USER-'.str_repeat('a',20); }
  private function token() { return array('id'=>'1','objectPHID'=>$this->target(),'tokenType'=>'api','token'=>str_repeat('s',32),'expires'=>null,'dateCreated'=>'1','dateModified'=>'1','tokenName'=>'Synthetic'); }
  private function refused($call) {
    $refused=false;try{$call();}catch(RuntimeException$error){$refused=true;}
    $this->assertTrue($refused);
  }
  public function testNoAPIObservationCanGrantRecoveryOrExposeSecret() {
    $groups=$this->groups();$groups['conduit'][]=$this->token();
    $r=PhorgeRecoveryNativeAPIFacts::describe($groups,$this->target(),10);
    $this->assertFalse($r['can_execute']);$this->assertFalse($r['api_family_complete']);
    $this->assertFalse(strpos(json_encode($r),str_repeat('s',32))!==false);
    $this->assertFalse($r['api_credentials']['conduit'][0]['credential_permission']);
  }
  public function testExpiryChangesObservationButNotStorageBinding() {
    $groups=$this->groups();$row=$this->token();$row['expires']='11';$groups['conduit'][]=$row;
    $before=PhorgeRecoveryNativeAPIFacts::describe($groups,$this->target(),10);
    $after=PhorgeRecoveryNativeAPIFacts::describe($groups,$this->target(),12);
    $this->assertEqual($before['storage_set_digest'],$after['storage_set_digest']);
    $this->assertEqual('expired',$after['api_credentials']['conduit'][0]['observation']);
    $groups['conduit'][0]['token']=str_repeat('t',32);
    $changed=PhorgeRecoveryNativeAPIFacts::describe($groups,$this->target(),12);
    $this->assertTrue($changed['storage_set_digest']!==$after['storage_set_digest']);
  }
  public function testMalformedForeignAndOversizedValuesFailClosed() {
    $base=$this->groups();$row=$this->token();
    foreach(array('id'=>array(),'objectPHID'=>'PHID-USER-'.str_repeat('b',20),'expires'=>true,'token'=>str_repeat('z',5000))as$key=>$value){
      $groups=$base;$changed=$row;$changed[$key]=$value;$groups['conduit'][]=$changed;
      $target=$this->target();$this->refused(function()use($groups,$target){PhorgeRecoveryNativeAPIFacts::describe($groups,$target,10);});
    }
    $groups=$base;for($i=1;$i<=65;$i++){$row['id']=(string)$i;$groups['conduit'][]=$row;}
    $target=$this->target();$this->refused(function()use($groups,$target){PhorgeRecoveryNativeAPIFacts::describe($groups,$target,10);});
  }
  public function testUnknownNativeTokenKindsAreExplicitlyUncovered() {
    $groups=$this->groups();$row=$this->token();$row['tokenType']='unknown';$groups['conduit'][]=$row;
    $r=PhorgeRecoveryNativeAPIFacts::describe($groups,$this->target(),10);
    $this->assertTrue(in_array('unknown-conduit-token-type',$r['unknown_reasons'],true));
    $this->assertFalse($r['all_sources_complete']);
    $groups['conduit'][0]['tokenType']='clr';$r=PhorgeRecoveryNativeAPIFacts::describe($groups,$this->target(),10);
    $this->assertTrue(in_array('cluster-token-requires-native-network-context',$r['unknown_reasons'],true));
  }
}
