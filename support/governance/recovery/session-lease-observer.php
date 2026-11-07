<?php
/** Test-only opaque owner. Never creates or widens a native writer account. */
final class GovernanceSessionLeaseObserverOwner {
  private $fixture,$control,$user,$password,$owner,$writerGrants;
  private $attempted=false,$cleaned=false;
  private function __construct(){}
  private function __clone(){}
  public function __serialize(){throw new RuntimeException('session_observer_serialization_refused');}
  public function __unserialize(array $data){throw new RuntimeException('session_observer_serialization_refused');}
  public function __debugInfo(){return array('scope'=>'owned-isolated-session-metadata-observer');}
  private function owned(){
    $this->fixture->assertOwned();
    if((new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'control'))->getValue($this->fixture)!==$this->control||
      (new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'ownerNonce'))->getValue($this->fixture)!==$this->owner['governance_fixture_owner']){
      throw new RuntimeException('session_observer_fixture_owner_changed');}
  }
  private function writerGrants(){
    $writer=$this->fixture->getWriterConfig();if(!preg_match('/\Agov_c_writer_[a-f0-9]{12}\z/D',$writer['user'])){throw new RuntimeException('session_observer_writer_unknown');}
    $rows=$this->control->query("SHOW GRANTS FOR '".$writer['user']."'@'%'")->fetch_all(MYSQLI_NUM);$grants=array();foreach($rows as$row){if(count($row)!==1){throw new RuntimeException('session_observer_writer_grants_unknown');}$grants[]=$row[0];}
    PhorgeRecoverySessionProducerFence::leaseDescribeGrants($grants,'writer',$writer['user'].'@%',$this->fixture->getNamespace());sort($grants,SORT_STRING);return$grants;
  }
  private function exists(){return(int)$this->control->query("SELECT COUNT(*) n FROM mysql.user WHERE User='".$this->user."' AND Host='%'")->fetch_assoc()['n'];}
  private function assertAccountOwner(){
    $row=$this->control->query("SELECT ATTRIBUTE attributes FROM information_schema.USER_ATTRIBUTES WHERE USER='".$this->user."' AND HOST='%'")->fetch_assoc();
    if(!$row||!is_string($row['attributes'])||strlen($row['attributes'])>4096){throw new RuntimeException('session_observer_account_owner_unknown');}
    $attributes=json_decode($row['attributes'],true,8,JSON_THROW_ON_ERROR);if(!is_array($attributes)||count($attributes)!==count($this->owner)){throw new RuntimeException('session_observer_account_owner_unknown');}
    foreach($this->owner as$key=>$value){if(($attributes[$key]??null)!==$value){throw new RuntimeException('session_observer_account_owner_unknown');}}
  }
  public static function prepare($fixture,mysqli $control,$revision){
    if(PHP_SAPI!=='cli'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'||get_class($fixture)!==PhorgeGovernanceCompletionFixture::class||
      !is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/D',$revision)||$revision!==getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION')){
      throw new RuntimeException('session_observer_isolated_profile_required');}
    if(isset($GLOBALS['governance_session_lease_observer_owner'])){throw new RuntimeException('session_observer_owner_reentry');}
    $fixture->assertOwned();if((new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'control'))->getValue($fixture)!==$control){throw new RuntimeException('session_observer_control_unknown');}
    $self=new self();$self->fixture=$fixture;$self->control=$control;$self->user='gov_c_metadata_'.bin2hex(random_bytes(8));$self->password=bin2hex(random_bytes(24));
    $self->owner=array('governance_fixture_namespace'=>$fixture->getNamespace(),'governance_fixture_owner'=>(new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'ownerNonce'))->getValue($fixture),'governance_run_nonce'=>bin2hex(random_bytes(16)),'governance_source_revision'=>$revision);
    if(!is_string($self->owner['governance_fixture_owner'])||!preg_match('/\A[a-f0-9]{64}\z/D',$self->owner['governance_fixture_owner'])){throw new RuntimeException('session_observer_fixture_owner_unknown');}
    $self->owned();$self->writerGrants=$self->writerGrants();
    if($self->exists()!==0){throw new RuntimeException('session_observer_account_preexists');}
    // Retain exact opaque ownership before SQL, including a lost CREATE ACK.
    $GLOBALS['governance_session_lease_observer_owner']=$self;$self->attempted=true;
    $attrs=$control->real_escape_string(json_encode($self->owner,JSON_THROW_ON_ERROR));
    $control->query("CREATE USER '".$self->user."'@'%' IDENTIFIED BY '".$self->password."' ATTRIBUTE '".$attrs."'");$self->assertAccountOwner();
    $control->query("GRANT SELECT ON `performance\\_schema`.* TO '".$self->user."'@'%'");
    $control->query("GRANT PROCESS ON *.* TO '".$self->user."'@'%'");
    if($self->writerGrants()!==$self->writerGrants){throw new RuntimeException('session_observer_writer_grants_changed');}
    return$self;
  }
  public function configuration(){
    $this->owned();$this->assertAccountOwner();$writer=$this->fixture->getWriterConfig();
    return array('observer'=>array('host'=>$writer['host'],'port'=>$writer['port'],'namespace'=>$this->fixture->getNamespace(),'user'=>$this->user,'password'=>$this->password),'metadata_owner'=>$this->owner);
  }
  public function cleanup(){
    $this->owned();if($this->cleaned){throw new RuntimeException('session_observer_cleanup_reentry');}
    $exists=$this->exists();if($exists!==0&&$exists!==1){throw new RuntimeException('session_observer_account_unknown');}
    if($exists===1){$this->assertAccountOwner();}
    $deadline=microtime(true)+3;$absent=false;
    do{$n=(int)$this->control->query("SELECT COUNT(*) n FROM performance_schema.threads WHERE PROCESSLIST_USER='".$this->user."'")->fetch_assoc()['n'];if($n===0){$absent=true;break;}usleep(20000);}while(microtime(true)<$deadline);
    if(!$absent){throw new RuntimeException('session_observer_owned_SQL_sessions_remaining');}
    if($exists===1){$this->assertAccountOwner();$this->control->query("DROP USER '".$this->user."'@'%'");}
    if($this->exists()!==0||$this->writerGrants()!==$this->writerGrants){throw new RuntimeException('session_observer_cleanup_unproved');}
    $this->cleaned=true;return array('owned_observer_removed'=>true,'owned_observer_SQL_sessions_absent'=>true,'native_writer_grants_unchanged'=>true,'cleanup_proved'=>true);
  }
}
