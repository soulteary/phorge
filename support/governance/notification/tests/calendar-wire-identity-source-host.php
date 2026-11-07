<?php
/** Actual credential-free projection with explicit owned-fixture collaborator. */
require dirname(__DIR__).'/calendar-wire-identity-source.php';
final class OwnedIdentityFixture {
 private $configs=array('setup'=>array('user'=>'gov_c_setup_aaaaaaaaaaaa','password'=>'DO_NOT_EXPORT'));
 private $tables=array('user'=>array(),'governance'=>array());
 private $ownerNonce='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
 public $checks=0;public $deny=false;
 function assertOwned(){if($this->deny){throw new RuntimeException('ownership_refused');}$this->checks++;}
 function getReaderConfig(){return array('user'=>'gov_native_ro_aaaaaaaaaaaa','password'=>'DO_NOT_EXPORT');}
 function getWriterConfig(){return array('user'=>'gov_c_writer_aaaaaaaaaaaa','password'=>'DO_NOT_EXPORT');}
 function getSummary(){return array('quickstart_sha256'=>str_repeat('b',64));}
 function getNamespace(){return 'governance_native_aaaaaaaaaaaaaaaa';}
}
$f=new OwnedIdentityFixture();$value=governance_calendar_wire_fixture_identity($f);
if($f->checks!==1||array_keys($value)!==array('namespace','owner_nonce','quickstart_sha256','writer_user','setup_user','reader_user','schemas')||
 $value['schemas']!==array('governance_native_aaaaaaaaaaaaaaaa_governance','governance_native_aaaaaaaaaaaaaaaa_user')||
 str_contains(json_encode($value),'DO_NOT_EXPORT')){throw new RuntimeException('identity_projection_failed');}
$f->deny=true;$denied=false;try{governance_calendar_wire_fixture_identity($f);}catch(RuntimeException$error){$denied=$error->getMessage()==='ownership_refused';}
if(!$denied){throw new RuntimeException('identity_ownership_not_required');}
echo json_encode(array('passed'=>2,'SQL'=>false,'SMTP'=>false,'browser'=>false,'native_fixture_collaborator'=>true))."\n";
