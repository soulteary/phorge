<?php
/** Fixed native Task-owner source under the owned RR prototype, never a permit. */
final class PhorgeRecoveryTaskOwnerSource extends Phobject {
  const MAX_TASKS=128;
  const MAX_BYTES=65536;
  private $scope;private $subject;private $key;private $digest;private $finished=false;
  private function __construct(){}
  private function __clone(){}
  public function __serialize(){throw new RuntimeException('recovery_task_owner_serialization_refused');}
  public function __unserialize(array$value){throw new RuntimeException('recovery_task_owner_serialization_refused');}
  public function __debugInfo(){return array('scope'=>'partial-fixed-native-Task-owner-source');}
  private static function phid($value,$type){if(!is_string($value)||!in_array($type,array('USER','TASK','SPCE'),true)||!preg_match('/\APHID-'.$type.'-[a-zA-Z0-9]{20}\z/D',$value)){throw new RuntimeException('recovery_task_owner_identity_unknown');}return$value;}
  private static function uint($value){if((!is_string($value)&&!is_int($value))||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',(string)$value)||(float)$value>4294967295){throw new RuntimeException('recovery_task_owner_integer_unknown');}return(int)$value;}
  private static function columns(){return array('id','phid','ownerPHID','authorPHID','viewPolicy','editPolicy','spacePHID','status','subtype','dateModified');}
  private static function rows(array$rows,$subject){
    self::phid($subject,'USER');if(count($rows)>self::MAX_TASKS){throw new RuntimeException('recovery_task_owner_row_budget');}$out=array();$bytes=0;
    foreach($rows as$row){
      if(!is_array($row)){throw new RuntimeException('recovery_task_owner_row_unknown');}$keys=array_keys($row);sort($keys);$want=self::columns();sort($want);if($keys!==$want){throw new RuntimeException('recovery_task_owner_row_unknown');}
      $row['id']=self::uint($row['id']);$row['dateModified']=self::uint($row['dateModified']);if($row['id']<1||isset($out[$row['id']])){throw new RuntimeException('recovery_task_owner_row_unknown');}
      self::phid($row['phid'],'TASK');self::phid($row['authorPHID'],'USER');if($row['ownerPHID']!==$subject){throw new RuntimeException('recovery_task_owner_subject_changed');}
      if($row['spacePHID']!==null){self::phid($row['spacePHID'],'SPCE');}
      foreach(array('viewPolicy'=>64,'editPolicy'=>64,'status'=>256,'subtype'=>256)as$field=>$limit){if(!is_string($row[$field])||strlen($row[$field])>$limit){throw new RuntimeException('recovery_task_owner_value_unknown');}}
      if($row['subtype']!==PhabricatorEditEngineSubtype::SUBTYPE_DEFAULT){throw new RuntimeException('recovery_task_owner_subtype_unsupported');}
      $bytes+=256;foreach($row as$value){if(is_string($value)){$bytes+=strlen($value);}}
      if($bytes>self::MAX_BYTES){throw new RuntimeException('recovery_task_owner_byte_budget');}$out[$row['id']]=$row;
    }ksort($out,SORT_NUMERIC);return$out;
  }
  private static function schema($conn,$schema){
    queryfx_all($conn,'SELECT id FROM %T.maniphest_task WHERE 1=0 LOCK IN SHARE MODE',$schema);
    $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$schema,'maniphest_task');
    if(!$engine||$engine['ENGINE']!=='InnoDB'){throw new RuntimeException('recovery_task_owner_engine_unknown');}
    foreach(array('PRIMARY'=>array(array('id'),0),'phid'=>array(array('phid'),0),'ownerPHID'=>array(array('ownerPHID','status'),1))as$index=>$spec){
      $rows=queryfx_all($conn,'SELECT COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME=%s ORDER BY SEQ_IN_INDEX',$schema,'maniphest_task',$index);
      if(count($rows)!==count($spec[0])){throw new RuntimeException('recovery_task_owner_index_unknown');}
      foreach($rows as$i=>$row){if($row['COLUMN_NAME']!==$spec[0][$i]||!in_array($row['NON_UNIQUE'],array(0,1,'0','1'),true)||self::uint($row['NON_UNIQUE'])!==$spec[1]||self::uint($row['SEQ_IN_INDEX'])!==$i+1){throw new RuntimeException('recovery_task_owner_index_unknown');}}
    }
    $rows=queryfx_all($conn,'SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_OCTET_LENGTH,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$schema,'maniphest_task');$needed=array_fill_keys(self::columns(),true);
    foreach($rows as$row){$name=$row['COLUMN_NAME'];if(!isset($needed[$name])){continue;}unset($needed[$name]);
      $nullable=in_array($name,array('ownerPHID','spacePHID'),true)?'YES':'NO';if($row['IS_NULLABLE']!==$nullable){throw new RuntimeException('recovery_task_owner_column_unknown');}
      if(in_array($name,array('id','dateModified'),true)){$ok=$row['DATA_TYPE']==='int'&&is_string($row['COLUMN_TYPE'])&&preg_match('/\Aint(?:\(10\))? unsigned\z/D',$row['COLUMN_TYPE']);}
      elseif(in_array($name,array('status','subtype'),true)){$ok=$row['DATA_TYPE']==='varchar'&&self::uint($row['CHARACTER_MAXIMUM_LENGTH'])===64&&self::uint($row['CHARACTER_OCTET_LENGTH'])<=256;}
      else{$ok=$row['DATA_TYPE']==='varbinary'&&self::uint($row['CHARACTER_OCTET_LENGTH'])===64;}
      if(!$ok){throw new RuntimeException('recovery_task_owner_column_unknown');}
    }
    if($needed){throw new RuntimeException('recovery_task_owner_column_unknown');}
  }
  private static function subject($conn,$schema,$subject){
    $plan=queryfx_one($conn,'SELECT id FROM %T.user FORCE INDEX (phid) WHERE phid=%s',$schema,$subject);
    if(!$plan||self::uint($plan['id'])<1){throw new RuntimeException('recovery_task_owner_subject_unknown');}
    $row=queryfx_one($conn,'SELECT id,phid,isAdmin,isApproved,isDisabled,isSystemAgent,isMailingList FROM %T.user FORCE INDEX (PRIMARY) WHERE id=%d LOCK IN SHARE MODE',$schema,$plan['id']);
    if(!$row||$row['phid']!==$subject||self::uint($row['id'])!==self::uint($plan['id'])){throw new RuntimeException('recovery_task_owner_subject_unknown');}
    foreach(array('isAdmin','isApproved','isDisabled','isSystemAgent','isMailingList')as$field){if(!in_array($row[$field],array(0,1,'0','1'),true)){throw new RuntimeException('recovery_task_owner_subject_flags_unknown');}$row[$field]=(int)$row[$field];}
    $row['id']=self::uint($row['id']);return$row;
  }
  private static function snapshot(PhorgeRecoveryOwnedRRScope$scope,$subject){
    self::phid($subject,'USER');$scope->getFacts();$conn=(new PhabricatorUser())->establishConnection('w');PhorgeRecoveryRestoreProfile::assertOwnedWriter($conn);
    $db=queryfx_one($conn,'SELECT DATABASE() db')['db'];if(!is_string($db)||!preg_match('/\A(governance_native_[a-f0-9]{16})_user\z/D',$db,$m)){throw new RuntimeException('recovery_task_owner_route_unknown');}
    // Native User before Task range/PK; never reverse this order in a producer hook.
    $identity=self::subject($conn,$m[1].'_user',$subject);$schema=$m[1].'_maniphest';self::schema($conn,$schema);
    // Fixed scalar columns only: no title/description/properties/mailKey payload read.
    $rows=queryfx_all($conn,'SELECT id,phid,ownerPHID,authorPHID,viewPolicy,editPolicy,spacePHID,status,subtype,dateModified FROM %T.maniphest_task FORCE INDEX (ownerPHID) WHERE ownerPHID=%s ORDER BY status,id LIMIT 129 LOCK IN SHARE MODE',$schema,$subject);
    $facts=array('identity'=>$identity,'selected_tasks'=>self::rows($rows,$subject));$scope->getFacts();return$facts;
  }
  public static function capture(PhorgeRecoveryOwnedRRScope$scope,$subject){$self=new self();$self->scope=$scope;$self->subject=$subject;$self->key=random_bytes(32);$self->digest=hash_hmac('sha256',serialize(self::snapshot($scope,$subject)),$self->key);return$self;}
  public function finish(){
    if($this->finished){throw new RuntimeException('recovery_task_owner_finished');}$this->finished=true;
    try{if(!hash_equals($this->digest,hash_hmac('sha256',serialize(self::snapshot($this->scope,$this->subject)),$this->key))){throw new RuntimeException('recovery_task_owner_rows_changed');}
      return array('profile'=>'fixed-native-Task-owner-under-owned-RR-v1','actual_SQL_reads_executed'=>true,'owned_native_User_RR_scope_observed'=>true,'native_owner_function_is_full_policy'=>false,'author_is_automatic_grant'=>false,'Space_or_active_account_gate_proved'=>false,'raw_producer_or_empty_range_wait_proved'=>false,'DDL_privilege_and_rollback_fault_matrix_proved'=>false,'all_automatic_object_classes_supported'=>false,'complete'=>false,'all_sources_complete'=>false,'restoration_authorized'=>false);}
    finally{$this->scope=null;$this->subject=null;$this->key=null;$this->digest=null;}
  }
}
