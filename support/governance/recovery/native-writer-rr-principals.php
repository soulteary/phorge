<?php
/** Test-only setup owns one metadata principal and modifies only this fixture writer. */
final class GovernanceNativeRRPrincipals {
  private $fixture;private $control;private $writer;private $observer;private $owner;
  private $attempted=false;private $created=false;
  private function __construct(){}
  private function __clone(){}
  public function __serialize(){throw new RuntimeException('native_rr_principals_serialization_refused');}
  public function __unserialize(array$data){throw new RuntimeException('native_rr_principals_serialization_refused');}
  public function __debugInfo(){return array('scope'=>'owned-isolated-RR-credentials-no-production-provision');}
  public static function prepare($fixture,mysqli$control,$variant){
    if(PHP_SAPI!=='cli'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'||get_class($fixture)!==PhorgeGovernanceCompletionFixture::class||!in_array($variant,array('candidate','missing-select','missing-lock'),true)){throw new RuntimeException('native_rr_principals_profile_refused');}
    $fixture->assertOwned();if((new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'control'))->getValue($fixture)!==$control){throw new RuntimeException('native_rr_setup_control_refused');}$self=new self();$self->fixture=$fixture;$self->control=$control;$self->writer=$fixture->getWriterConfig();
    if(!preg_match('/\Agov_c_writer_[a-f0-9]{12}\z/D',$self->writer['user'])||$self->writer['namespace']!==$fixture->getNamespace()){throw new RuntimeException('native_rr_principals_writer_refused');}
    $user=getenv('PHORGE_GOVERNANCE_METADATA_PRINCIPAL_NAME');$owner=getenv('PHORGE_GOVERNANCE_METADATA_OWNER_NONCE');$run=getenv('PHORGE_GOVERNANCE_METADATA_RUN_NONCE');$source=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
    if(!is_string($user)||!preg_match('/\Agov_c_metadata_[a-f0-9]{16}\z/D',$user)||!is_string($owner)||!preg_match('/\A[a-f0-9]{64}\z/D',$owner)||!is_string($run)||!preg_match('/\A[a-f0-9]{32}\z/D',$run)||!is_string($source)||!preg_match('/\A[a-f0-9]{40}\z/D',$source)){throw new RuntimeException('native_rr_external_owner_lease_required');}
    $self->owner=array('governance_fixture_owner'=>$owner,'governance_fixture_namespace'=>$fixture->getNamespace(),'governance_run_nonce'=>$run,'governance_source_revision'=>$source);
    $password=bin2hex(random_bytes(24));$self->observer=array('host'=>$self->writer['host'],'port'=>$self->writer['port'],'user'=>$user,'password'=>$password,'namespace'=>$fixture->getNamespace());
    if($self->observerExists()){throw new RuntimeException('native_rr_principal_preexists');}
    // The caller must retain this owner before any SQL; do not infer it from a prefix.
    $GLOBALS['governance_native_rr_principal_setup']=$self;$self->attempted=true;$failure=null;
    try{if($control->query("CREATE USER '".$user."'@'%' IDENTIFIED BY '".$password."' ATTRIBUTE '".$control->real_escape_string(json_encode($self->owner,JSON_THROW_ON_ERROR))."'")!==true){throw new RuntimeException('native_rr_create_failed');}}catch(Throwable$error){$failure=$error;}
    if($self->observerExists()){$self->assertObserverOwner();$self->created=true;}
    elseif($failure===null){throw new RuntimeException('native_rr_create_unproved');}
    if($failure!==null){throw$failure;}
    if($control->query("GRANT SELECT, TRIGGER, PROCESS ON *.* TO '".$user."'@'%'")!==true){throw new RuntimeException('native_rr_observer_grant_failed');}
    // Candidate privilege is deliberately no UPDATE/DELETE/GRANT OPTION on mysql.*.
    $writer=$self->writer['user'];
    if($variant!=='missing-select'){if($control->query("GRANT SELECT (Host,User,Select_priv,Trigger_priv,Process_priv) ON mysql.user TO '".$writer."'@'%'")!==true){throw new RuntimeException('native_rr_writer_grant_failed');}}
    if($variant!=='missing-lock'){if($control->query("GRANT LOCK TABLES ON mysql.* TO '".$writer."'@'%'")!==true){throw new RuntimeException('native_rr_writer_grant_failed');}}
    return$self;
  }
  private function observerExists(){
    $user=$this->observer['user'];$row=$this->control->query("SELECT COUNT(*) n FROM mysql.user WHERE User='".$user."' AND Host='%'")->fetch_assoc();return(string)$row['n']!=='0';
  }
  private function assertObserverOwner(){
    $user=$this->observer['user'];$row=$this->control->query("SELECT ATTRIBUTE attributes FROM information_schema.USER_ATTRIBUTES WHERE USER='".$user."' AND HOST='%'")->fetch_assoc();
    if(!$row||!is_string($row['attributes'])){throw new RuntimeException('native_rr_owner_unknown');}$attrs=json_decode($row['attributes'],true,16,JSON_THROW_ON_ERROR);
    foreach($this->owner as$key=>$value){if(($attrs[$key]??null)!==$value){throw new RuntimeException('native_rr_owner_unknown');}}
  }
  public function privateConfiguration(){
    $this->assertObserverOwner();return array('writer'=>$this->writer,'observer'=>$this->observer,'metadata_owner'=>$this->owner);
  }
  /** Never KILL by a bare SQL thread ID, and never use DROP USER as session cleanup. */
  public function cleanup(){
    $this->fixture->assertOwned();$result=array('observer_removed'=>false,'owned_observer_sessions_absent'=>false,'cleanup_proved'=>false);
    if(!$this->attempted){$result['cleanup_proved']=true;return$result;}
    if(!$this->observerExists()){$result['observer_removed']=true;$result['owned_observer_sessions_absent']=true;$result['cleanup_proved']=true;return$result;}
    $this->assertObserverOwner();$user=$this->observer['user'];$deadline=hrtime(true)+3000000000;
    do{$row=$this->control->query("SELECT COUNT(*) n FROM performance_schema.threads WHERE PROCESSLIST_USER='".$user."'")->fetch_assoc();if((string)$row['n']==='0'){$result['owned_observer_sessions_absent']=true;break;}usleep(250000);}while(hrtime(true)<$deadline);
    if(!$result['owned_observer_sessions_absent']){return$result;}
    $this->assertObserverOwner();if($this->control->query("DROP USER '".$user."'@'%'")!==true){throw new RuntimeException('native_rr_drop_unproved');}$result['observer_removed']=!$this->observerExists();$result['cleanup_proved']=$result['observer_removed'];return$result;
  }
}
