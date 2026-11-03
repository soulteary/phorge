<?php

/** Fixed native workflow, borrowing only the actual locked People transaction. */
final class PhorgeOrganizationTransferAdapter extends Phobject {
  private $service;
  private $connection;
  private $nonce;
  private $final = null;
  private function __construct() {}
  private function __clone() {}
  public function __serialize(){throw new RuntimeException('organization_transfer_serialization_refused');}
  public function __unserialize(array $data){throw new RuntimeException('organization_transfer_serialization_refused');}
  public function __debugInfo(){return array('scope'=>'native-approved-transfer');}
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
    if($schema==='native-organization-transfer-v1'){return 1;}
    if($schema==='native-organization-transfer-v2'){return 2;}
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
  private static function loadTicket(AphrontDatabaseConnection $conn,$id,$lock){
    self::id($id);
    $row=queryfx_one($conn,'SELECT id,transferID,operationID,subjectPHID,responsiblePHID,requesterPHID,executorPHID,action,state,requestDigest,'.
      'CASE WHEN OCTET_LENGTH(bindingJSON)<=32768 THEN bindingJSON ELSE NULL END bindingJSON,'.
      'approverPHID,CASE WHEN OCTET_LENGTH(approverBindingJSON)<=32768 THEN approverBindingJSON ELSE NULL END approverBindingJSON,'.
      'approvedAt,expiresAt,consumedAt,version,createdAt FROM %R WHERE BINARY transferID=BINARY %s'.($lock?' FOR UPDATE':''),new PhorgeOrganizationTransfer(),$id);
    if(!$row||$row['transferID']!==$id){throw new RuntimeException('organization_transfer_missing');}
    $data=self::decode($row['bindingJSON']);
    $keys=array_keys($data);sort($keys,SORT_STRING);
    if($keys!==array('action','calendar','executor','expiresAt','intent','issuedAt','requester','responsiblePHID','schema','siteTimezone','sources')){
      throw new RuntimeException('organization_transfer_binding_fields_refused');}
    if(!in_array($data['schema']??null,array('native-organization-transfer-v1','native-organization-transfer-v2'),true)||
      !hash_equals($row['requestDigest'],self::digest($data))||
      $row['operationID']!==($data['intent']['operation_id']??null)||
      $row['transferID']!==($data['intent']['transfer_id']??null)||
      $row['action']!==($data['action']??null)||
      !in_array($row['state'],array('submitted','approved','executed'),true)||
      PhorgeOrganizationService::version($row['version'])<1||
      PhorgeOrganizationService::version($row['expiresAt'])!==($data['expiresAt']??null)||
      $row['requesterPHID']!==($data['requester']['userPHID']??null)||
      $row['executorPHID']!==($data['executor']['userPHID']??null)||
      $row['subjectPHID']!==($data['sources']['subject']['userPHID']??null)||
      $row['responsiblePHID']!==($data['responsiblePHID']??null)){
      throw new RuntimeException('organization_transfer_record_refused');}
    if(!is_int($data['issuedAt'])||!is_int($data['expiresAt'])||$data['expiresAt']<=$data['issuedAt']||
      PhorgeOrganizationService::version($row['createdAt'])!==$data['issuedAt']||
      self::digest($data['requester'])!==self::digest($data['executor'])||
      $row['responsiblePHID']!==($data['sources']['responsible']['userPHID']??null)||
      $row['responsiblePHID']!==($data['intent']['responsible_phid']??null)||
      $row['subjectPHID']!==($data['intent']['subject_phid']??null)){
      throw new RuntimeException('organization_transfer_binding_sources_refused');}
    PhorgeGovernanceOrganizationApprovalAdapter::validateTTL($data['expiresAt']-$data['issuedAt']);
    if($data['action']!=='transfer'||!is_array($data['intent'])||
      PhorgeOrganizationTransferRequest::proposal($data['intent'])!==$data['intent']){throw new RuntimeException('organization_transfer_action_unsupported');}
    if($row['state']==='submitted' && ($row['approverPHID']!==null||$row['approverBindingJSON']!==null||$row['approvedAt']!==null||$row['consumedAt']!==null)){
      throw new RuntimeException('organization_transfer_state_refused');}
    if($row['state']!=='submitted' && ($row['approverPHID']===null||$row['approverBindingJSON']===null||$row['approvedAt']===null)){
      throw new RuntimeException('organization_transfer_state_refused');}
    if(($row['state']==='executed')!==($row['consumedAt']!==null)){throw new RuntimeException('organization_transfer_state_refused');}
    return array($row,$data);
  }
  /** Planning is private implementation metadata, never a current authority. */
  public static function planningPHIDs(AphrontDatabaseConnection $conn,$ticket=null,$handover=null){
    if($conn!==(new PhabricatorUser())->establishConnection('w')){throw new RuntimeException('organization_transfer_planning_connection_refused');}
    $phids=array();
    if($ticket!==null){list($row,$data)=self::loadTicket($conn,$ticket,false);
      $phids=array($row['subjectPHID'],$row['responsiblePHID'],$row['requesterPHID'],$row['executorPHID']);
      if($row['approverPHID']!==null){$phids[]=$row['approverPHID'];}
      if(isset($data['sources']['handover']['responsiblePHID'])){$phids[]=$data['sources']['handover']['responsiblePHID'];}}
    if($handover!==null){self::id($handover);$row=queryfx_one($conn,
      'SELECT handoverID,subjectPHID,responsiblePHID FROM %R WHERE BINARY handoverID=BINARY %s',new PhorgeOrganizationHandover(),$handover);
      if(!$row||$row['handoverID']!==$handover){throw new RuntimeException('organization_handover_missing');}
      $phids[]=$row['subjectPHID'];$phids[]=$row['responsiblePHID'];}
    $phids=array_values(array_unique($phids));sort($phids,SORT_STRING);
    if(count($phids)>8){throw new RuntimeException('organization_transfer_identity_budget');}
    foreach($phids as$phid){if(!PhorgeGovernanceOrganizationRequest::userID($phid)){throw new RuntimeException('organization_transfer_identity_refused');}}
    return $phids;
  }
  private function department($id,$active){
    self::id($id);$row=queryfx_one($this->connection,'SELECT * FROM organization_unit WHERE BINARY unitID=BINARY %s FOR UPDATE',$id);
    if(!$row||$row['unitID']!==$id||$row['kind']!=='department'||
      !in_array($row['state'],array('active','archived'),true)||($active&&$row['state']!=='active')||
      ($row['visibilityModel']??null)!=='restricted-admin-v1'||(string)($row['visibilityVersion']??'')!=='1'||
      PhorgeOrganizationService::version($row['version'])<1){throw new RuntimeException('organization_transfer_department_refused');}
    return $row;
  }
  private function initialSources(array $intent,$sourceVersion=2){
    $subject=$this->identity($intent['subject_phid'],true,$sourceVersion);$responsible=$this->identity($intent['responsible_phid'],true,$sourceVersion);
    $ids=array($intent['from_department'],$intent['to_department']);sort($ids,SORT_STRING);$units=array();
    foreach($ids as$id){$units[$id]=$this->department($id,$id===$intent['to_department']);}
    $primary=queryfx_one($this->connection,'SELECT * FROM organization_primarydepartment WHERE BINARY userPHID=BINARY %s FOR UPDATE',$subject['userPHID']);
    if(!$primary||$primary['userPHID']!==$subject['userPHID']||$primary['unitID']!==$intent['from_department']||
      PhorgeOrganizationService::version($primary['version'])!==$intent['primary_version']||
      PhorgeOrganizationService::version($units[$intent['from_department']]['version'])!==$intent['from_version']||
      PhorgeOrganizationService::version($units[$intent['to_department']]['version'])!==$intent['to_version']){
      throw new RuntimeException('organization_transfer_primary_or_unit_stale');}
    $active=queryfx_all($this->connection,'SELECT id FROM %R WHERE BINARY subjectPHID=BINARY %s AND state=%s LIMIT 2 FOR UPDATE',
      new PhorgeOrganizationHandover(),$subject['userPHID'],'active');
    if($active){throw new RuntimeException('organization_transfer_active_handover_requires_disposition');}
    return array('subject'=>$subject,'responsible'=>$responsible,'primary'=>$primary,
      'from'=>$units[$intent['from_department']],'to'=>$units[$intent['to_department']]);
  }
  private function zone(){
    $zone=PhabricatorEnv::getEnvConfig('phabricator.timezone');
    if($zone===null){return 'UTC';}
    if(!is_string($zone)||!in_array($zone,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true)){
      throw new RuntimeException('organization_transfer_site_timezone_unknown');}
    return $zone;
  }
  private function checkTime(array $data){
    $now=$this->now();
    if(!is_int($data['issuedAt']??null)||!is_int($data['expiresAt']??null)||$now<$data['issuedAt']||$now>=$data['expiresAt']){
      throw new RuntimeException('organization_transfer_approval_expired');}
    return $now;
  }
  private function checkCurrent(array $row,array $data,$compareSources){
    $this->actor();$this->credential($data['requester']);$this->credential($data['executor']);
    if($row['state']!=='submitted'){
      $reviewer=$this->credential(self::decode($row['approverBindingJSON']));
      if($reviewer->getUserPHID()!==$row['approverPHID']||$reviewer->getUserPHID()===$row['requesterPHID']||
        $reviewer->getUserPHID()===$row['executorPHID']){throw new RuntimeException('organization_transfer_independent_approver_required');}}
    if($data['action']!=='transfer'){throw new RuntimeException('organization_transfer_action_unsupported');}
    if($compareSources){
      if(self::digest($this->initialSources($data['intent'],self::sourceVersion($data['schema'])))!==self::digest($data['sources'])){
        throw new RuntimeException('organization_transfer_source_stale');}}
    else{foreach(array('subject','responsible')as$key){
      $actual=$this->identity($data['sources'][$key]['userPHID'],true,self::sourceVersion($data['schema']));
      if(self::digest($actual)!==self::digest($data['sources'][$key])){throw new RuntimeException('organization_transfer_identity_changed');}}
      foreach(array('from','to')as$key){$unit=$this->department($data['sources'][$key]['unitID'],$key==='to');
        if(self::digest($unit)!==self::digest($data['sources'][$key])){throw new RuntimeException('organization_transfer_department_changed');}}}
    if($this->zone()!==$data['siteTimezone']){throw new RuntimeException('organization_transfer_site_timezone_changed');}
    $calendar=PhorgeOrganizationCalendar::preview($data['calendar']['start_at'],$data['intent']['handover_days'],
      $data['siteTimezone'],$data['calendar']['deadline_offset']);
    if(self::digest($calendar)!==self::digest($data['calendar'])||$calendar['resolution_required']){throw new RuntimeException('organization_transfer_calendar_changed');}
    $now=$this->checkTime($data);
    if($calendar['days']>0&&$calendar['deadline_at']<=$now){throw new RuntimeException('organization_transfer_handover_already_due');}
    return $now;
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
  public function previewInitial(array $intent,$offset=null){
    $this->actor();$intent=PhorgeOrganizationTransferRequest::proposal($intent);$sources=$this->initialSources($intent);
    $calendar=PhorgeOrganizationCalendar::preview($this->now(),$intent['handover_days'],$this->zone(),$offset);
    return array('intent'=>$intent,'calendar'=>$calendar,'old_department_state'=>$sources['from']['state'],
      'native_project_permissions_changed'=>false,'approval_required'=>true,'exhaustive_project_review'=>false);
  }
  public function proposeInitial(array $intent,$ttl,$offset=null){
    $actor=$this->actor();$intent=PhorgeOrganizationTransferRequest::proposal($intent);
    PhorgeGovernanceOrganizationApprovalAdapter::validateTTL($ttl);
    $existing=queryfx_one($this->connection,'SELECT transferID FROM %R WHERE BINARY transferID=BINARY %s FOR UPDATE',new PhorgeOrganizationTransfer(),$intent['transfer_id']);
    if($existing){list($row,$data)=self::loadTicket($this->connection,$intent['transfer_id'],true);
      if($row['requesterPHID']!==$actor->getUserPHID()||$data['intent']!==$intent||$data['expiresAt']-$data['issuedAt']!==$ttl||
        ($offset!==null&&$data['calendar']['deadline_offset']!==$offset)){
        throw new RuntimeException('organization_transfer_proposal_conflict');}
      $this->checkCurrent($row,$data,true);return $this->receipt($row,true);}
    $sources=$this->initialSources($intent);$now=$this->now();$zone=$this->zone();
    $calendar=PhorgeOrganizationCalendar::preview($now,$intent['handover_days'],$zone,$offset);
    if($calendar['resolution_required']){throw new RuntimeException('organization_transfer_calendar_resolution_required');}
    $credential=$actor->getSource()->getSnapshotData();
    $data=array('schema'=>'native-organization-transfer-v2','action'=>'transfer','intent'=>$intent,
      'requester'=>$credential,'executor'=>$credential,'responsiblePHID'=>$intent['responsible_phid'],
      'sources'=>$sources,'siteTimezone'=>$zone,'calendar'=>$calendar,'issuedAt'=>$now,'expiresAt'=>$now+$ttl);
    queryfx($this->connection,'INSERT INTO %R (transferID,operationID,subjectPHID,responsiblePHID,requesterPHID,executorPHID,action,state,requestDigest,bindingJSON,expiresAt,version,createdAt) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,1,%d)',
      new PhorgeOrganizationTransfer(),$intent['transfer_id'],$intent['operation_id'],$intent['subject_phid'],$intent['responsible_phid'],
      $actor->getUserPHID(),$actor->getUserPHID(),'transfer','submitted',self::digest($data),self::json($data),$data['expiresAt'],$now);
    $this->audit($intent['operation_id'],'transfer.propose',$intent['to_department'],$intent['subject_phid'],null,
      array('transferID'=>$intent['transfer_id'],'calendar'=>$calendar));
    list($row,$data)=self::loadTicket($this->connection,$intent['transfer_id'],true);
    $this->checkCurrent($row,$data,true);return $this->receipt($row);
  }
  public function previewTicket($id){
    $actor=$this->actor();list($row,$data)=self::loadTicket($this->connection,$id,true);
    return array('request'=>$this->receipt($row),'intent'=>$data['intent'],'calendar'=>$data['calendar'],
      'request_digest'=>$row['requestDigest'],'can_approve'=>$row['state']==='submitted'&&$actor->getUserPHID()!==$row['requesterPHID']&&$actor->getUserPHID()!==$row['executorPHID'],
      'exhaustive_project_review'=>false,'native_project_permissions_changed'=>false);
  }
  public function approve($id,$expectedDigest){
    $actor=$this->actor();list($row,$data)=self::loadTicket($this->connection,$id,true);
    if(!is_string($expectedDigest)||!hash_equals($row['requestDigest'],$expectedDigest)||
      $actor->getUserPHID()===$row['requesterPHID']||$actor->getUserPHID()===$row['executorPHID']){
      throw new RuntimeException('organization_transfer_independent_approval_refused');}
    $this->checkCurrent($row,$data,true);
    if($row['state']==='approved'){
      if($row['approverPHID']!==$actor->getUserPHID()){throw new RuntimeException('organization_transfer_approver_changed');}
      return $this->receipt($row,true);}
    if($row['state']!=='submitted'){throw new RuntimeException('organization_transfer_state_refused');}
    self::nextVersion($row['version']);
    $now=$this->now();$review_binding=self::json($actor->getSource()->getSnapshotData());
    $expected=$row;$expected['state']='approved';$expected['approverPHID']=$actor->getUserPHID();
    $expected['approverBindingJSON']=$review_binding;$expected['approvedAt']=$now;$expected['version']=self::nextVersion($row['version']);
    queryfx($this->connection,'UPDATE %R SET state=%s,approverPHID=%s,approverBindingJSON=%s,approvedAt=%d,version=version+1 WHERE BINARY transferID=BINARY %s AND version=%d',
      new PhorgeOrganizationTransfer(),'approved',$actor->getUserPHID(),$review_binding,$now,$id,PhorgeOrganizationService::version($row['version']));
    if($this->connection->getAffectedRows()!==1){throw new RuntimeException('organization_transfer_stale');}
    $this->audit($row['operationID'],'transfer.approve',$data['intent']['to_department'],$row['subjectPHID'],null,array('transferID'=>$id));
    list($updated,$current)=self::loadTicket($this->connection,$id,true);self::sameRow($updated,$expected);$this->checkCurrent($updated,$current,true);
    return $this->receipt($updated);
  }
  private static function nextVersion($value){
    $current=PhorgeOrganizationService::version($value);
    if($current>=PHP_INT_MAX-1){throw new RuntimeException('organization_transfer_version_exhausted');}
    return$current+1;
  }
  private static function sameRow(array $actual,array $expected){
    $a=array_keys($actual);$b=array_keys($expected);sort($a);sort($b);
    if($a!==$b){throw new RuntimeException('organization_transfer_row_profile_unknown');}
    foreach($expected as$key=>$value){
      if(is_int($value)){$same=(is_string($actual[$key])||is_int($actual[$key]))&&(string)$actual[$key]===(string)$value;}
      else{$same=$actual[$key]===$value;}
      if(!$same){throw new RuntimeException('organization_transfer_unplanned_write');}
    }
  }
  public function execute($id,$acknowledged){
    $actor=$this->actor();if($acknowledged!==true){throw new RuntimeException('organization_transfer_acknowledgement_required');}
    list($row,$data)=self::loadTicket($this->connection,$id,true);
    if($row['executorPHID']!==$actor->getUserPHID()){throw new RuntimeException('organization_transfer_bound_executor_required');}
    $operation=$row['operationID'];$digest=self::digest(array('native-transfer-execute-v1',$data));
    $replay=$this->replay($operation,$digest);
    if($replay!==null){
      if($row['state']!=='executed'){throw new RuntimeException('organization_transfer_inconsistent_ledger');}
      // A committed fact is readable by the bound CURRENT administrator, even
      // after the original approved credentials naturally expire. No reconsume.
      $this->actor();return $replay;
    }
    if($row['state']!=='approved'){throw new RuntimeException('organization_transfer_approved_request_required');}
    $now=$this->checkCurrent($row,$data,true);$version=PhorgeOrganizationService::version($row['version']);self::nextVersion($version);
    self::nextVersion($data['sources']['primary']['version']);
    $expected_ticket=$row;$expected_ticket['state']='executed';$expected_ticket['consumedAt']=$now;$expected_ticket['version']=$version+1;
    queryfx($this->connection,'UPDATE %R SET state=%s,consumedAt=%d,version=version+1 WHERE BINARY transferID=BINARY %s AND state=%s AND version=%d',
      new PhorgeOrganizationTransfer(),'executed',$now,$id,'approved',$version);
    if($this->connection->getAffectedRows()!==1){throw new RuntimeException('organization_transfer_stale');}
    $before=$data['sources']['primary'];$primary=$before;$primary['unitID']=$data['intent']['to_department'];
    $primary['version']=PhorgeOrganizationService::version($before['version'])+1;
    queryfx($this->connection,'UPDATE organization_primarydepartment SET unitID=%s,version=version+1 WHERE BINARY userPHID=BINARY %s AND BINARY unitID=BINARY %s AND version=%d',
      $primary['unitID'],$row['subjectPHID'],$before['unitID'],PhorgeOrganizationService::version($before['version']));
    if($this->connection->getAffectedRows()!==1){throw new RuntimeException('organization_transfer_primary_stale');}
    $handover=null;$expected_handover=null;$calendar=$data['calendar'];
    if($calendar['days']>0){
      $handover='handover-'.substr(hash('sha256',$id),0,48);
      $expected_handover=array('handoverID'=>$handover,'transferID'=>$id,'subjectPHID'=>$row['subjectPHID'],
        'fromDepartmentID'=>$before['unitID'],'toDepartmentID'=>$primary['unitID'],'responsiblePHID'=>$row['responsiblePHID'],
        'state'=>'active','startAt'=>$calendar['start_at'],'deadlineAt'=>$calendar['deadline_at'],'timezone'=>$calendar['timezone'],
        'startLocal'=>$calendar['start_local'],'deadlineLocal'=>$calendar['deadline_local'],
        'startOffset'=>$calendar['start_offset'],'deadlineOffset'=>$calendar['deadline_offset'],'cumulativeDays'=>$calendar['days'],'version'=>1);
      queryfx($this->connection,'INSERT INTO %R (handoverID,transferID,subjectPHID,fromDepartmentID,toDepartmentID,responsiblePHID,state,startAt,deadlineAt,timezone,startLocal,deadlineLocal,startOffset,deadlineOffset,cumulativeDays,version) VALUES (%s,%s,%s,%s,%s,%s,%s,%d,%d,%s,%s,%s,%d,%d,%d,1)',
        new PhorgeOrganizationHandover(),$handover,$id,$row['subjectPHID'],$before['unitID'],$primary['unitID'],$row['responsiblePHID'],'active',
        $calendar['start_at'],$calendar['deadline_at'],$calendar['timezone'],$calendar['start_local'],$calendar['deadline_local'],
        $calendar['start_offset'],$calendar['deadline_offset'],$calendar['days']);
      $inserted=queryfx_one($this->connection,'SELECT * FROM %R WHERE BINARY handoverID=BINARY %s FOR UPDATE',new PhorgeOrganizationHandover(),$handover);
      if(!$inserted){throw new RuntimeException('organization_transfer_handover_missing');}
      $expected_handover['id']=PhorgeOrganizationService::version($inserted['id']);self::sameRow($inserted,$expected_handover);
    }
    $event='transfer.executed:'.$id;
    queryfx($this->connection,'INSERT INTO %R (eventID,eventType,transferID,handoverID,sourceVersion,state,attempts,createdAt) VALUES (%s,%s,%s,%ns,%d,%s,0,%d)',
      new PhorgeOrganizationOutbox(),$event,'transfer.executed',$id,$handover,$version+1,'pending',$now);
    $expected_event=array('eventID'=>$event,'eventType'=>'transfer.executed','transferID'=>$id,'handoverID'=>$handover,
      'sourceVersion'=>$version+1,'state'=>'pending','attempts'=>0,'createdAt'=>$now,'dispatchedAt'=>null);
    $inserted=queryfx_one($this->connection,'SELECT * FROM %R WHERE BINARY eventID=BINARY %s FOR UPDATE',new PhorgeOrganizationOutbox(),$event);
    if(!$inserted){throw new RuntimeException('organization_transfer_outbox_missing');}
    $expected_event['id']=PhorgeOrganizationService::version($inserted['id']);self::sameRow($inserted,$expected_event);
    $this->final=array('ticket'=>$expected_ticket,'primary'=>$primary,'handover'=>$expected_handover,'event'=>$expected_event);
    $this->audit($operation,'transfer.execute',$primary['unitID'],$row['subjectPHID'],$before,
      array('primary'=>$primary,'handoverID'=>$handover,'calendar'=>$calendar,'native_project_permissions_changed'=>false));
    $versions=array('primary:'.$row['subjectPHID']=>$primary['version'],'transfer:'.$id=>$version+1);
    if($handover!==null){$versions['handover:'.$handover]=1;}
    $result=$this->record($operation,$digest,$versions);
    $this->assertFinal();return $result;
  }
  public function assertFinal(){
    $this->actor();
    if($this->final===null){throw new RuntimeException('organization_transfer_final_check_required');}
    list($row,$data)=self::loadTicket($this->connection,$this->final['ticket']['transferID'],true);
    $this->checkCurrent($row,$data,false);self::sameRow($row,$this->final['ticket']);
    $primary=queryfx_one($this->connection,'SELECT * FROM organization_primarydepartment WHERE BINARY userPHID=BINARY %s FOR UPDATE',$row['subjectPHID']);
    if(!$primary){throw new RuntimeException('organization_transfer_primary_missing');}self::sameRow($primary,$this->final['primary']);
    $handover=queryfx_one($this->connection,'SELECT * FROM %R WHERE BINARY transferID=BINARY %s FOR UPDATE',new PhorgeOrganizationHandover(),$row['transferID']);
    if($this->final['handover']===null){if($handover){throw new RuntimeException('organization_transfer_immediate_has_handover');}}
    else{if(!$handover){throw new RuntimeException('organization_transfer_handover_missing');}self::sameRow($handover,$this->final['handover']);}
    $event=queryfx_one($this->connection,'SELECT * FROM %R WHERE BINARY eventID=BINARY %s FOR UPDATE',new PhorgeOrganizationOutbox(),$this->final['event']['eventID']);
    if(!$event){throw new RuntimeException('organization_transfer_outbox_missing');}self::sameRow($event,$this->final['event']);
    // Sample time/credentials again after the last possible SQL row wait.
    $this->checkCurrent($row,$data,false);
  }
}
