<?php
require_once __DIR__.'/organization-grant-fencer-mdl.php';
require_once __DIR__.'/organization-grant-mdl-cutpoint-diagnostic.php';
require_once __DIR__.'/organization-grant-mdl-acquisition-diagnostic.php';
require_once __DIR__.'/organization-grant-mdl-v3.php';
require_once __DIR__.'/organization-grant-owned-x.php';

/** Disposable-process mechanics only. No source permit or production grant lease. */
final class GovernanceNativeWriterRRMDLComponent {
  private $scope;private $writer;private $observer;private $control;private $role;
  private $subject;private $ending;private $capture;private $terminal=false;
  private function __construct(){}
  private function __clone(){}
  public function __serialize(){throw new RuntimeException('native_rr_component_serialization_refused');}
  public function __unserialize(array$data){throw new RuntimeException('native_rr_component_serialization_refused');}
  public function __debugInfo(){return array('scope'=>'disposable-native-writer-RR-MDL-no-permit');}
  public static function begin(PhorgeRecoveryOwnedRRScope$scope,PhabricatorUser$subject,
    AphrontDatabaseConnection$observer,mysqli$control,$ending){
    if(PHP_SAPI!=='cli'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'||
      !in_array($ending,array('commit','rollback'),true)||get_class($subject)!==PhabricatorUser::class||
      !$subject->getID()||!$subject->getPHID()||get_class($observer)!==AphrontMySQLiDatabaseConnection::class){throw new RuntimeException('native_rr_component_profile_refused');}
    $scope->getFacts();$self=new self();$self->scope=$scope;$self->observer=$observer;$self->control=$control;$self->ending=$ending;
    $self->writer=(new PhabricatorUser())->establishConnection('w');
    if(get_class($self->writer)!==AphrontMySQLiDatabaseConnection::class||$self->writer===$observer||$subject->establishConnection('w')!==$self->writer){throw new RuntimeException('native_rr_component_writer_refused');}
    $writer=queryfx_one($self->writer,'SELECT CAST(CONNECTION_ID() AS CHAR) id,@@server_uuid uuid,CURRENT_USER() principal,CURRENT_ROLE() role,@@proxy_user proxy,DATABASE() db');
    $meta=queryfx_one($observer,'SELECT CAST(CONNECTION_ID() AS CHAR) id,@@server_uuid uuid,CURRENT_USER() principal,CURRENT_ROLE() role,@@proxy_user proxy');
    $server=governance_recovery_grant_mdl_rows($control,'SELECT @@server_uuid uuid');$profile=PhorgeRecoveryRestoreProfile::assertCurrent();
    if(!$writer||!$meta||count($server)!==1||$writer['uuid']!==$meta['uuid']||$writer['uuid']!==$server[0]['uuid']||$writer['id']===$meta['id']||$writer['id']===(string)$control->thread_id||$writer['db']!==$profile['namespace'].'_user'||
      $writer['principal']!==PhabricatorEnv::getEnvConfig('mysql.user').'@%'||!preg_match('/\Agov_c_metadata_[a-f0-9]{16}@%\z/D',$meta['principal'])||
      $writer['role']!=='NONE'||$meta['role']!=='NONE'||$writer['proxy']!==null||$meta['proxy']!==null||!governance_grant_cutpoint_uint($writer['id'])||$writer['id']==='0'){
      throw new RuntimeException('native_rr_component_identity_unknown');}
    $self->role=$writer;$self->role['observer_principal']=$meta['principal'];$self->role['namespace']=$profile['namespace'];
    $row=queryfx_one($self->writer,'SELECT * FROM %R WHERE id=%d FOR UPDATE',$subject,$subject->getID());
    if(!$row||$row['phid']!==$subject->getPHID()){throw new RuntimeException('native_rr_component_subject_unknown');}
    $self->subject=(new PhabricatorUser())->loadFromArray($row);
    $name=substr($meta['principal'],0,-2);
    $grant=queryfx_all($self->writer,'SELECT Host,User,Select_priv,Trigger_priv,Process_priv FROM mysql.user FORCE INDEX (PRIMARY) WHERE Host=%s AND User=%s FOR UPDATE','%',$name);
    if(count($grant)!==1||$grant[0]!==array('Host'=>'%','User'=>$name,'Select_priv'=>'Y','Trigger_priv'=>'Y','Process_priv'=>'Y')){throw new RuntimeException('native_rr_component_grant_tuple_unknown');}
    $owned=governance_grant_owned_x_poll(static function()use($self){
      $rows=governance_recovery_grant_mdl_rows($self->control,"SELECT COUNT(*) n FROM performance_schema.data_locks g JOIN performance_schema.data_locks u ON u.ENGINE=g.ENGINE AND u.ENGINE_TRANSACTION_ID=g.ENGINE_TRANSACTION_ID JOIN information_schema.INNODB_TRX b ON b.TRX_ID=CAST(g.ENGINE_TRANSACTION_ID AS CHAR) JOIN performance_schema.threads t ON t.PROCESSLIST_ID=b.TRX_MYSQL_THREAD_ID WHERE g.ENGINE='INNODB' AND b.TRX_MYSQL_THREAD_ID=".$self->role['id']." AND g.THREAD_ID=t.THREAD_ID AND u.THREAD_ID=t.THREAD_ID AND g.OBJECT_SCHEMA='mysql' AND g.OBJECT_NAME='user' AND g.INDEX_NAME='PRIMARY' AND g.LOCK_TYPE='RECORD' AND g.LOCK_STATUS='GRANTED' AND g.LOCK_MODE IN ('X','X,REC_NOT_GAP') AND u.OBJECT_SCHEMA='".$self->role['db']."' AND u.OBJECT_NAME='user' AND u.INDEX_NAME='PRIMARY' AND u.LOCK_TYPE='RECORD' AND u.LOCK_STATUS='GRANTED' AND u.LOCK_MODE IN ('X','X,REC_NOT_GAP') AND u.LOCK_DATA='".(int)$self->subject->getID()."'");
      if(count($rows)!==1||!is_string($rows[0]['n'])||!preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$rows[0]['n'])){throw new RuntimeException('native_rr_component_physical_unknown');}return$rows[0]['n']!=='0';
    },static function(){return hrtime(true);},static function($us){usleep($us);});
    if(!$owned['matched']){throw new RuntimeException('native_rr_component_physical_unproved');}
    $before=$self->readIdentity();$thread=$before['ticket']['OWNER_THREAD_ID'];$point=$before['ticket']['OWNER_EVENT_ID'];$lower=governance_grant_cutpoint_predecessor($point);$rows=array();$counts=array();
    foreach(array('current','history')as$storage){$part=governance_recovery_grant_mdl_rows($control,'SELECT CAST(THREAD_ID AS CHAR) THREAD_ID,CAST(EVENT_ID AS CHAR) EVENT_ID,CAST(END_EVENT_ID AS CHAR) END_EVENT_ID,EVENT_NAME,CAST(NESTING_EVENT_ID AS CHAR) NESTING_EVENT_ID,NESTING_EVENT_TYPE FROM performance_schema.events_statements_'.$storage.' WHERE THREAD_ID='.$thread.' AND (EVENT_ID='.$point.' OR (EVENT_ID<'.$point.' AND (END_EVENT_ID>='.$lower.' OR END_EVENT_ID IS NULL))) ORDER BY EVENT_ID DESC LIMIT 17');$counts[$storage]=count($part);$rows=array_merge($rows,$part);}
    $after=$self->readIdentity();$self->capture=array('private_identity'=>$before,'private_after_identity'=>$after,'private_statement_rows'=>$rows,'private_statement_counts'=>$counts,'public'=>governance_grant_acquisition_unavailable());
    governance_grant_mdl_v3_capture($self->capture,$after,$self->role['id']);$self->assertActive();
    return$self;
  }
  private function readIdentity(){
    $id=$this->role['id'];$tickets=governance_recovery_grant_mdl_tickets($this->control);
    $tx=governance_recovery_grant_mdl_rows($this->control,'SELECT CAST(e.THREAD_ID AS CHAR) THREAD_ID,CAST(e.EVENT_ID AS CHAR) EVENT_ID,e.STATE,CAST(e.END_EVENT_ID AS CHAR) END_EVENT_ID,e.ISOLATION_LEVEL FROM performance_schema.events_transactions_current e JOIN performance_schema.threads t ON t.THREAD_ID=e.THREAD_ID WHERE t.PROCESSLIST_ID='.$id.' LIMIT 2');
    if(count($tx)!==1||$tx[0]['ISOLATION_LEVEL']!=='REPEATABLE READ'){throw new RuntimeException('native_rr_component_isolation_unknown');}unset($tx[0]['ISOLATION_LEVEL']);
    $physical=governance_recovery_grant_mdl_rows($this->control,"SELECT DISTINCT CAST(b.TRX_ID AS CHAR) TRX_ID,CAST(l.THREAD_ID AS CHAR) THREAD_ID,CAST(b.TRX_MYSQL_THREAD_ID AS CHAR) PROCESSLIST_ID FROM performance_schema.data_locks l JOIN information_schema.INNODB_TRX b ON b.TRX_ID=CAST(l.ENGINE_TRANSACTION_ID AS CHAR) WHERE l.ENGINE='INNODB' AND b.TRX_MYSQL_THREAD_ID=".$id." AND l.OBJECT_SCHEMA='mysql' AND l.OBJECT_NAME='user' AND l.INDEX_NAME='PRIMARY' AND l.LOCK_TYPE='RECORD' AND l.LOCK_STATUS='GRANTED' AND l.LOCK_MODE IN ('X','X,REC_NOT_GAP') LIMIT 2");
    return governance_grant_acquisition_identity($tickets,$tx,$physical,$id);
  }
  private function assertActive(){
    if($this->terminal){throw new RuntimeException('native_rr_component_terminal');}$this->scope->getFacts();
    $now=queryfx_one($this->writer,'SELECT CAST(CONNECTION_ID() AS CHAR) id,@@server_uuid uuid,CURRENT_USER() principal,CURRENT_ROLE() role,@@proxy_user proxy,DATABASE() db');
    foreach(array('id','uuid','principal','role','proxy','db')as$key){if($now[$key]!==$this->role[$key]){throw new RuntimeException('native_rr_component_identity_changed');}}
    if((new PhabricatorUser())->establishConnection('w')!==$this->writer){throw new RuntimeException('native_rr_component_writer_changed');}
    governance_grant_mdl_v3_capture($this->capture,$this->readIdentity(),$this->role['id']);
  }
  public function writeInnocuousMarker(){
    $this->assertActive();$this->subject->setRealName($this->ending==='commit'?'Synthetic RR Commit Marker':'Synthetic RR Rollback Marker')->saveWithoutIndex();$this->assertActive();
  }
  /** Owner-private worker handshake only. Caller must never publish these identities. */
  public function privateHandshake(){
    $this->assertActive();return array('role'=>$this->role,'capture'=>$this->capture,'subject_id'=>(string)$this->subject->getID(),'subject_phid'=>$this->subject->getPHID(),'ending'=>$this->ending);
  }
  public function finish(){
    $this->assertActive();$this->terminal=true;if($this->ending==='commit'){$this->scope->commit();}else{$this->scope->rollback();}return$this->scope->getTerminalFacts();
  }
  public function abort(){ $this->terminal=true;return$this->scope->abort(); }
}
