<?php

/** Fixed native workflow, borrowing only the actual locked People transaction. */
final class PhorgeOrganizationHandoverAdapter extends Phobject {
  private $service;
  private $connection;
  private $nonce;
  private $final = null;
  private function __construct() {}
  private function __clone() {}
  public function __serialize(){throw new RuntimeException('organization_transfer_serialization_refused');}
  public function __unserialize(array $data){throw new RuntimeException('organization_transfer_serialization_refused');}
  public function __debugInfo(){return array('scope'=>'native-approved-handover');}
  private static function property($service,$key){
    return (new ReflectionProperty(PhorgeOrganizationService::class,$key))->getValue($service);
  }
  private static function json($value){return PhorgeGovernanceOrganizationApprovalAdapter::canonicalJSON($value);}
  private static function digest($value){return hash('sha256',self::json($value));}
  private static function id($value){
    if(!PhorgeGovernanceOrganizationRequest::identifier($value,64)){
      throw new InvalidArgumentException('organization_transfer_identifier_refused');}
    return $value;
  }
  public static function forLockedService(PhorgeOrganizationService $service){
    PhorgeGovernanceNativeRuntimeProfile::assertCurrent();
    $adapter=new self();$adapter->service=WeakReference::create($service);
    $adapter->connection=self::property($service,'connection');
    $adapter->nonce=self::property($service,'transactionNonce');$adapter->actor();
    foreach(array(new PhorgeOrganizationTransfer(),new PhorgeOrganizationHandover(),
      new PhorgeOrganizationProjectDisposition(),new PhorgeOrganizationOutbox())as$table){
      queryfx_one($adapter->connection,'SELECT id FROM %R WHERE 1=0 LOCK IN SHARE MODE',$table);
      $engine=queryfx_one($adapter->connection,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table->getTableName());
      if(!$engine||$engine['ENGINE']!=='InnoDB'){throw new RuntimeException('organization_transfer_transactional_schema_required');}
    }
    return $adapter;
  }
  private function actor(){
    PhorgeOrganizationService::assertEnabled();$service=$this->service->get();
    if(!$service||!is_string($this->nonce)||!preg_match('/\A[a-f0-9]{32}\z/',$this->nonce)||
      self::property($service,'transactionNonce')!==$this->nonce||self::property($service,'connection')!==$this->connection||
      !($this->connection instanceof AphrontMySQLiDatabaseConnection)||
      get_class($this->connection)!==AphrontMySQLiDatabaseConnection::class||!$this->connection->isInsideTransaction()){
      throw new RuntimeException('organization_transfer_locked_transaction_required');}
    $snapshot=PhorgeGovernanceNativeIdentitySource::revalidate(self::property($service,'actorSnapshot'),$this->connection);
    if(!$snapshot->isSystemAdmin()){throw new RuntimeException('organization_transfer_current_admin_required');}
    $guard=queryfx_one($this->connection,'SELECT id,version FROM organization_guard WHERE id=1 FOR UPDATE');
    if(!$guard||(string)$guard['id']!=='1'||PhorgeOrganizationService::version($guard['version'])<1){
      throw new RuntimeException('organization_transfer_guard_required');}
    return $snapshot;
  }
  private function now(){return PhorgeOrganizationService::version(queryfx_one($this->connection,'SELECT UNIX_TIMESTAMP() n')['n']);}
  private static function sourceVersion($schema){
    if($schema==='native-organization-handover-v1'){return 1;}
    if($schema==='native-organization-handover-v2'){return 2;}
    throw new RuntimeException('organization_identity_binding_version_refused');
  }
  private function identity($phid,$eligible=false,$sourceVersion=2){
    if(!PhorgeGovernanceOrganizationRequest::userID($phid)){throw new RuntimeException('organization_transfer_identity_refused');}
    $accounts=self::property($this->service->get(),'accounts');
    if(!isset($accounts[$phid])||$accounts[$phid]['userPHID']!==$phid){throw new RuntimeException('organization_transfer_identity_plan_changed');}
    if($sourceVersion===1){$identity=PhorgeGovernanceNativeIdentitySource::lockIdentityPHID($phid,$this->connection);}
    else if($sourceVersion===2){$identity=PhorgeGovernanceNativeNoncredentialIdentitySource::lockIdentityPHID($phid,$this->connection);}
    else{throw new RuntimeException('organization_identity_binding_version_refused');}
    if($eligible&&!$identity['eligible']){throw new RuntimeException('organization_transfer_identity_ineligible');}
    return $identity;
  }
  private function credential(array $binding){
    $this->identity($binding['userPHID']??'',true,1);
    $snapshot=PhorgeGovernanceNativeIdentitySource::lockCredentialBinding($binding,$this->connection);
    if(!$snapshot->isSystemAdmin()){throw new RuntimeException('organization_transfer_current_admin_required');}
    return $snapshot;
  }
  private static function decode($text){
    if(!is_string($text)||strlen($text)>32768){throw new RuntimeException('organization_transfer_binding_refused');}
    $data=json_decode($text,true,16,JSON_THROW_ON_ERROR);
    if(!is_array($data)){throw new RuntimeException('organization_transfer_binding_refused');}
    self::json($data);return $data;
  }
  private function audit($op,$action,$unit,$subject,$before,$after){
    return(new ReflectionMethod(PhorgeOrganizationService::class,'audit'))->invoke($this->service->get(),$op,$action,$unit,$subject,$before,$after);
  }
  private function replay($op,$digest){
    return(new ReflectionMethod(PhorgeOrganizationService::class,'replay'))->invoke($this->service->get(),$op,$digest);
  }
  private function record($op,$digest,array $versions){
    return(new ReflectionMethod(PhorgeOrganizationService::class,'record'))->invoke($this->service->get(),$op,$digest,$versions);
  }
  private function receipt(array $row,$replayed=false){
    return array('transfer_id'=>$row['transferID'],'state'=>$row['state'],
      'version'=>PhorgeOrganizationService::version($row['version']),'expires_at'=>PhorgeOrganizationService::version($row['expiresAt']),
      'replayed'=>$replayed,'native_project_permissions_changed'=>false);
  }
  private static function normalize($action,array $intent){
    switch($action){
      case 'extend':return PhorgeOrganizationTransferRequest::extension($intent);
      case 'terminate':return PhorgeOrganizationTransferRequest::termination($intent);
      case 'responsible':return PhorgeOrganizationTransferRequest::responsibility($intent);
      default:throw new InvalidArgumentException('organization_handover_action_refused');
    }
  }
  private static function ticket(AphrontDatabaseConnection $conn,$id,$lock){
    self::id($id);
    $row=queryfx_one($conn,'SELECT id,transferID,operationID,subjectPHID,responsiblePHID,requesterPHID,executorPHID,action,state,requestDigest,'.
      'CASE WHEN OCTET_LENGTH(bindingJSON)<=32768 THEN bindingJSON ELSE NULL END bindingJSON,'.
      'approverPHID,CASE WHEN OCTET_LENGTH(approverBindingJSON)<=32768 THEN approverBindingJSON ELSE NULL END approverBindingJSON,'.
      'approvedAt,expiresAt,consumedAt,version,createdAt FROM %R WHERE BINARY transferID=BINARY %s'.($lock?' FOR UPDATE':''),new PhorgeOrganizationTransfer(),$id);
    if(!$row||$row['transferID']!==$id){throw new RuntimeException('organization_handover_approval_missing');}
    $data=self::decode($row['bindingJSON']);$keys=array_keys($data);sort($keys,SORT_STRING);
    if($keys!==array('action','calendar','executor','expiresAt','intent','issuedAt','requester','schema','siteTimezone','sources')||
      !in_array($data['schema'],array('native-organization-handover-v1','native-organization-handover-v2'),true)||!hash_equals($row['requestDigest'],self::digest($data))||
      self::normalize($data['action'],$data['intent'])!==$data['intent']||$row['action']!==$data['action']||
      $row['transferID']!==$data['intent']['transfer_id']||$row['operationID']!==$data['intent']['operation_id']||
      $row['subjectPHID']!==($data['sources']['subject']['userPHID']??null)||
      $row['responsiblePHID']!==($data['sources']['newResponsible']['userPHID']??$data['sources']['oldResponsible']['userPHID']??null)||
      $row['requesterPHID']!==($data['requester']['userPHID']??null)||$row['executorPHID']!==$row['requesterPHID']||
      self::digest($data['requester'])!==self::digest($data['executor'])||
      !in_array($row['state'],array('submitted','approved','executed'),true)||
      PhorgeOrganizationService::version($row['version'])<1||
      !is_int($data['issuedAt'])||!is_int($data['expiresAt'])||$data['expiresAt']<=$data['issuedAt']||
      PhorgeOrganizationService::version($row['expiresAt'])!==$data['expiresAt']||
      PhorgeOrganizationService::version($row['createdAt'])!==$data['issuedAt']){
      throw new RuntimeException('organization_handover_approval_shape_refused');}
    PhorgeGovernanceOrganizationApprovalAdapter::validateTTL($data['expiresAt']-$data['issuedAt']);
    if(($row['state']==='submitted'&&($row['approverPHID']!==null||$row['approverBindingJSON']!==null||$row['approvedAt']!==null))||
      ($row['state']!=='submitted'&&($row['approverPHID']===null||$row['approverBindingJSON']===null||$row['approvedAt']===null))||
      ($row['state']==='executed')!==($row['consumedAt']!==null)){
      throw new RuntimeException('organization_handover_approval_state_refused');}
    return array($row,$data);
  }
  /** Planning is never authority. Re-read under the existing sorted source locks. */
  public static function planningPHIDs(AphrontDatabaseConnection $conn,$ticket=null,$handover=null,array $extra=array()){
    if($conn!==(new PhabricatorUser())->establishConnection('w')){throw new RuntimeException('organization_handover_planning_connection_refused');}
    $phids=$extra;
    if($ticket!==null){list($row,$data)=self::ticket($conn,$ticket,false);
      foreach(array('subjectPHID','responsiblePHID','requesterPHID','executorPHID','approverPHID')as$key){if($row[$key]!==null){$phids[]=$row[$key];}}
      $handover=$data['intent']['handover_id'];
    }
    if($handover!==null){self::id($handover);$row=queryfx_one($conn,'SELECT handoverID,subjectPHID,responsiblePHID FROM %R WHERE BINARY handoverID=BINARY %s',new PhorgeOrganizationHandover(),$handover);
      if(!$row||$row['handoverID']!==$handover){throw new RuntimeException('organization_handover_missing');}
      $phids[]=$row['subjectPHID'];$phids[]=$row['responsiblePHID'];}
    $phids=array_values(array_unique($phids));sort($phids,SORT_STRING);
    if(count($phids)>8){throw new RuntimeException('organization_handover_identity_budget');}
    foreach($phids as$phid){if(!PhorgeGovernanceOrganizationRequest::userID($phid)){throw new RuntimeException('organization_handover_identity_refused');}}
    return$phids;
  }
  private function handover($id){
    self::id($id);$row=queryfx_one($this->connection,'SELECT * FROM %R WHERE BINARY handoverID=BINARY %s FOR UPDATE',new PhorgeOrganizationHandover(),$id);
    if(!$row||$row['handoverID']!==$id||!in_array($row['state'],array('active','terminated'),true)||
      PhorgeOrganizationService::version($row['version'])<1){throw new RuntimeException('organization_handover_record_refused');}
    self::id($row['transferID']);self::id($row['fromDepartmentID']);self::id($row['toDepartmentID']);
    if($row['fromDepartmentID']===$row['toDepartmentID']||!PhorgeGovernanceOrganizationRequest::userID($row['subjectPHID'])||
      !PhorgeGovernanceOrganizationRequest::userID($row['responsiblePHID'])){throw new RuntimeException('organization_handover_record_refused');}
    self::storedCalendar($row);return$row;
  }
  /** Derive cumulative days from the stored instants/wall times, not a caller total. */
  public static function storedCalendar(array $row){
    $zone=$row['timezone']??null;
    if(!is_string($zone)||!in_array($zone,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true)){
      throw new RuntimeException('organization_handover_calendar_source_refused');}
    $z=new DateTimeZone($zone);$dates=array();
    foreach(array('start','deadline')as$key){
      $epoch=PhorgeOrganizationService::version($row[$key.'At']??null);
      if($epoch>253402300799){throw new RuntimeException('organization_handover_calendar_source_refused');}
      $date=(new DateTimeImmutable('@'.$epoch))->setTimezone($z);
      if($date->format('Y-m-d H:i:s')!==($row[$key.'Local']??null)||
        (string)$date->getOffset()!==(string)($row[$key.'Offset']??null)){
        throw new RuntimeException('organization_handover_calendar_source_refused');}
      $dates[$key]=$date;
    }
    if($dates['deadline']<$dates['start']||$dates['start']->format('H:i:s')!==$dates['deadline']->format('H:i:s')){
      throw new RuntimeException('organization_handover_calendar_source_refused');}
    $start=new DateTimeImmutable($dates['start']->format('Y-m-d'),new DateTimeZone('UTC'));
    $end=new DateTimeImmutable($dates['deadline']->format('Y-m-d'),new DateTimeZone('UTC'));
    $days=(int)$start->diff($end)->format('%a');
    if($days!==PhorgeOrganizationService::version($row['cumulativeDays']??null)){
      throw new RuntimeException('organization_handover_cumulative_source_refused');}
    return$days;
  }
  private function unit($id){
    self::id($id);$row=queryfx_one($this->connection,'SELECT * FROM organization_unit WHERE BINARY unitID=BINARY %s FOR UPDATE',$id);
    if(!$row||$row['unitID']!==$id||$row['kind']!=='department'||!in_array($row['state'],array('active','archived'),true)||
      ($row['visibilityModel']??null)!=='restricted-admin-v1'||(string)($row['visibilityVersion']??'')!=='1'){
      throw new RuntimeException('organization_handover_department_source_refused');}
    return$row;
  }
  private function sources($action,array $intent,$sourceVersion=2){
    $handover=$this->handover($intent['handover_id']);
    if($handover['state']!=='active'||PhorgeOrganizationService::version($handover['version'])!==$intent['expected_version']){
      throw new RuntimeException('organization_handover_stale');}
    $subject=$this->identity($handover['subjectPHID'],$action==='extend',$sourceVersion);
    // A disabled former responsible person may be replaced or the handover ended.
    $owner=$this->identity($handover['responsiblePHID'],$action==='extend',$sourceVersion);
    $ids=array($handover['fromDepartmentID'],$handover['toDepartmentID']);sort($ids,SORT_STRING);$units=array();
    foreach($ids as$id){$units[$id]=$this->unit($id);}
    $primary=queryfx_one($this->connection,'SELECT * FROM organization_primarydepartment WHERE BINARY userPHID=BINARY %s FOR UPDATE',$handover['subjectPHID']);
    if(!$primary||$primary['userPHID']!==$handover['subjectPHID']||$primary['unitID']!==$handover['toDepartmentID']){
      throw new RuntimeException('organization_handover_primary_changed');}
    $sources=array('handover'=>$handover,'subject'=>$subject,'oldResponsible'=>$owner,'primary'=>$primary,
      'from'=>$units[$handover['fromDepartmentID']],'to'=>$units[$handover['toDepartmentID']]);
    if($action==='responsible'){
      if($intent['responsible_phid']===$handover['responsiblePHID']){throw new RuntimeException('organization_handover_same_responsible_refused');}
      $sources['newResponsible']=$this->identity($intent['responsible_phid'],true,$sourceVersion);}
    return$sources;
  }
  private function zone(){
    $zone=PhabricatorEnv::getEnvConfig('phabricator.timezone');if($zone===null){return'UTC';}
    if(!is_string($zone)||!in_array($zone,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true)){
      throw new RuntimeException('organization_handover_site_timezone_unknown');}return$zone;
  }
  private function calendar($action,array $intent,array $sources,$offset){
    if($action!=='extend'){if($offset!==null){throw new InvalidArgumentException('organization_handover_offset_refused');}return null;}
    $h=$sources['handover'];$days=self::storedCalendar($h);
    return PhorgeOrganizationCalendar::extension(PhorgeOrganizationService::version($h['deadlineAt']),$intent['handover_days'],
      $h['timezone'],$days,$intent['reason'],$intent['exception_reason'],$offset);
  }
  private function checkCurrent(array $row,array $data,$compareSources){
    $this->actor();$this->credential($data['requester']);$this->credential($data['executor']);
    if($row['state']!=='submitted'){$reviewer=$this->credential(self::decode($row['approverBindingJSON']));
      if($reviewer->getUserPHID()!==$row['approverPHID']||in_array($reviewer->getUserPHID(),array($row['requesterPHID'],$row['executorPHID']),true)){
        throw new RuntimeException('organization_handover_independent_approval_required');}}
    if($compareSources){if(self::digest($this->sources($data['action'],$data['intent'],self::sourceVersion($data['schema'])))!==self::digest($data['sources'])){
      throw new RuntimeException('organization_handover_source_stale');}}
    else{
      foreach(array('subject','oldResponsible','newResponsible')as$key){if(isset($data['sources'][$key])){
        $eligible=$key==='newResponsible'||$data['action']==='extend';
        if(self::digest($this->identity($data['sources'][$key]['userPHID'],$eligible,self::sourceVersion($data['schema'])))!==self::digest($data['sources'][$key])){
          throw new RuntimeException('organization_handover_identity_changed');}}}
      foreach(array('from','to')as$key){if(self::digest($this->unit($data['sources'][$key]['unitID']))!==self::digest($data['sources'][$key])){
        throw new RuntimeException('organization_handover_department_changed');}}
      $primary=queryfx_one($this->connection,'SELECT * FROM organization_primarydepartment WHERE BINARY userPHID=BINARY %s FOR UPDATE',$row['subjectPHID']);
      if(!$primary||self::digest($primary)!==self::digest($data['sources']['primary'])){throw new RuntimeException('organization_handover_primary_changed');}}
    if($this->zone()!==$data['siteTimezone']){throw new RuntimeException('organization_handover_site_timezone_changed');}
    $offset=$data['calendar']['deadline_offset']??null;
    if(self::digest($this->calendar($data['action'],$data['intent'],$data['sources'],$offset))!==self::digest($data['calendar'])){
      throw new RuntimeException('organization_handover_calendar_changed');}
    $now=$this->now();
    if($now<$data['issuedAt']||$now>=$data['expiresAt']){throw new RuntimeException('organization_handover_approval_expired');}
    if($data['action']==='extend'&&($data['calendar']['resolution_required']||$data['calendar']['deadline_at']<=$now)){
      throw new RuntimeException('organization_handover_extension_already_due');}
    return$now;
  }
  public function preview($action,array $intent,$offset=null){
    $this->actor();$intent=self::normalize($action,$intent);$sources=$this->sources($action,$intent);
    return array('action'=>$action,'intent'=>$intent,'calendar'=>$this->calendar($action,$intent,$sources,$offset),
      'site_timezone'=>$this->zone(),'stored_timezone'=>$sources['handover']['timezone'],
      'overdue_seconds'=>max(0,$this->now()-PhorgeOrganizationService::version($sources['handover']['deadlineAt'])),
      'approval_required'=>true,'native_project_permissions_changed'=>false,'automatic_dispatch'=>false);
  }
  public function propose($action,array $intent,$ttl,$offset=null){
    $actor=$this->actor();$intent=self::normalize($action,$intent);PhorgeGovernanceOrganizationApprovalAdapter::validateTTL($ttl);
    $exists=queryfx_one($this->connection,'SELECT transferID FROM %R WHERE BINARY transferID=BINARY %s FOR UPDATE',new PhorgeOrganizationTransfer(),$intent['transfer_id']);
    if($exists){list($row,$data)=self::ticket($this->connection,$intent['transfer_id'],true);
      if($data['action']!==$action||$data['intent']!==$intent||$row['requesterPHID']!==$actor->getUserPHID()||
        $data['expiresAt']-$data['issuedAt']!==$ttl||($offset!==null&&($data['calendar']['deadline_offset']??null)!==$offset)){
        throw new RuntimeException('organization_handover_proposal_conflict');}
      $this->checkCurrent($row,$data,true);return$this->receipt($row,true);}
    $sources=$this->sources($action,$intent);$calendar=$this->calendar($action,$intent,$sources,$offset);$now=$this->now();
    if($calendar!==null&&($calendar['resolution_required']||$calendar['deadline_at']<=$now)){throw new RuntimeException('organization_handover_extension_already_due');}
    $binding=$actor->getSource()->getSnapshotData();$data=array('schema'=>'native-organization-handover-v2','action'=>$action,'intent'=>$intent,
      'sources'=>$sources,'calendar'=>$calendar,'siteTimezone'=>$this->zone(),'requester'=>$binding,'executor'=>$binding,'issuedAt'=>$now,'expiresAt'=>$now+$ttl);
    queryfx($this->connection,'INSERT INTO %R (transferID,operationID,subjectPHID,responsiblePHID,requesterPHID,executorPHID,action,state,requestDigest,bindingJSON,expiresAt,version,createdAt) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,1,%d)',
      new PhorgeOrganizationTransfer(),$intent['transfer_id'],$intent['operation_id'],$sources['subject']['userPHID'],
      $sources['newResponsible']['userPHID']??$sources['oldResponsible']['userPHID'],$actor->getUserPHID(),$actor->getUserPHID(),
      $action,'submitted',self::digest($data),self::json($data),$data['expiresAt'],$now);
    $this->audit($intent['operation_id'],'handover.propose',$sources['to']['unitID'],$sources['subject']['userPHID'],null,
      array('transferID'=>$intent['transfer_id'],'action'=>$action,'calendar'=>$calendar,'siteTimezone'=>$data['siteTimezone'],
        'storedTimezone'=>$sources['handover']['timezone'],'overdueSeconds'=>max(0,$now-(int)$sources['handover']['deadlineAt'])));
    list($row,$data)=self::ticket($this->connection,$intent['transfer_id'],true);$this->checkCurrent($row,$data,true);return$this->receipt($row);
  }
  public function readTicket($id){
    $actor=$this->actor();list($row,$data)=self::ticket($this->connection,$id,true);
    return array('request'=>$this->receipt($row),'intent'=>$data['intent'],'calendar'=>$data['calendar'],'request_digest'=>$row['requestDigest'],
      'can_approve'=>$row['state']==='submitted'&&!in_array($actor->getUserPHID(),array($row['requesterPHID'],$row['executorPHID']),true),
      'change_context'=>self::projection($data['sources']['handover']),
      'native_project_permissions_changed'=>false);
  }
  public function approve($id,$digest){
    $actor=$this->actor();list($row,$data)=self::ticket($this->connection,$id,true);
    if(!is_string($digest)||!hash_equals($row['requestDigest'],$digest)||in_array($actor->getUserPHID(),array($row['requesterPHID'],$row['executorPHID']),true)){
      throw new RuntimeException('organization_handover_independent_approval_required');}
    if($row['state']==='approved'){if($row['approverPHID']!==$actor->getUserPHID()){throw new RuntimeException('organization_handover_approver_changed');}
      $this->checkCurrent($row,$data,true);return$this->receipt($row,true);}
    if($row['state']!=='submitted'){throw new RuntimeException('organization_handover_approval_state_refused');}
    $now=$this->checkCurrent($row,$data,true);$v=self::nextVersion($row['version']);$expected=$row;
    $expected['state']='approved';$expected['approverPHID']=$actor->getUserPHID();$expected['approverBindingJSON']=self::json($actor->getSource()->getSnapshotData());
    $expected['approvedAt']=$now;$expected['version']=$v;
    queryfx($this->connection,'UPDATE %R SET state=%s,approverPHID=%s,approverBindingJSON=%s,approvedAt=%d,version=version+1 WHERE BINARY transferID=BINARY %s AND state=%s AND version=%d',
      new PhorgeOrganizationTransfer(),'approved',$expected['approverPHID'],$expected['approverBindingJSON'],$now,$id,'submitted',$v-1);
    if($this->connection->getAffectedRows()!==1){throw new RuntimeException('organization_handover_stale');}
    $this->audit($row['operationID'],'handover.approve',$data['sources']['to']['unitID'],$row['subjectPHID'],null,array('transferID'=>$id));
    list($row,$data)=self::ticket($this->connection,$id,true);self::sameRow($row,$expected);$this->checkCurrent($row,$data,true);return$this->receipt($row);
  }
  private static function nextVersion($v){$v=PhorgeOrganizationService::version($v);if($v>=PHP_INT_MAX-1){throw new RuntimeException('organization_handover_version_exhausted');}return$v+1;}
  private static function sameRow(array $actual,array $expected){
    (new ReflectionMethod(PhorgeOrganizationTransferAdapter::class,'sameRow'))->invoke(null,$actual,$expected);
  }
  public function execute($id,$ack){
    $actor=$this->actor();if($ack!==true){throw new InvalidArgumentException('organization_handover_acknowledgement_required');}
    list($row,$data)=self::ticket($this->connection,$id,true);
    if($row['executorPHID']!==$actor->getUserPHID()){throw new RuntimeException('organization_handover_bound_executor_required');}
    $digest=self::digest(array('native-handover-execute-v1',$data));$replay=$this->replay($row['operationID'],$digest);
    if($replay!==null){if($row['state']!=='executed'){throw new RuntimeException('organization_handover_inconsistent_ledger');}$this->actor();return$replay;}
    if($row['state']!=='approved'){throw new RuntimeException('organization_handover_approval_required');}
    $now=$this->checkCurrent($row,$data,true);$ticket=$row;$ticket['state']='executed';$ticket['consumedAt']=$now;$ticket['version']=self::nextVersion($row['version']);
    queryfx($this->connection,'UPDATE %R SET state=%s,consumedAt=%d,version=version+1 WHERE BINARY transferID=BINARY %s AND state=%s AND version=%d',
      new PhorgeOrganizationTransfer(),'executed',$now,$id,'approved',$ticket['version']-1);
    if($this->connection->getAffectedRows()!==1){throw new RuntimeException('organization_handover_stale');}
    $before=$data['sources']['handover'];$after=$before;$after['version']=self::nextVersion($before['version']);
    switch($data['action']){
      case 'extend':$c=$data['calendar'];$after['deadlineAt']=$c['deadline_at'];$after['deadlineLocal']=$c['deadline_local'];
        $after['deadlineOffset']=$c['deadline_offset'];$after['cumulativeDays']=$c['cumulative_days'];break;
      case 'terminate':$after['state']='terminated';break;
      case 'responsible':$after['responsiblePHID']=$data['intent']['responsible_phid'];break;
      default:throw new RuntimeException('organization_handover_action_refused');
    }
    queryfx($this->connection,'UPDATE %R SET state=%s,responsiblePHID=%s,deadlineAt=%d,deadlineLocal=%s,deadlineOffset=%d,cumulativeDays=%d,version=version+1 WHERE BINARY handoverID=BINARY %s AND state=%s AND version=%d',
      new PhorgeOrganizationHandover(),$after['state'],$after['responsiblePHID'],(int)$after['deadlineAt'],$after['deadlineLocal'],(int)$after['deadlineOffset'],(int)$after['cumulativeDays'],
      $after['handoverID'],'active',$after['version']-1);
    if($this->connection->getAffectedRows()!==1){throw new RuntimeException('organization_handover_stale');}
    $event='handover.'.$data['action'].':'.$id;
    queryfx($this->connection,'INSERT INTO %R (eventID,eventType,transferID,handoverID,sourceVersion,state,attempts,createdAt) VALUES (%s,%s,%s,%s,%d,%s,0,%d)',
      new PhorgeOrganizationOutbox(),$event,'handover.'.$data['action'],$id,$after['handoverID'],$after['version'],'pending',$now);
    $expected_event=array('eventID'=>$event,'eventType'=>'handover.'.$data['action'],'transferID'=>$id,'handoverID'=>$after['handoverID'],
      'sourceVersion'=>$after['version'],'state'=>'pending','attempts'=>0,'createdAt'=>$now,'dispatchedAt'=>null);
    $event_row=queryfx_one($this->connection,'SELECT * FROM %R WHERE BINARY eventID=BINARY %s FOR UPDATE',new PhorgeOrganizationOutbox(),$event);
    if(!$event_row){throw new RuntimeException('organization_handover_outbox_missing');}$expected_event['id']=PhorgeOrganizationService::version($event_row['id']);
    self::sameRow($event_row,$expected_event);
    $this->final=array('ticket'=>$ticket,'handover'=>$after,'event'=>$expected_event);
    $this->audit($row['operationID'],'handover.execute',$data['sources']['to']['unitID'],$row['subjectPHID'],$before,
      array('handover'=>$after,'reason'=>$data['intent']['reason'],'exceptionReason'=>$data['intent']['exception_reason']??null,
        'overdueSeconds'=>max(0,$now-(int)$before['deadlineAt']),'siteTimezone'=>$data['siteTimezone'],'native_project_permissions_changed'=>false));
    $result=$this->record($row['operationID'],$digest,array('transfer:'.$id=>$ticket['version'],'handover:'.$after['handoverID']=>$after['version']));
    $this->assertFinal();return$result;
  }
  public function assertFinal(){
    $this->actor();if($this->final===null){throw new RuntimeException('organization_handover_final_check_required');}
    list($row,$data)=self::ticket($this->connection,$this->final['ticket']['transferID'],true);self::sameRow($row,$this->final['ticket']);
    $h=$this->handover($this->final['handover']['handoverID']);self::sameRow($h,$this->final['handover']);
    $event=queryfx_one($this->connection,'SELECT * FROM %R WHERE BINARY eventID=BINARY %s FOR UPDATE',new PhorgeOrganizationOutbox(),$this->final['event']['eventID']);
    if(!$event){throw new RuntimeException('organization_handover_outbox_missing');}self::sameRow($event,$this->final['event']);
    $this->checkCurrent($row,$data,false);
  }
  /** A local native viewer query result, not a cross-database atomic grant. */
  private function project($phid){
    if(!is_string($phid)||!preg_match('/\APHID-PROJ-[a-zA-Z0-9]{20}\z/D',$phid)){
      throw new InvalidArgumentException('organization_handover_project_unavailable');}
    $actor=$this->actor();
    $viewer=(new PhabricatorUser())->loadOneWhere('BINARY phid=BINARY %s',$actor->getUserPHID());
    if(!$viewer||$viewer->getPHID()!==$actor->getUserPHID()||!$viewer->getIsAdmin()||$viewer->getIsDisabled()){
      throw new RuntimeException('organization_handover_current_viewer_required');}
    try{$project=(new PhabricatorProjectQuery())->setViewer($viewer)->withPHIDs(array($phid))->executeOne();}
    catch(PhabricatorPolicyException$error){if(get_class($error)!==PhabricatorPolicyException::class){throw$error;}throw new RuntimeException('organization_handover_project_unavailable');}
    if(!$project||$project->getPHID()!==$phid){throw new RuntimeException('organization_handover_project_unavailable');}
    return$project;
  }
  private function readableDispositionRows($id){
    return queryfx_all($this->connection,'SELECT id,handoverID,projectPHID,decision,manualStatus,manualActorPHID,manualAt,observedDigest,'.
      'CASE WHEN OCTET_LENGTH(note)<=1024 THEN note ELSE NULL END note,version FROM %R WHERE BINARY handoverID=BINARY %s ORDER BY id LIMIT 33 FOR UPDATE',new PhorgeOrganizationProjectDisposition(),$id);
  }
  public function projectReceipts($id){
    $this->actor();$h=$this->handover($id);$rows=$this->readableDispositionRows($id);
    if(count($rows)>32){throw new RuntimeException('organization_handover_disposition_budget');}
    $result=array();
    foreach($rows as$row){
      try{$project=$this->project($row['projectPHID']);}
      catch(RuntimeException$error){if($error->getMessage()!=='organization_handover_project_unavailable'){throw$error;}continue;}
      if(!in_array($row['decision'],array('keep','remove','review'),true)||!in_array($row['manualStatus'],array('pending','acknowledged'),true)||
        !is_string($row['note'])||!phutil_is_utf8($row['note'])){throw new RuntimeException('organization_handover_disposition_shape_refused');}
      $result[]=array('project_phid'=>$project->getPHID(),'name'=>$project->getName(),'decision'=>$row['decision'],
        'manual_status'=>$row['manualStatus'],'note'=>$row['note'],'version'=>PhorgeOrganizationService::version($row['version']));
    }
    $this->actor();return array('handover_id'=>$id,'handover_version'=>PhorgeOrganizationService::version($h['version']),
      'rows'=>$result,'exhaustive'=>false,'native_project_permissions_changed'=>false,'source_consistency'=>'current-native-viewer-query-sampled');
  }
  public function recordProjectReceipt(array $intent){
    $actor=$this->actor();$intent=PhorgeOrganizationTransferRequest::disposition($intent);$h=$this->handover($intent['handover_id']);
    if(PhorgeOrganizationService::version($h['version'])!==$intent['handover_version']){throw new RuntimeException('organization_handover_stale');}
    $project=$this->project($intent['project_phid']);$digest=self::digest(array('native-manual-project-receipt-v1',$intent));
    $replay=$this->replay($intent['operation_id'],$digest);if($replay!==null){$this->project($intent['project_phid']);$this->actor();return$replay;}
    $rows=$this->readableDispositionRows($h['handoverID']);$old=null;
    foreach($rows as$row){if($row['projectPHID']===$intent['project_phid']){$old=$row;}}
    if(count($rows)>32||($old===null&&count($rows)>=32)){throw new RuntimeException('organization_handover_disposition_budget');}
    $expected=$old===null?0:PhorgeOrganizationService::version($old['version']);
    if($expected!==$intent['expected_version']){throw new RuntimeException('organization_handover_disposition_stale');}
    $version=self::nextVersion($expected);$now=$this->now();
    // This digest documents the observed native object only; it is no authority.
    $observed=self::digest(array('projectPHID'=>$project->getPHID(),'viewPolicy'=>$project->getViewPolicy(),
      'editPolicy'=>$project->getEditPolicy(),'joinPolicy'=>$project->getJoinPolicy()));
    if($old===null){queryfx($this->connection,'INSERT INTO %R (handoverID,projectPHID,decision,manualStatus,manualActorPHID,manualAt,observedDigest,note,version) VALUES (%s,%s,%s,%s,%s,%d,%s,%s,1)',
      new PhorgeOrganizationProjectDisposition(),$h['handoverID'],$intent['project_phid'],$intent['decision'],$intent['manual_status'],$actor->getUserPHID(),$now,$observed,$intent['note']);}
    else{queryfx($this->connection,'UPDATE %R SET decision=%s,manualStatus=%s,manualActorPHID=%s,manualAt=%d,observedDigest=%s,note=%s,version=version+1 WHERE BINARY handoverID=BINARY %s AND BINARY projectPHID=BINARY %s AND version=%d',
      new PhorgeOrganizationProjectDisposition(),$intent['decision'],$intent['manual_status'],$actor->getUserPHID(),$now,$observed,$intent['note'],$h['handoverID'],$intent['project_phid'],$expected);
      if($this->connection->getAffectedRows()!==1){throw new RuntimeException('organization_handover_disposition_stale');}}
    $this->audit($intent['operation_id'],'handover.project.receipt',$h['toDepartmentID'],$h['subjectPHID'],null,
      array('handoverID'=>$h['handoverID'],'projectPHID'=>$intent['project_phid'],'decision'=>$intent['decision'],'manualStatus'=>$intent['manual_status'],'noPermissionMutation'=>true));
    $result=$this->record($intent['operation_id'],$digest,array('disposition:'.$intent['project_phid']=>$version));
    $actual=queryfx_one($this->connection,'SELECT handoverID,projectPHID,decision,manualStatus,manualActorPHID,manualAt,observedDigest,note,version FROM %R WHERE BINARY handoverID=BINARY %s AND BINARY projectPHID=BINARY %s FOR UPDATE',
      new PhorgeOrganizationProjectDisposition(),$h['handoverID'],$intent['project_phid']);
    self::sameRow($actual,array('handoverID'=>$h['handoverID'],'projectPHID'=>$intent['project_phid'],'decision'=>$intent['decision'],
      'manualStatus'=>$intent['manual_status'],'manualActorPHID'=>$actor->getUserPHID(),'manualAt'=>$now,'observedDigest'=>$observed,'note'=>$intent['note'],'version'=>$version));
    $this->project($intent['project_phid']);$this->actor();return$result;
  }
  private static function projection(array $row){
    return array('handover_id'=>$row['handoverID'],'subject_phid'=>$row['subjectPHID'],'responsible_phid'=>$row['responsiblePHID'],
      'from_department'=>$row['fromDepartmentID'],'to_department'=>$row['toDepartmentID'],'state'=>$row['state'],
      'start_at'=>PhorgeOrganizationService::version($row['startAt']),'deadline_at'=>PhorgeOrganizationService::version($row['deadlineAt']),
      'timezone'=>$row['timezone'],'cumulative_days'=>self::storedCalendar($row),'version'=>PhorgeOrganizationService::version($row['version']));
  }
  public function listPage($limit,$after){
    $this->actor();if(!is_int($limit)||$limit<1||$limit>100){throw new InvalidArgumentException('organization_handover_page_refused');}
    $after=PhorgeOrganizationService::auditCursor($after);
    $rows=queryfx_all($this->connection,'SELECT * FROM %R WHERE id>%d ORDER BY id LIMIT %d FOR UPDATE',new PhorgeOrganizationHandover(),$after,$limit);
    $out=array();foreach($rows as$row){$out[]=self::projection($row);}$this->actor();
    return array('rows'=>$out,'next_cursor'=>count($rows)===$limit?(string)last($rows)['id']:null);
  }
  public function detail($id){$this->actor();$row=$this->handover($id);$out=self::projection($row);$this->actor();return$out;}

  public function duePage($limit,$cursor){
    $this->actor();
    if(!is_int($limit)||$limit<1||$limit>100){throw new InvalidArgumentException('organization_handover_page_refused');}
    if($cursor===null){$cursor=array('deadline_at'=>0,'id'=>0);}
    if(!is_array($cursor)){throw new InvalidArgumentException('organization_handover_cursor_refused');}
    $keys=array_keys($cursor);sort($keys,SORT_STRING);
    if($keys!==array('deadline_at','id')||!is_int($cursor['deadline_at'])||!is_int($cursor['id'])||$cursor['deadline_at']<0||$cursor['id']<0){
      throw new InvalidArgumentException('organization_handover_cursor_refused');}
    $now=$this->now();
    $rows=queryfx_all($this->connection,'SELECT * FROM %R WHERE state=%s AND deadlineAt<=%d AND (deadlineAt>%d OR (deadlineAt=%d AND id>%d)) ORDER BY deadlineAt,id LIMIT %d FOR UPDATE',
      new PhorgeOrganizationHandover(),'active',$now,$cursor['deadline_at'],$cursor['deadline_at'],$cursor['id'],$limit);
    $result=array();foreach($rows as$row){self::storedCalendar($row);
      $result[]=array('handover_id'=>$row['handoverID'],'subject_phid'=>$row['subjectPHID'],'responsible_phid'=>$row['responsiblePHID'],
        'deadline_at'=>PhorgeOrganizationService::version($row['deadlineAt']),'version'=>PhorgeOrganizationService::version($row['version']));}
    $last=$rows?last($rows):null;$this->actor();
    return array('rows'=>$result,'next_cursor'=>count($rows)===$limit?array('deadline_at'=>(int)$last['deadlineAt'],'id'=>(int)$last['id']):null,
      'as_of'=>$now,'automatic_dispatch'=>false,'native_project_permissions_changed'=>false);
  }
  public function recordDue($operation,$id,$expectedVersion){
    $actor=$this->actor();if(!PhorgeGovernanceOrganizationRequest::identifier($operation,128)||!is_int($expectedVersion)||$expectedVersion<1){
      throw new InvalidArgumentException('organization_handover_due_intent_refused');}
    $h=$this->handover($id);$digest=self::digest(array('native-handover-due-v1',$id,$expectedVersion));
    $replay=$this->replay($operation,$digest);if($replay!==null){$this->actor();return$replay;}
    $now=$this->now();if($h['state']!=='active'||(int)$h['version']!==$expectedVersion||(int)$h['deadlineAt']>$now){
      throw new RuntimeException('organization_handover_not_due');}
    // One notification identity per actual deadline, regardless of caller opid.
    $event='handover.due:'.substr(hash('sha256',$id.':'.$h['deadlineAt']),0,64);
    $existing=queryfx_one($this->connection,'SELECT * FROM %R WHERE BINARY eventID=BINARY %s FOR UPDATE',new PhorgeOrganizationOutbox(),$event);
    if(!$existing){queryfx($this->connection,'INSERT INTO %R (eventID,eventType,transferID,handoverID,sourceVersion,state,attempts,createdAt) VALUES (%s,%s,NULL,%s,%d,%s,0,%d)',
      new PhorgeOrganizationOutbox(),$event,'handover.due',$id,$expectedVersion,'pending',$now);}
    else{if($existing['handoverID']!==$id||$existing['eventType']!=='handover.due'||$existing['transferID']!==null||
      $existing['state']!=='pending'||(string)$existing['attempts']!=='0'||$existing['dispatchedAt']!==null){
      throw new RuntimeException('organization_handover_due_event_shape_refused');}}
    $event_row=queryfx_one($this->connection,'SELECT * FROM %R WHERE BINARY eventID=BINARY %s FOR UPDATE',new PhorgeOrganizationOutbox(),$event);
    if(!$event_row){throw new RuntimeException('organization_handover_outbox_missing');}
    $expected_event=$existing===null?array('id'=>PhorgeOrganizationService::version($event_row['id']),
      'eventID'=>$event,'eventType'=>'handover.due','transferID'=>null,'handoverID'=>$id,'sourceVersion'=>$expectedVersion,
      'state'=>'pending','attempts'=>0,'createdAt'=>$now,'dispatchedAt'=>null):$existing;
    if(PhorgeOrganizationService::version($expected_event['sourceVersion'])<1||
      PhorgeOrganizationService::version($expected_event['sourceVersion'])>$expectedVersion){throw new RuntimeException('organization_handover_due_event_shape_refused');}
    self::sameRow($event_row,$expected_event);
    $this->audit($operation,'handover.due',$h['toDepartmentID'],$h['subjectPHID'],null,array('handoverID'=>$id,'eventID'=>$event,'automaticDispatch'=>false));
    $result=$this->record($operation,$digest,array('handover:'.$id=>$expectedVersion));
    $event_row=queryfx_one($this->connection,'SELECT * FROM %R WHERE BINARY eventID=BINARY %s FOR UPDATE',new PhorgeOrganizationOutbox(),$event);
    if(!$event_row){throw new RuntimeException('organization_handover_outbox_missing');}self::sameRow($event_row,$expected_event);
    self::sameRow($this->handover($id),$h);$this->actor();return$result;
  }
}
