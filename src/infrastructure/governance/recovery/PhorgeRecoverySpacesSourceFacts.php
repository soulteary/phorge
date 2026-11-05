<?php
/** Private selected Space rows and actual Query request-cache facts; no permit. */
final class PhorgeRecoverySpacesSourceFacts extends Phobject {
  const MAX_ROWS=64;
  const MAX_ROW_BYTES=16384;
  const MAX_TOTAL_BYTES=65536;
  private $connection;private $key;private $digest;private $finished=false;
  private function __construct(){}
  private function __clone(){}
  public function __serialize(){throw new RuntimeException('recovery_spaces_serialization_refused');}
  public function __unserialize(array$data){throw new RuntimeException('recovery_spaces_serialization_refused');}
  public function __debugInfo(){return array('scope'=>'partial-selected-space-facts');}
  private static function columns(){return array('id','phid','namespaceName','viewPolicy','editPolicy','isDefaultNamespace','dateCreated','dateModified','description','isArchived');}
  private static function uint($v,$max=4294967295){
    if((!is_string($v)&&!is_int($v))||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',(string)$v)||(float)$v>$max){throw new RuntimeException('recovery_spaces_integer_unknown');}return(int)$v;
  }
  private static function normalize(array$row){
    $keys=array_keys($row);sort($keys);$want=self::columns();sort($want);if($keys!==$want){throw new RuntimeException('recovery_spaces_row_shape_unknown');}
    if(!is_string($row['phid'])||!preg_match('/\APHID-SPCE-[a-zA-Z0-9]{20}\z/D',$row['phid'])){throw new RuntimeException('recovery_spaces_identity_unknown');}
    foreach(array('namespaceName'=>1020,'viewPolicy'=>64,'editPolicy'=>64,'description'=>self::MAX_ROW_BYTES)as$key=>$limit){
      if(!is_string($row[$key])||strlen($row[$key])>$limit){throw new RuntimeException('recovery_spaces_value_unknown');}}
    if(self::uint($row['id'])<1){throw new RuntimeException('recovery_spaces_identity_unknown');}
    foreach(array('id','dateCreated','dateModified')as$key){$row[$key]=self::uint($row[$key]);}
    $row['isArchived']=self::uint($row['isArchived'],1);
    if($row['isDefaultNamespace']!==null){if(self::uint($row['isDefaultNamespace'],1)!==1){throw new RuntimeException('recovery_spaces_default_unknown');}$row['isDefaultNamespace']=1;}
    return$row;
  }
  private static function objectRow($object){
    if(!is_object($object)||get_class($object)!==PhabricatorSpacesNamespace::class){throw new RuntimeException('recovery_spaces_cached_object_unknown');}
    $r=new ReflectionObject($object);$row=array();
    foreach(self::columns()as$field){$row[$field]=$r->getProperty($field)->getValue($object);}
    return self::normalize($row);
  }
  private static function cacheRowBudget(array$row,&$bytes){
    $bytes+=128;foreach(array('phid','namespaceName','viewPolicy','editPolicy','description')as$field){$bytes+=strlen($row[$field]);}
    if($bytes>self::MAX_TOTAL_BYTES){throw new RuntimeException('recovery_spaces_cache_byte_budget');}
  }
  private static function cachedMap($value,array$rows,&$bytes){
    if(!is_array($value)||count($value)>self::MAX_ROWS){throw new RuntimeException('recovery_spaces_cache_shape_unknown');}
    $out=array();foreach($value as$key=>$object){
      if(!is_string($key)||!isset($rows[$key])){throw new RuntimeException('recovery_spaces_cache_identity_unknown');}
      $row=self::objectRow($object);if($row!==$rows[$key]||$row['phid']!==$key){throw new RuntimeException('recovery_spaces_cache_rows_changed');}self::cacheRowBudget($row,$bytes);$out[$key]=$row;
    }ksort($out);return$out;
  }
  /** Reads actual storage without getter calls, cache creation, policy evaluation or mutation. */
  private static function cacheFacts(array$rows){
    $cache=(new ReflectionProperty(PhabricatorCaches::class,'requestCache'))->getValue();
    if($cache===null){return array('state'=>'uninitialized');}
    if(!is_object($cache)||get_class($cache)!==PhutilInRequestKeyValueCache::class){throw new RuntimeException('recovery_spaces_cache_provider_unknown');}
    $values=(new ReflectionProperty(PhutilInRequestKeyValueCache::class,'cache'))->getValue($cache);
    $ttl=(new ReflectionProperty(PhutilInRequestKeyValueCache::class,'ttl'))->getValue($cache);
    $limit=(new ReflectionProperty(PhutilInRequestKeyValueCache::class,'limit'))->getValue($cache);
    if(!is_array($values)||!is_array($ttl)||count($values)>4096||count($ttl)>4096||$limit!==0){throw new RuntimeException('recovery_spaces_cache_budget_or_mode_unknown');}
    $out=array('state'=>'native-request-cache','selected'=>array());$bytes=0;
    foreach($values as$key=>$value){
      if(!is_string($key)||strlen($key)>256){throw new RuntimeException('recovery_spaces_cache_key_unknown');}
      if(!str_starts_with($key,'spaces.')){continue;}
      if(count($out['selected'])>=8){throw new RuntimeException('recovery_spaces_cache_selected_budget');}
      if(array_key_exists($key,$ttl)){throw new RuntimeException('recovery_spaces_cache_expiry_unsupported');}
      if($key===PhabricatorSpacesNamespaceQuery::KEY_ALL){$map=self::cachedMap($value,$rows,$bytes);if(count($map)!==count($rows)){throw new RuntimeException('recovery_spaces_cache_rows_changed');}$out['selected'][$key]=$map;}
      elseif($key===PhabricatorSpacesNamespaceQuery::KEY_DEFAULT){
        $expected=null;foreach($rows as$row){if($row['isDefaultNamespace']===1){$expected=$row;}}
        $actual=$value===null?null:self::objectRow($value);if($actual!==null){self::cacheRowBudget($actual,$bytes);}
        if($actual!==$expected){throw new RuntimeException('recovery_spaces_cache_default_changed');}$out['selected'][$key]=$actual;
      }elseif(preg_match('/\Aspaces\.viewer\(u\.(?:public|omnipotent|PHID-USER-[a-zA-Z0-9]{20})\)\z/D',$key)){
        // This is the actual cached subset, NOT an attestation of policy correctness.
        $out['selected'][$key]=self::cachedMap($value,$rows,$bytes);
      }else{throw new RuntimeException('recovery_spaces_cache_key_unknown');}
    }
    ksort($out['selected']);return$out;
  }
  private static function schema($conn,$schema){
    queryfx_all($conn,'SELECT id FROM %T.spaces_namespace WHERE 1=0 LOCK IN SHARE MODE',$schema);
    $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$schema,'spaces_namespace');
    if(!$engine||$engine['ENGINE']!=='InnoDB'){throw new RuntimeException('recovery_spaces_engine_unknown');}
    foreach(array('PRIMARY'=>array('id'),'key_phid'=>array('phid'),'key_default'=>array('isDefaultNamespace'))as$index=>$expected){
      $rows=queryfx_all($conn,'SELECT COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME=%s ORDER BY SEQ_IN_INDEX',$schema,'spaces_namespace',$index);
      if(count($rows)!==1||$rows[0]['COLUMN_NAME']!==$expected[0]||(string)$rows[0]['NON_UNIQUE']!=='0'||(string)$rows[0]['SEQ_IN_INDEX']!=='1'){throw new RuntimeException('recovery_spaces_index_unknown');}
    }
    $rows=queryfx_all($conn,'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$schema,'spaces_namespace');
    $actual=ipull($rows,'COLUMN_NAME');sort($actual);$want=self::columns();sort($want);if($actual!==$want){throw new RuntimeException('recovery_spaces_columns_unknown');}
  }
  private static function readRows($conn,$schema){
    // Plan all potentially large fields before retrieving any payload.
    $plans=queryfx_all($conn,'SELECT id,OCTET_LENGTH(phid) phidBytes,OCTET_LENGTH(namespaceName) nameBytes,OCTET_LENGTH(viewPolicy) viewBytes,OCTET_LENGTH(editPolicy) editBytes,OCTET_LENGTH(description) descriptionBytes FROM %T.spaces_namespace FORCE INDEX (PRIMARY) ORDER BY id LIMIT 65 LOCK IN SHARE MODE',$schema);
    if(count($plans)>self::MAX_ROWS){throw new RuntimeException('recovery_spaces_row_budget');}
    $bytes=0;$seen=array();
    foreach($plans as$plan){
      $keys=array_keys($plan);sort($keys);$want=array('id','phidBytes','nameBytes','viewBytes','editBytes','descriptionBytes');sort($want);if($keys!==$want){throw new RuntimeException('recovery_spaces_plan_unknown');}
      $id=self::uint($plan['id']);if($id<1||isset($seen[$id])){throw new RuntimeException('recovery_spaces_plan_unknown');}$seen[$id]=true;$rowbytes=128;
      foreach(array('phidBytes'=>64,'nameBytes'=>1020,'viewBytes'=>64,'editBytes'=>64,'descriptionBytes'=>self::MAX_ROW_BYTES)as$field=>$limit){$rowbytes+=self::uint($plan[$field],$limit);}
      if($rowbytes>self::MAX_ROW_BYTES||($bytes+=$rowbytes)>self::MAX_TOTAL_BYTES){throw new RuntimeException('recovery_spaces_byte_budget');}
    }
    $out=array();$default=0;
    foreach($plans as$plan){
      $row=queryfx_one($conn,'SELECT id,phid,namespaceName,viewPolicy,editPolicy,isDefaultNamespace,dateCreated,dateModified,description,isArchived FROM %T.spaces_namespace FORCE INDEX (PRIMARY) WHERE id=%d AND OCTET_LENGTH(phid)=%d AND OCTET_LENGTH(namespaceName)=%d AND OCTET_LENGTH(viewPolicy)=%d AND OCTET_LENGTH(editPolicy)=%d AND OCTET_LENGTH(description)=%d LOCK IN SHARE MODE',$schema,$plan['id'],$plan['phidBytes'],$plan['nameBytes'],$plan['viewBytes'],$plan['editBytes'],$plan['descriptionBytes']);
      if(!$row||self::uint($row['id'])!==self::uint($plan['id'])){throw new RuntimeException('recovery_spaces_row_changed');}
      $row=self::normalize($row);
      foreach(array('phid'=>'phidBytes','namespaceName'=>'nameBytes','viewPolicy'=>'viewBytes','editPolicy'=>'editBytes','description'=>'descriptionBytes')as$field=>$length){if(strlen($row[$field])!==self::uint($plan[$length])){throw new RuntimeException('recovery_spaces_row_changed');}}
      if(isset($out[$row['phid']])){throw new RuntimeException('recovery_spaces_duplicate_identity');}$out[$row['phid']]=$row;$default+=(int)($row['isDefaultNamespace']===1);
    }
    if($default>1||($out&&$default!==1)){throw new RuntimeException('recovery_spaces_default_unknown');}ksort($out);return$out;
  }
  private static function snapshot($conn){
    PhorgeRecoveryRestoreProfile::assertOwnedWriter($conn);$db=queryfx_one($conn,'SELECT DATABASE() db')['db'];
    if(!is_string($db)||!preg_match('/\A(governance_native_[a-f0-9]{16})_user\z/D',$db,$m)){throw new RuntimeException('recovery_spaces_route_unknown');}
    $schema=$m[1].'_spaces';self::schema($conn,$schema);$rows=self::readRows($conn,$schema);
    return array('selected_rows'=>$rows,'query_request_cache'=>self::cacheFacts($rows));
  }
  public static function capture(AphrontDatabaseConnection$conn){$value=new self();$value->connection=$conn;$value->key=random_bytes(32);$value->digest=hash_hmac('sha256',serialize(self::snapshot($conn)),$value->key);return$value;}
  public function finish(){
    if($this->finished){throw new RuntimeException('recovery_spaces_finished');}$this->finished=true;
    try{if(!hash_equals($this->digest,hash_hmac('sha256',serialize(self::snapshot($this->connection)),$this->key))){throw new RuntimeException('recovery_spaces_facts_changed');}
      return array('profile'=>'selected-native-space-row-and-query-cache-facts-v1','actual_SQL_reads_executed'=>true,'request_cache_policy_correctness_proved'=>false,'implicit_default_is_authorization'=>false,'history_is_current_policy'=>false,'transaction_history_captured'=>false,'object_space_bindings_complete'=>false,'dynamic_policy_dependencies_complete'=>false,'absence_lease_proved'=>false,'producer_lease_proved'=>false,'continuous_cache_or_ABA_lease_proved'=>false,'complete'=>false,'all_sources_complete'=>false,'restoration_authorized'=>false);}
    finally{$this->connection=null;$this->key=null;$this->digest=null;}
  }
}
