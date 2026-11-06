<?php
/** Author-rooted native File facts prototype. Never a File policy assessment. */
final class PhorgeRecoveryFileSourceFacts extends Phobject {
  const MAX_FILES=64;
  const MAX_RELATIONS=256;
  const MAX_RELATIONS_PER_QUERY=128;
  const MAX_BYTES=65536;
  private $scope;private $subject;private $key;private $digest;private $finished=false;
  private $parentViewer;
  private function __construct(){}
  private function __clone(){}
  public function __serialize(){throw new RuntimeException('recovery_file_serialization_refused');}
  public function __unserialize(array$value){throw new RuntimeException('recovery_file_serialization_refused');}
  public function __debugInfo(){return array('scope'=>'partial-author-rooted-File-facts');}
  private static function phid($value,$type){
    if(!is_string($value)||!in_array($type,array('USER','FILE','TASK'),true)||!preg_match('/\APHID-'.$type.'-[a-zA-Z0-9]{20}\z/D',$value)){throw new RuntimeException('recovery_file_identity_unknown');}return$value;
  }
  private static function uint($value){
    if((!is_string($value)&&!is_int($value))||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',(string)$value)||(float)$value>4294967295){throw new RuntimeException('recovery_file_integer_unknown');}return(int)$value;
  }
  private static function columns($table){
    switch($table){
      case'file':return array('id','phid','authorPHID','viewPolicy','isPartial','isDeleted','dateModified');
      case'file_attachment':return array('id','objectPHID','filePHID','attacherPHID','attachmentMode','dateModified');
      case'file_transformedfile':return array('id','originalPHID','transform','transformedPHID');
    }throw new RuntimeException('recovery_file_table_unknown');
  }
  private static function keys($row,$table){
    if(!is_array($row)){throw new RuntimeException('recovery_file_row_unknown');}
    $keys=array_keys($row);sort($keys);$wanted=self::columns($table);sort($wanted);
    if($keys!==$wanted){throw new RuntimeException('recovery_file_row_unknown');}
  }
  private static function queryRows(array$rows){if(count($rows)>self::MAX_RELATIONS_PER_QUERY){throw new RuntimeException('recovery_file_query_truncated');}return$rows;}
  private static function relationBudget(array$attachments,array$transforms){if(count($attachments)+count($transforms)>self::MAX_RELATIONS){throw new RuntimeException('recovery_file_row_budget');}}
  private static function normalize(array$files,array$attachments,array$transforms,$subject,$parent_endpoints=false){
    self::phid($subject,'USER');if(count($files)>self::MAX_FILES||count($attachments)+count($transforms)>self::MAX_RELATIONS){throw new RuntimeException('recovery_file_row_budget');}
    $out=array('files'=>array(),'attachments'=>array(),'transforms'=>array());$selected=array();$bytes=0;
    foreach($files as$row){
      self::keys($row,'file');$row['id']=self::uint($row['id']);$row['dateModified']=self::uint($row['dateModified']);self::phid($row['phid'],'FILE');
      if(!$row['id']||isset($out['files'][$row['id']])||isset($selected[$row['phid']])||$row['authorPHID']!==$subject){throw new RuntimeException('recovery_file_author_root_changed');}
      if(!is_string($row['viewPolicy'])||strlen($row['viewPolicy'])>64){throw new RuntimeException('recovery_file_policy_unknown');}
      foreach(array('isPartial','isDeleted')as$field){$row[$field]=self::uint($row[$field]);if($row[$field]>1){throw new RuntimeException('recovery_file_flag_unknown');}}
      $out['files'][$row['id']]=$row;$selected[$row['phid']]=true;
    }
    foreach($attachments as$row){
      self::keys($row,'file_attachment');$row['id']=self::uint($row['id']);$row['dateModified']=self::uint($row['dateModified']);self::phid($row['filePHID'],'FILE');
      // This slice observes Task attachment references, never arbitrary providers.
      if($parent_endpoints){if(!is_string($row['objectPHID'])||!preg_match('/\APHID-[A-Z]{4}-[a-zA-Z0-9]{20}\z/D',$row['objectPHID'])){throw new RuntimeException('recovery_file_identity_unknown');}}else{self::phid($row['objectPHID'],'TASK');}if($row['attacherPHID']!==null){self::phid($row['attacherPHID'],'USER');}
      if(!$row['id']||!isset($selected[$row['filePHID']])||isset($out['attachments'][$row['id']])||!in_array($row['attachmentMode'],array('attach','reference','detach'),true)){throw new RuntimeException('recovery_file_attachment_unknown');}
      $out['attachments'][$row['id']]=$row;
    }
    foreach($transforms as$row){
      self::keys($row,'file_transformedfile');$row['id']=self::uint($row['id']);self::phid($row['transformedPHID'],'FILE');
      if($row['originalPHID']!==PhabricatorPHIDConstants::PHID_VOID){self::phid($row['originalPHID'],'FILE');}
      if(!$row['id']||!is_string($row['transform'])||strlen($row['transform'])>512||preg_match('/[\x00-\x1f\x7f]/',$row['transform'])||(!isset($selected[$row['originalPHID']])&&!isset($selected[$row['transformedPHID']]))){throw new RuntimeException('recovery_file_transform_unknown');}
      if(isset($out['transforms'][$row['id']])){if($out['transforms'][$row['id']]!==$row){throw new RuntimeException('recovery_file_transform_identity_changed');}continue;}
      $out['transforms'][$row['id']]=$row;
    }
    foreach($out as&$rows){ksort($rows,SORT_NUMERIC);foreach($rows as$row){$bytes+=128;foreach($row as$value){if(is_string($value)){$bytes+=strlen($value);}}if($bytes>self::MAX_BYTES){throw new RuntimeException('recovery_file_byte_budget');}}}unset($rows);
    // Detect cycles in the observed transform graph; external frontier is not
    // recursively resolved and therefore remains an explicit coverage blocker.
    $edges=array();foreach($out['transforms']as$row){if($row['originalPHID']!==PhabricatorPHIDConstants::PHID_VOID){$edges[$row['originalPHID']][]=$row['transformedPHID'];}}
    $done=array();$active=array();$visit=function($node)use(&$visit,&$done,&$active,$edges){if(isset($active[$node])){throw new RuntimeException('recovery_file_transform_cycle');}if(isset($done[$node])){return;}$active[$node]=true;foreach(($edges[$node]??array())as$next){$visit($next);}unset($active[$node]);$done[$node]=true;};foreach(array_keys($edges)as$node){$visit($node);}
    // All values are now bounded primitives; serialization cannot invoke user callbacks.
    if(strlen(serialize($out))>self::MAX_BYTES){throw new RuntimeException('recovery_file_byte_budget');}
    return$out;
  }
  /** Pure untrusted-row controls only; never accepts a caller completeness bit. */
  public static function describeUntrustedRows(array$files,array$attachments,array$transforms,$subject){self::normalize($files,$attachments,$transforms,$subject);return self::summary(false);}
  private static function summary($SQL){return array('scope'=>'author-rooted-File-and-Task-attachment-topology-only','actual_SQL_reads_executed'=>$SQL,'native_policy_interpreter_proved'=>false,'account_admission_proved'=>false,'metadata_profile_builtin_interpreted'=>false,'parent_objects_authorized'=>false,'transform_providers_interpreted'=>false,'all_subject_visible_files_covered'=>false,'secret_URI_download_capabilities_covered'=>false,'raw_producer_or_absence_lease_proved'=>false,'DDL_privilege_and_fault_matrix_proved'=>false,'complete'=>false,'all_sources_complete'=>false,'restoration_authorized'=>false);}
  private static function schema($conn,$schema){
    $specs=array('file'=>array('PRIMARY'=>array(array('id'),0),'phid'=>array(array('phid'),0),'authorPHID'=>array(array('authorPHID'),1)),
      'file_attachment'=>array('PRIMARY'=>array(array('id'),0),'key_object'=>array(array('objectPHID','filePHID'),0),'key_file'=>array(array('filePHID'),1)),
      'file_transformedfile'=>array('PRIMARY'=>array(array('id'),0),'originalPHID'=>array(array('originalPHID','transform'),0),'transformedPHID'=>array(array('transformedPHID'),1)));
    // Acquire all selected table MDL before metadata observations.
    foreach($specs as$table=>$unused){queryfx_all($conn,'SELECT id FROM %T.%T WHERE 1=0 LOCK IN SHARE MODE',$schema,$table);}
    foreach($specs as$table=>$indexes){
      $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$schema,$table);if(!$engine||$engine['ENGINE']!=='InnoDB'){throw new RuntimeException('recovery_file_engine_unknown');}
      foreach($indexes as$index=>$expected){$rows=queryfx_all($conn,'SELECT COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME=%s ORDER BY SEQ_IN_INDEX',$schema,$table,$index);if(count($rows)!==count($expected[0])){throw new RuntimeException('recovery_file_index_unknown');}foreach($rows as$i=>$row){if($row['COLUMN_NAME']!==$expected[0][$i]||self::uint($row['NON_UNIQUE'])!==$expected[1]||self::uint($row['SEQ_IN_INDEX'])!==$i+1||$row['SUB_PART']!==null){throw new RuntimeException('recovery_file_index_unknown');}}}
      $columns=queryfx_all($conn,'SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_OCTET_LENGTH,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$schema,$table);$needed=array_fill_keys(self::columns($table),true);
      foreach($columns as$row){$name=$row['COLUMN_NAME'];if(!isset($needed[$name])){continue;}unset($needed[$name]);
        $nullable=($table==='file'&&$name==='authorPHID')||$name==='attacherPHID';if($row['IS_NULLABLE']!==($nullable?'YES':'NO')){throw new RuntimeException('recovery_file_column_unknown');}
        if(in_array($name,array('id','dateModified'),true)){$ok=$row['DATA_TYPE']==='int'&&is_string($row['COLUMN_TYPE'])&&preg_match('/\Aint(?:\(10\))? unsigned\z/D',$row['COLUMN_TYPE']);}
        elseif(in_array($name,array('isPartial','isDeleted'),true)){$ok=$row['DATA_TYPE']==='tinyint';}
        elseif(in_array($name,array('attachmentMode','transform'),true)){$length=$name==='attachmentMode'?32:128;$ok=$row['DATA_TYPE']==='varchar'&&self::uint($row['CHARACTER_MAXIMUM_LENGTH'])===$length&&self::uint($row['CHARACTER_OCTET_LENGTH'])<=$length*4;}
        else{$ok=$row['DATA_TYPE']==='varbinary'&&self::uint($row['CHARACTER_OCTET_LENGTH'])===64;}
        if(!$ok){throw new RuntimeException('recovery_file_column_unknown');}
      }if($needed){throw new RuntimeException('recovery_file_column_unknown');}
    }
  }
  private static function snapshot(PhorgeRecoveryOwnedRRScope$scope,$subject,$parent_endpoints=false){
    self::phid($subject,'USER');$scope->getFacts();$conn=(new PhabricatorUser())->establishConnection('w');PhorgeRecoveryRestoreProfile::assertOwnedWriter($conn);
    $db=queryfx_one($conn,'SELECT DATABASE() db')['db'];if(!is_string($db)||!preg_match('/\A(governance_native_[a-f0-9]{16})_user\z/D',$db,$m)){throw new RuntimeException('recovery_file_route_unknown');}
    $plan=queryfx_one($conn,'SELECT id FROM %T.user FORCE INDEX (phid) WHERE phid=%s',$m[1].'_user',$subject);if(!$plan||self::uint($plan['id'])<1){throw new RuntimeException('recovery_file_subject_unknown');}
    $user=queryfx_one($conn,'SELECT id,phid FROM %T.user FORCE INDEX (PRIMARY) WHERE id=%d LOCK IN SHARE MODE',$m[1].'_user',$plan['id']);if(!$user||self::uint($user['id'])!==self::uint($plan['id'])||$user['phid']!==$subject){throw new RuntimeException('recovery_file_subject_changed');}
    $schema=$m[1].'_file';self::schema($conn,$schema);
    // Fixed scalar columns only. Metadata, secretKey, mailKey, storageHandle and
    // file payload are deliberately not loaded or interpreted by this slice.
    $files=queryfx_all($conn,'SELECT id,phid,authorPHID,viewPolicy,isPartial,isDeleted,dateModified FROM %T.file FORCE INDEX (authorPHID) WHERE authorPHID=%s ORDER BY id LIMIT 65 LOCK IN SHARE MODE',$schema,$subject);
    self::normalize($files,array(),array(),$subject);$phids=ipull($files,'phid','phid');ksort($phids,SORT_STRING);$attachments=array();$transforms=array();
    foreach($phids as$phid){
      $part=queryfx_all($conn,'SELECT id,objectPHID,filePHID,attacherPHID,attachmentMode,dateModified FROM %T.file_attachment FORCE INDEX (key_file) WHERE filePHID=%s ORDER BY id LIMIT 129 LOCK IN SHARE MODE',$schema,$phid);$attachments=array_merge($attachments,self::queryRows($part));self::relationBudget($attachments,$transforms);
      foreach(array('originalPHID','transformedPHID')as$direction){$part=queryfx_all($conn,'SELECT id,originalPHID,transform,transformedPHID FROM %T.file_transformedfile FORCE INDEX (%T) WHERE %T=%s ORDER BY id LIMIT 129 LOCK IN SHARE MODE',$schema,$direction,$direction,$phid);$transforms=array_merge($transforms,self::queryRows($part));self::relationBudget($attachments,$transforms);}
      // Global budget before issuing another per-file dependency query.
      self::normalize($files,$attachments,$transforms,$subject,$parent_endpoints);
    }
    $facts=array('subject'=>$user,'source'=>self::normalize($files,$attachments,$transforms,$subject,$parent_endpoints));$scope->getFacts();return$facts;
  }
  /**
   * Bounded roots, endpoint observations only. Native policy queries use other
   * DAO connections and may consult Spaces/custom policies. They are NOT the
   * User writer snapshot and may not enter the durable RC review assessment.
   * No caller callbacks, preauthorized objects or claimed completeness accepted.
   */
  public static function captureParentPolicies(
    PhorgeRecoveryOwnedRRScope $scope,
    $subject,
    PhabricatorUser $viewer) {
    $self = new self();
    $self->scope = $scope;
    $self->subject = $subject;
    $self->parentViewer = $viewer;
    $self->key = random_bytes(32);
    $self->digest = hash_hmac('sha256', serialize(
      self::parentSnapshot($scope, $subject, $viewer)), $self->key);
    return $self;
  }

  private static function exactPolicyResult($rows, $class, $phid) {
    if (!is_array($rows) || count($rows) > 1) {
      throw new RuntimeException('recovery_file_parent_query_result_unknown');
    }
    foreach ($rows as $row) {
      if (!is_object($row) || get_class($row) !== $class ||
          $row->getPHID() !== $phid) {
        throw new RuntimeException('recovery_file_parent_query_identity_changed');
      }
    }
    return $rows ? reset($rows) : null;
  }

  /** Native User getters cast flags to bool; validate their stored value too. */
  private static function canonicalViewerFlag(PhabricatorUser $viewer, $flag) {
    $raw = (new ReflectionProperty(PhabricatorUser::class, $flag))->getValue($viewer);
    $value = $viewer->{'get'.ucfirst($flag)}();
    $canonical = array(0, 1, '0', '1', false, true);
    if (!in_array($raw, $canonical, true) ||
        !in_array($value, $canonical, true) || (int)$raw !== (int)$value) {
      throw new RuntimeException('recovery_file_parent_viewer_refused');
    }
    return (int)$raw;
  }

  private static function parentSnapshot(
    PhorgeRecoveryOwnedRRScope $scope,
    $subject,
    PhabricatorUser $viewer) {
    // Native File author automatic capability does not enforce account
    // admission. Refuse anonymous, omnipotent and stale/disabled viewer objects
    // before querying any policy-aware File/Task source.
    if (get_class($viewer) !== PhabricatorUser::class ||
        !$viewer->isLoggedIn() || $viewer->isOmnipotent()) {
      throw new RuntimeException('recovery_file_parent_viewer_refused');
    }
    $viewer_phid = self::phid($viewer->getPHID(), 'USER');
    $scope->getFacts();
    $conn = (new PhabricatorUser())->establishConnection('w');
    PhorgeRecoveryRestoreProfile::assertOwnedWriter($conn);
    $db = queryfx_one($conn, 'SELECT DATABASE() db')['db'];
    if (!is_string($db) || !preg_match(
      '/\A(governance_native_[a-f0-9]{16})_user\z/D', $db, $match)) {
      throw new RuntimeException('recovery_file_parent_route_unknown');
    }
    $account = queryfx_one($conn,
      'SELECT id,phid,isAdmin,isApproved,isDisabled,isSystemAgent,isMailingList '.
      'FROM %T.user FORCE INDEX (phid) WHERE phid=%s LOCK IN SHARE MODE',
      $match[1].'_user', $viewer_phid);
    if (!$account || self::uint($account['id']) < 1 ||
        $account['phid'] !== $viewer_phid) {
      throw new RuntimeException('recovery_file_parent_viewer_refused');
    }
    // Native GlobalPolicy's admin rule reads this object flag directly. Bind
    // the locked current SQL flag and reject a stale or noncanonical object;
    // do not change the native rule or silently refresh the caller's viewer.
    $viewer_admin = self::canonicalViewerFlag($viewer, 'isAdmin');
    if (!in_array($account['isAdmin'] ?? null, array(0, 1, '0', '1'), true) ||
        !in_array($viewer_admin, array(0, 1, '0', '1', false, true), true) ||
        (int)$account['isAdmin'] !== (int)$viewer_admin) {
      throw new RuntimeException('recovery_file_parent_viewer_refused');
    }
    $account['isAdmin'] = (int)$account['isAdmin'];
    foreach (array('isApproved'=>1, 'isDisabled'=>0, 'isSystemAgent'=>0,
      'isMailingList'=>0) as $flag=>$required) {
      $object_flag = self::canonicalViewerFlag($viewer, $flag);
      if (!in_array($account[$flag], array(0, 1, '0', '1'), true) ||
          !in_array($object_flag, array(0, 1, '0', '1', false, true), true) ||
          (int)$account[$flag] !== $required ||
          (int)$object_flag !== $required) {
        throw new RuntimeException('recovery_file_parent_viewer_refused');
      }
      $account[$flag] = $required;
    }
    $account['id'] = self::uint($account['id']);
    $source = self::snapshot($scope, $subject, true);
    $parents = array();
    $by_file = array();
    $unsupported_parents = array();
    $transformed = array();
    foreach ($source['source']['transforms'] as $edge) {
      $transformed[$edge['originalPHID']] = true;
      $transformed[$edge['transformedPHID']] = true;
    }
    foreach ($source['source']['attachments'] as $edge) {
      if ($edge['attachmentMode'] === 'attach') {
        if (strncmp($edge['objectPHID'], 'PHID-TASK-', 10) !== 0) {
          $unsupported_parents[$edge['filePHID']] = true;
          continue;
        }
        $parents[$edge['objectPHID']] = true;
        $by_file[$edge['filePHID']][$edge['objectPHID']] = true;
      }
    }
    ksort($parents, SORT_STRING);
    // Raw locked rows bind denial/deletion and policy changes even when a
    // viewer-aware query omits a parent. Fixed PHID lookups, never a broad scan.
    $task_rows = array();
    $task_observations = array();
    foreach ($parents as $phid=>$unused) {
      $row = queryfx_one($conn,
        'SELECT id,phid,ownerPHID,viewPolicy,editPolicy,spacePHID,dateModified '.
        'FROM %T.maniphest_task FORCE INDEX (phid) WHERE phid=%s '.
        'LOCK IN SHARE MODE', $match[1].'_maniphest', $phid);
      if ($row) {
        if ($row['phid'] !== $phid || self::uint($row['id']) < 1) {
          throw new RuntimeException('recovery_file_parent_identity_changed');
        }
        $row['id'] = self::uint($row['id']);
        $row['dateModified'] = self::uint($row['dateModified']);
        foreach (array('ownerPHID', 'viewPolicy', 'editPolicy', 'spacePHID') as $key) {
          if ($row[$key] !== null &&
              (!is_string($row[$key]) || strlen($row[$key]) > 64)) {
            throw new RuntimeException('recovery_file_parent_value_unknown');
          }
        }
      }
      $task_rows[$phid] = $row;
      if (strlen(serialize($task_rows)) > self::MAX_BYTES) {
        throw new RuntimeException('recovery_file_parent_byte_budget');
      }
      $task = self::exactPolicyResult(
        (new ManiphestTaskQuery())->setViewer($viewer)
          ->withPHIDs(array($phid))->setLimit(2)
          ->setRaisePolicyExceptions(false)->execute(),
        ManiphestTask::class, $phid);
      if ($task && (!$row || self::uint($task->getID()) !== $row['id'] ||
          $task->getOwnerPHID() !== $row['ownerPHID'] ||
          $task->getViewPolicy() !== $row['viewPolicy'] ||
          $task->getEditPolicy() !== $row['editPolicy'] ||
          $task->getSpacePHID() !== $row['spacePHID'] ||
          self::uint($task->getDateModified()) !== $row['dateModified'])) {
        throw new RuntimeException('recovery_file_parent_native_rows_changed');
      }
      $task_observations[$phid] = $task ? 'admitted' : 'denied';
    }
    $observations = array();
    foreach ($source['source']['files'] as $file) {
      $phid = $file['phid'];
      $attached = $by_file[$phid] ?? array();
      ksort($attached, SORT_STRING);
      $state = $file['isDeleted'] ? 'denied' : 'unsupported';
      // Transform recursion, profile/builtin metadata and other providers are
      // outside this slice. No attachment is not an absence/authorization proof.
      if ($attached && !isset($unsupported_parents[$phid]) &&
          !isset($transformed[$phid]) &&
          !$file['isPartial'] && !$file['isDeleted']) {
        $native = self::exactPolicyResult(
          (new PhabricatorFileQuery())->setViewer($viewer)
            ->withPHIDs(array($phid))->withIsDeleted(false)->setLimit(2)
            ->setRaisePolicyExceptions(false)->execute(),
          PhabricatorFile::class, $phid);
        if ($native && ($native->getAuthorPHID() !== $subject ||
            self::uint($native->getID()) !== $file['id'] ||
            $native->getViewPolicy() !== $file['viewPolicy'] ||
            self::uint($native->getDateModified()) !== $file['dateModified'] ||
            self::uint($native->getIsPartial()) !== $file['isPartial'] ||
            self::uint($native->getIsDeleted()) !== 0)) {
          throw new RuntimeException('recovery_file_parent_native_rows_changed');
        }
        $state = $native ? 'admitted' : 'denied';
        if ($native && ($native->isBuiltin() || $native->getIsProfileImage())) {
          $state = $file['isDeleted'] ? 'denied' : 'unsupported';
        }
      }
      $observations[$phid] = array('state'=>$state,
        'Task_parents'=>array_intersect_key($task_observations, $attached));
    }
    ksort($observations, SORT_STRING);
    if (strlen(serialize(array($task_rows, $observations))) > self::MAX_BYTES) {
      throw new RuntimeException('recovery_file_parent_byte_budget');
    }
    $scope->getFacts();
    $facts = array('version'=>2, 'mode'=>'native-policy-endpoints-current-account-flags-not-owned-snapshot',
      'viewer'=>$account, 'source'=>$source, 'Task_rows'=>$task_rows,
      'observations'=>$observations);
    if (strlen(serialize($facts)) > self::MAX_BYTES) {
      throw new RuntimeException('recovery_file_parent_byte_budget');
    }
    return $facts;
  }

  public function finishParentPolicies() {
    if ($this->finished || $this->parentViewer === null) {
      throw new RuntimeException('recovery_file_parent_finished');
    }
    $this->finished = true;
    try {
      $facts = self::parentSnapshot($this->scope, $this->subject, $this->parentViewer);
      if (!hash_equals($this->digest,
          hash_hmac('sha256', serialize($facts), $this->key))) {
        throw new RuntimeException('recovery_file_parent_review_stale');
      }
      return array('scope'=>'author-rooted-File-attached-Task-policy-endpoints-v2-current-account-flags',
        'review_binding'=>hash('sha256', serialize($facts)),
        'observations'=>$facts['observations'],
        'native_policy_queries_executed'=>(bool)$facts['Task_rows'],
        'current_account_flags_bound'=>array('isAdmin','isApproved','isDisabled','isSystemAgent','isMailingList'),
        'same_transaction_snapshot_proved'=>false,
        'durable_review_compatible'=>false, 'dependency_budget_proved'=>false,
        'all_object_classes_covered'=>false, 'all_sources_complete'=>false,
        'complete'=>false, 'restoration_authorized'=>false,
        'execution_blocked'=>true);
    } finally {
      $this->scope = null; $this->subject = null; $this->parentViewer = null;
      $this->key = null; $this->digest = null;
    }
  }
  public static function capture(PhorgeRecoveryOwnedRRScope$scope,$subject){$self=new self();$self->scope=$scope;$self->subject=$subject;$self->key=random_bytes(32);$self->digest=hash_hmac('sha256',serialize(self::snapshot($scope,$subject)),$self->key);return$self;}
  public function finish(){if($this->parentViewer!==null){throw new RuntimeException('recovery_file_parent_finish_required');}if($this->finished){throw new RuntimeException('recovery_file_finished');}$this->finished=true;try{if(!hash_equals($this->digest,hash_hmac('sha256',serialize(self::snapshot($this->scope,$this->subject)),$this->key))){throw new RuntimeException('recovery_file_rows_changed');}return self::summary(true);}finally{$this->scope=null;$this->subject=null;$this->key=null;$this->digest=null;}}
}
