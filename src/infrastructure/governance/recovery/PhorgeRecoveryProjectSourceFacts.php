<?php

/** Subject-centric current row facts, not a Project policy interpreter or lease. */
final class PhorgeRecoveryProjectSourceFacts extends Phobject {
  const MAX_PROJECTS=128;
  const MAX_EDGES=256;
  const MAX_HISTORY=64;
  const MAX_BYTES=524288;
  const MAX_NODES=8192;
  const MAX_DEPTH=16;
  private $connection;
  private $subject;
  private $key;
  private $digest;
  private $finished=false;
  private function __construct() {}
  private function __clone() {}
  public function __serialize(){throw new RuntimeException('recovery_project_serialization_refused');}
  public function __unserialize(array $data){throw new RuntimeException('recovery_project_serialization_refused');}
  public function __debugInfo(){return array('scope'=>'partial-project-source-facts');}

  private static function phid($value,$type){
    $lengths=array('USER'=>20,'PROJ'=>20,'SPCE'=>20,'XACT-USER'=>15,'XACT-PROJ'=>15);
    if(!is_string($type)||!isset($lengths[$type])||!is_string($value)||!preg_match('/\APHID-'.$type.'-[a-zA-Z0-9]{'.$lengths[$type].'}\z/D',$value)){
      throw new RuntimeException('recovery_project_identity_unknown');}
    return$value;
  }
  private static function integer($value,$max=4294967295){
    if((!is_int($value)&&!is_string($value))||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',(string)$value)||(float)$value>$max){
      throw new RuntimeException('recovery_project_number_unknown');}
    return(int)$value;
  }
  private static function fields(array $row,array $keys){$a=array_keys($row);sort($a);sort($keys);if($a!==$keys){throw new RuntimeException('recovery_project_shape_unknown');}}
  private static function primitiveBudget($value,$depth,&$nodes,&$bytes){
    if($depth>self::MAX_DEPTH||++$nodes>self::MAX_NODES){throw new RuntimeException('recovery_project_budget');}
    $bytes+=32;
    if(is_array($value)){
      if(count($value)>self::MAX_NODES){throw new RuntimeException('recovery_project_budget');}
      foreach($value as$key=>$item){
        // Do not stringify objects, call magic methods or traverse iterators.
        if(!is_int($key)&&!is_string($key)){throw new RuntimeException('recovery_project_primitive_unknown');}
        $bytes+=32+strlen((string)$key);self::primitiveBudget($item,$depth+1,$nodes,$bytes);
      }
    }elseif(is_string($value)){$bytes+=strlen($value);}
    elseif(is_int($value)||is_bool($value)||$value===null){$bytes+=24;}
    else{throw new RuntimeException('recovery_project_primitive_unknown');}
    if($bytes>self::MAX_BYTES){throw new RuntimeException('recovery_project_budget');}
  }
  private static function budget(array $snapshot){
    $nodes=0;$bytes=0;self::primitiveBudget($snapshot,0,$nodes,$bytes);return$bytes;
  }
  private static function profile(){return array('id','phid','status','parentProjectPHID','milestoneNumber',
    'projectPath','projectPathKey','projectDepth','hasSubprojects','hasMilestones','viewPolicy','editPolicy','joinPolicy','spacePHID','subtype','isMembershipLocked');}

  /** Pure validation of untrusted rows cannot become runtime or permission evidence. */
  public static function describeUntrustedRows($subject,array $snapshot){
    self::analyze($subject,$snapshot);return self::summary(false);
  }
  private static function summary($actual){return array('schema_version'=>1,'scope'=>'subject-project-source-dictionaries-only',
    'reason'=>'current-row-and-selected-history-facts-not-source-completion',
    'actual_SQL_queries_executed'=>$actual,'direct_inverse_materialized_distinct'=>true,
    'selected_history_is_current_membership'=>false,'native_policy_interpreter_proved'=>false,'dynamic_provider_closure_proved'=>false,
    'SQL_absence_or_producer_lease'=>false,'DDL_or_privilege_lease'=>false,'continuous_or_ABA_lease'=>false,
    'all_sources_complete'=>false,'complete'=>false,'restoration_authorized'=>false);}

  private static function analyze($subject,array $x){
    self::phid($subject,'USER');self::fields($x,array('subject_row','projects','selected','children','user_edges','project_edges','history'));
    foreach($x as$value){if(!is_array($value)){throw new RuntimeException('recovery_project_shape_unknown');}}
    self::fields($x['subject_row'],array('id','phid'));
    if(self::integer($x['subject_row']['id'])<1||$x['subject_row']['phid']!==$subject){throw new RuntimeException('recovery_project_subject_unknown');}
    self::budget($x);$projects=$x['projects'];$selected=$x['selected'];
    if(count($projects)>self::MAX_PROJECTS||count($x['user_edges'])+count($x['project_edges'])>self::MAX_EDGES||count($x['history'])>self::MAX_HISTORY){throw new RuntimeException('recovery_project_budget');}
    $ids=array();$pathkeys=array();
    foreach($projects as$phid=>$row){
      self::phid($phid,'PROJ');self::fields($row,self::profile());
      if($row['phid']!==$phid||self::integer($row['id'])<1||!in_array(self::integer($row['status']),array(0,100),true)||
        !is_string($row['projectPathKey'])||strlen($row['projectPathKey'])!==4||!is_string($row['projectPath'])||
        !is_string($row['subtype'])||$row['subtype']!=='default'){
        throw new RuntimeException('recovery_project_row_unknown');}
      if(isset($ids[(string)$row['id']])||isset($pathkeys[$row['projectPathKey']])){throw new RuntimeException('recovery_project_duplicate_identity');}
      $ids[(string)$row['id']]=true;$pathkeys[$row['projectPathKey']]=true;
      foreach(array('hasSubprojects','hasMilestones','isMembershipLocked')as$flag){self::integer($row[$flag],1);}
      foreach(array('viewPolicy','editPolicy','joinPolicy')as$policy){if(!is_string($row[$policy])||strlen($row[$policy])>64){throw new RuntimeException('recovery_project_policy_unknown');}}
      if($row['spacePHID']!==null){self::phid($row['spacePHID'],'SPCE');}
      if($row['milestoneNumber']!==null){if(self::integer($row['milestoneNumber'])<1||$row['parentProjectPHID']===null){throw new RuntimeException('recovery_project_milestone_unknown');}}
      $seen=array();$cursor=$phid;$depth=0;
      while($cursor!==null){
        if(isset($seen[$cursor])||$depth>=16||!isset($projects[$cursor])){throw new RuntimeException('recovery_project_parent_unknown');}
        $seen[$cursor]=true;$parent=$projects[$cursor]['parentProjectPHID'];
        if($parent!==null){self::phid($parent,'PROJ');if(!isset($projects[$parent])||$projects[$parent]['milestoneNumber']!==null){throw new RuntimeException('recovery_project_parent_unknown');}}
        $cursor=$parent;$depth++;
      }
      $parent=$row['parentProjectPHID'];$want=($parent===null?'':$projects[$parent]['projectPath']).$row['projectPathKey'];
      if($row['projectPath']!==$want||self::integer($row['projectDepth'],15)!==$depth-1){throw new RuntimeException('recovery_project_path_unknown');}
    }
    foreach($selected as$phid=>$value){if($value!==true||!isset($projects[$phid])||!array_key_exists($phid,$x['children'])){throw new RuntimeException('recovery_project_selection_unknown');}}
    foreach($x['children']as$parent=>$children){
      if(!isset($selected[$parent])||!array_is_list($children)||count($children)!==count(array_unique($children))){throw new RuntimeException('recovery_project_children_unknown');}
      $sub=false;$mile=false;
      foreach($children as$child){if(!isset($projects[$child])||$projects[$child]['parentProjectPHID']!==$parent){throw new RuntimeException('recovery_project_children_unknown');}
        if($projects[$child]['milestoneNumber']===null){$sub=true;}else{$mile=true;}}
      if(self::integer($projects[$parent]['hasSubprojects'],1)!==(int)$sub||self::integer($projects[$parent]['hasMilestones'],1)!==(int)$mile){throw new RuntimeException('recovery_project_flags_inconsistent');}
    }
    $sets=array();$seen=array();
    foreach(array('user_edges','project_edges')as$group){foreach($x[$group]as$edge){
      self::fields($edge,array('src','type','dst','dateCreated','seq','dataID'));$type=self::integer($edge['type']);
      self::integer($edge['dateCreated']);self::integer($edge['seq']);
      if($edge['dataID']!==null&&self::integer($edge['dataID'])!==0){throw new RuntimeException('recovery_project_edge_data_unsupported');}
      if($group==='user_edges'){$project=$edge['dst'];if($edge['src']!==$subject||!in_array($type,array(14,22,48),true)){throw new RuntimeException('recovery_project_edge_unknown');}}
      else{$project=$edge['src'];if($edge['dst']!==$subject||!in_array($type,array(13,21,47,60,61),true)){throw new RuntimeException('recovery_project_edge_unknown');}}
      self::phid($project,'PROJ');if(!isset($selected[$project])){throw new RuntimeException('recovery_project_selection_unknown');}
      $id=$group.':'.$type.':'.$project;if(isset($seen[$id])){throw new RuntimeException('recovery_project_duplicate_edge');}$seen[$id]=true;
      $sets[$type][$project]=true;
    }}
    foreach(array(array(13,14),array(21,22),array(47,48))as$pair){$a=array_keys($sets[$pair[0]]??array());$b=array_keys($sets[$pair[1]]??array());sort($a);sort($b);if($a!==$b){throw new RuntimeException('recovery_project_inverse_inconsistent');}}
    foreach($selected as$phid=>$unused){
      $row=$projects[$phid];$want=false;
      if($row['milestoneNumber']!==null){$want=isset($sets[13][$row['parentProjectPHID']]);}
      else if(!self::integer($row['hasSubprojects'],1)){$want=isset($sets[13][$phid]);}
      else{foreach($sets[13]??array()as$direct=>$yes){$leaf=$projects[$direct];
        if($leaf['milestoneNumber']===null&&!self::integer($leaf['hasSubprojects'],1)&&$direct!==$phid&&str_starts_with($leaf['projectPath'],$row['projectPath'])){$want=true;}}}
      if($want!==isset($sets[60][$phid])){throw new RuntimeException('recovery_project_materialized_inconsistent');}
    }
    foreach($x['history']as$row){self::fields($row,array('id','phid','objectPHID','authorPHID','transactionType','oldValue','newValue','metadata','dateCreated'));
      if(self::integer($row['id'])<1){throw new RuntimeException('recovery_project_history_unknown');}
      self::integer($row['dateCreated']);self::phid($row['phid'],'XACT-'.($row['objectPHID']===$subject?'USER':'PROJ'));self::phid($row['authorPHID'],'USER');
      if(($row['objectPHID']!==$subject&&!isset($selected[$row['objectPHID']]))||!in_array($row['transactionType'],array('edge','project:parent','project:milestone'),true)){throw new RuntimeException('recovery_project_history_unknown');}
      foreach(array('oldValue','newValue','metadata')as$field){if(!is_string($row[$field])||strlen($row[$field])>16384){throw new RuntimeException('recovery_project_history_unknown');}}
    }
  }

  private static function schema($conn,$schema,$table,array $needed){
    queryfx_all($conn,'SELECT 1 FROM %T.%T WHERE 1=0 LOCK IN SHARE MODE',$schema,$table);
    $row=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$schema,$table);
    if(!$row||$row['ENGINE']!=='InnoDB'){throw new RuntimeException('recovery_project_schema_unknown');}
    foreach($needed as$name=>$columns){
      $rows=queryfx_all($conn,'SELECT COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME=%s ORDER BY SEQ_IN_INDEX',$schema,$table,$name);
      if(ipull($rows,'COLUMN_NAME')!==$columns){throw new RuntimeException('recovery_project_index_unknown');}
      foreach($rows as$r){if((string)$r['NON_UNIQUE']!==($name==='key_object'?'1':'0')){throw new RuntimeException('recovery_project_index_unknown');}}
    }
    if($table==='edge'){
      $rows=queryfx_all($conn,'SELECT COLUMN_NAME,COLUMN_TYPE,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s ORDER BY ORDINAL_POSITION',$schema,$table);
      if(ipull($rows,'COLUMN_NAME')!==array('src','type','dst','dateCreated','seq','dataID')){throw new RuntimeException('recovery_project_schema_unknown');}
      foreach($rows as$r){if(in_array($r['COLUMN_NAME'],array('src','dst'),true)&&($r['COLUMN_TYPE']!=='varbinary(64)'||$r['COLLATION_NAME']!==null)){throw new RuntimeException('recovery_project_schema_unknown');}}
    }
  }
  private static function snapshot($conn,$subject){
    PhorgeRecoveryRestoreProfile::assertOwnedWriter($conn);self::phid($subject,'USER');
    $db=queryfx_one($conn,'SELECT DATABASE() db')['db'];if(!preg_match('/\A(governance_native_[a-f0-9]{16})_user\z/D',$db,$m)){throw new RuntimeException('recovery_project_route_unknown');}$prefix=$m[1];
    foreach(array('project','user')as$app){self::schema($conn,$prefix.'_'.$app,'edge',array('PRIMARY'=>array('src','type','dst'),'key_dst'=>array('dst','type','src')));}
    self::schema($conn,$prefix.'_project','project',array('PRIMARY'=>array('id'),'key_phid'=>array('phid'),'key_milestone'=>array('parentProjectPHID','milestoneNumber')));
    foreach(array('project','user')as$app){self::schema($conn,$prefix.'_'.$app,$app.'_transaction',array('PRIMARY'=>array('id'),'key_object'=>array('objectPHID')));}
    self::schema($conn,$prefix.'_user','user',array('PRIMARY'=>array('id'),'phid'=>array('phid')));
    $plan=queryfx_one($conn,'SELECT id FROM %T.user FORCE INDEX (phid) WHERE phid=%s',$prefix.'_user',$subject);
    if(!$plan||self::integer($plan['id'])<1){throw new RuntimeException('recovery_project_subject_unknown');}
    $subject_row=queryfx_one($conn,'SELECT id,phid FROM %T.user FORCE INDEX (PRIMARY) WHERE id=%d LOCK IN SHARE MODE',$prefix.'_user',$plan['id']);
    if(!$subject_row||$subject_row['phid']!==$subject){throw new RuntimeException('recovery_project_subject_unknown');}
    $out=queryfx_all($conn,'SELECT * FROM %T.edge FORCE INDEX (PRIMARY) WHERE src=%s AND dst LIKE %s ORDER BY type,dst LIMIT 257 LOCK IN SHARE MODE',$prefix.'_user',$subject,'PHID-PROJ-%');
    $in=queryfx_all($conn,'SELECT * FROM %T.edge FORCE INDEX (key_dst) WHERE dst=%s ORDER BY type,src LIMIT 257 LOCK IN SHARE MODE',$prefix.'_project',$subject);
    if(count($out)+count($in)>self::MAX_EDGES){throw new RuntimeException('recovery_project_budget');}
    $x=array('subject_row'=>$subject_row,'projects'=>array(),'selected'=>array(),'children'=>array(),'user_edges'=>$out,'project_edges'=>$in,'history'=>array());
    $columns=implode(',',self::profile());
    $put=function(array$row)use(&$x){$phid=$row['phid'];if(isset($x['projects'][$phid])&&$x['projects'][$phid]!==$row){throw new RuntimeException('recovery_project_rows_changed');}$x['projects'][$phid]=$row;if(count($x['projects'])>self::MAX_PROJECTS){throw new RuntimeException('recovery_project_budget');}self::budget($x);};
    $load=function($phid)use($conn,$prefix,$columns,&$x,$put){self::phid($phid,'PROJ');if(!isset($x['projects'][$phid])){
      $rows=queryfx_all($conn,'SELECT %Q FROM %T.project FORCE INDEX (key_phid) WHERE phid=%s LIMIT 2 LOCK IN SHARE MODE',qsprintf($conn,$columns),$prefix.'_project',$phid);
      if(count($rows)!==1){throw new RuntimeException('recovery_project_missing');}$put($rows[0]);}return$x['projects'][$phid];};
    $select=function($phid)use(&$x,$load){$seen=array();while($phid!==null){if(isset($seen[$phid])||count($seen)>=16){throw new RuntimeException('recovery_project_parent_unknown');}$seen[$phid]=true;$row=$load($phid);$x['selected'][$phid]=true;$phid=$row['parentProjectPHID'];}};
    $seeds=array();foreach($out as$e){$seeds[$e['dst']]=true;}foreach($in as$e){$seeds[$e['src']]=true;}ksort($seeds,SORT_STRING);foreach($seeds as$phid=>$yes){$select($phid);}
    do{$pending=array_diff_key($x['selected'],$x['children']);ksort($pending,SORT_STRING);
      foreach($pending as$phid=>$yes){$rows=queryfx_all($conn,'SELECT %Q FROM %T.project FORCE INDEX (key_milestone) WHERE parentProjectPHID=%s ORDER BY milestoneNumber,id LIMIT 129 LOCK IN SHARE MODE',qsprintf($conn,$columns),$prefix.'_project',$phid);
        if(count($rows)>self::MAX_PROJECTS){throw new RuntimeException('recovery_project_budget');}$x['children'][$phid]=array();
        foreach($rows as$row){$put($row);$x['children'][$phid][]=$row['phid'];if($row['milestoneNumber']!==null){$select($row['phid']);}}
      }
    }while(array_diff_key($x['selected'],$x['children']));
    $groups=array('user'=>array($subject),'project'=>array_keys($x['selected']));
    $history_bytes=self::budget($x);
    foreach($groups as$app=>$ids){sort($ids,SORT_STRING);foreach($ids as$object){
      self::readHistory($conn,$prefix.'_'.$app,$app.'_transaction',$object,$x['history'],$history_bytes);
    }}
    self::analyze($subject,$x);return$x;
  }
  private static function historyLength($value){
    if((!is_int($value)&&!is_string($value))||!preg_match('/\A(?:0|[1-9][0-9]{0,4})\z/D',(string)$value)||(int)$value>16384){
      throw new RuntimeException('recovery_project_history_length_unknown');}
    return(int)$value;
  }
  private static function readHistory($conn,$schema,$table,$object,array &$history,&$bytes){
    $kind=$table==='project_transaction'?'PROJ':($table==='user_transaction'?'USER':null);
    $app=$kind==='PROJ'?'project':'user';
    if($kind===null||!is_string($schema)||!preg_match('/\Agovernance_native_[a-f0-9]{16}_'.$app.'\z/D',$schema)){
      throw new RuntimeException('recovery_project_history_route_unknown');}
    self::phid($object,$kind);
    // Current indexed shared locks and lengths precede every LONGTEXT fetch.
    $selected=queryfx_all($conn,'SELECT id,phid,objectPHID,transactionType,OCTET_LENGTH(oldValue) oldBytes,OCTET_LENGTH(newValue) newBytes,OCTET_LENGTH(metadata) metadataBytes FROM %T.%T FORCE INDEX (key_object) WHERE objectPHID=%s AND transactionType IN (%Ls) ORDER BY id LIMIT 65 LOCK IN SHARE MODE',
      $schema,$table,$object,array('edge','project:parent','project:milestone'));
    if(count($history)+count($selected)>self::MAX_HISTORY){throw new RuntimeException('recovery_project_budget');}
    $planned=$bytes;$plans=array();$seen=array();
    foreach($selected as$entry){
      self::fields($entry,array('id','phid','objectPHID','transactionType','oldBytes','newBytes','metadataBytes'));
      $id=self::integer($entry['id']);
      if($id<1||isset($seen[$id])||$entry['objectPHID']!==$object||!in_array($entry['transactionType'],array('edge','project:parent','project:milestone'),true)){
        throw new RuntimeException('recovery_project_history_identity_unknown');}
      self::phid($entry['phid'],'XACT-'.$kind);$seen[$id]=true;
      // Fixed conservative row/plan overhead plus exact payload byte lengths.
      $planned+=4096;
      foreach(array('oldBytes','newBytes','metadataBytes')as$key){$entry[$key]=self::historyLength($entry[$key]);$planned+=$entry[$key];}
      if($planned>self::MAX_BYTES){throw new RuntimeException('recovery_project_budget');}
      $plans[]=$entry;
    }
    // Validate the entire plan first; a bad later entry cannot trigger any payload read.
    $bytes=$planned;
    foreach($plans as$entry){
      $row=queryfx_one($conn,'SELECT id,phid,objectPHID,authorPHID,transactionType,oldValue,newValue,metadata,dateCreated FROM %T.%T FORCE INDEX (PRIMARY) WHERE id=%d AND objectPHID=%s AND OCTET_LENGTH(oldValue)=%d AND OCTET_LENGTH(newValue)=%d AND OCTET_LENGTH(metadata)=%d LOCK IN SHARE MODE',
        $schema,$table,$entry['id'],$object,$entry['oldBytes'],$entry['newBytes'],$entry['metadataBytes']);
      if(!$row){throw new RuntimeException('recovery_project_history_row_changed');}
      self::fields($row,array('id','phid','objectPHID','authorPHID','transactionType','oldValue','newValue','metadata','dateCreated'));
      if(self::integer($row['id'])!==self::integer($entry['id'])||$row['phid']!==$entry['phid']||$row['objectPHID']!==$object||$row['transactionType']!==$entry['transactionType']){
        throw new RuntimeException('recovery_project_history_row_changed');}
      foreach(array('oldValue'=>'oldBytes','newValue'=>'newBytes','metadata'=>'metadataBytes')as$field=>$key){
        if(!is_string($row[$field])||strlen($row[$field])!==$entry[$key]){throw new RuntimeException('recovery_project_history_row_changed');}}
      self::phid($row['authorPHID'],'USER');self::integer($row['dateCreated']);$history[]=$row;
    }
  }
  public static function capture(AphrontDatabaseConnection$conn,$subject){
    $snapshot=self::snapshot($conn,$subject);$facts=new self();$facts->connection=$conn;$facts->subject=$subject;$facts->key=random_bytes(32);
    $facts->digest=hash_hmac('sha256',serialize($snapshot),$facts->key);return$facts;
  }
  public function finish(){
    if($this->finished){throw new RuntimeException('recovery_project_facts_finished');}$this->finished=true;
    try{if(!hash_equals($this->digest,hash_hmac('sha256',serialize(self::snapshot($this->connection,$this->subject)),$this->key))){throw new RuntimeException('recovery_project_rows_changed');}return self::summary(true);}
    finally{$this->key=null;$this->digest=null;$this->subject=null;$this->connection=null;}
  }
}
