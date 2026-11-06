<?php

/** Durable native approvals on the exact People's organization transaction. */
final class PhorgeGovernanceNativeApprovalAdapter extends Phobject {
  private $service;
  private $connection;
  private $nonce;
  private function __construct() {}
  private function __clone() {}
  public function __serialize() { throw new RuntimeException('organization_approval_serialization_refused'); }
  public function __unserialize(array $value) { throw new RuntimeException('organization_approval_serialization_refused'); }
  public function __debugInfo() { return array('scope'=>'native-organization-approval'); }
  private static function property($service,$name) {
    return (new ReflectionProperty(PhorgeOrganizationService::class,$name))->getValue($service);
  }
  public static function forLockedService(PhorgeOrganizationService $service) {
    if (get_class($service)!==PhorgeOrganizationService::class) {
      throw new RuntimeException('organization_approval_service_required');
    }
    $adapter=new self(); $adapter->service=WeakReference::create($service);
    $adapter->connection=self::property($service,'connection');
    $adapter->nonce=self::property($service,'transactionNonce');
    $adapter->actor(); return $adapter;
  }
  private function actor() {
    PhorgeOrganizationService::assertEnabled();
    $service=$this->service->get();
    if (!$service || !is_string($this->nonce) || !preg_match('/\A[a-f0-9]{32}\z/',$this->nonce) ||
        self::property($service,'transactionNonce')!==$this->nonce ||
        self::property($service,'connection')!==$this->connection ||
        !($this->connection instanceof AphrontDatabaseConnection) ||
        !$this->connection->isInsideTransaction()) {
      throw new RuntimeException('organization_approval_transaction_required');
    }
    $snapshot=PhorgeGovernanceNativeIdentitySource::revalidate(
      self::property($service,'actorSnapshot'),$this->connection);
    if (!$snapshot->isSystemAdmin()) { throw new RuntimeException('organization_approval_admin_required'); }
    $guard=queryfx_one($this->connection,'SELECT id,version FROM organization_guard WHERE id=1 FOR UPDATE');
    if (!$guard || (string)$guard['id']!=='1' || PhorgeOrganizationService::guardGeneration($guard['version'],PhabricatorEnv::getEnvConfig('governance.account-recovery.enabled'))<1) {
      throw new RuntimeException('organization_approval_guard_required');
    }
    return $snapshot;
  }
  private static function json($value) {
    return PhorgeGovernanceOrganizationApprovalAdapter::canonicalJSON($value);
  }
  private static function digest($value) { return hash('sha256',self::json($value)); }
  private static function identifier($value) {
    return PhorgeGovernanceOrganizationApprovalAdapter::validateIdentifier($value);
  }
  private static function params($action,$subject,$from,$until,$compound=null) {
    if(in_array($action,array('member.add-and-appoint','appointment.revoke-and-end'),true)){
      if(!is_array($compound)){throw new RuntimeException('organization_compound_request_refused');}
      $checked=PhorgeOrganizationRequest::compound($compound);
      if($checked['action']!==$action||$checked['target_user']!==$subject||$checked['appointment_from']!==$from||$checked['appointment_until']!==$until){throw new RuntimeException('organization_compound_request_refused');}
      return array('subject'=>$subject,'valid_from'=>$from,'valid_until'=>$until,'compound'=>$checked);
    }
    if($compound!==null){throw new RuntimeException('organization_compound_request_refused');}
    if (!in_array($action,array('appointment.grant','appointment.revoke'),true)) {
      throw new RuntimeException('organization_approval_action_refused');
    }
    return PhorgeGovernanceOrganizationApprovalAdapter::validateParameters(
      $action==='appointment.grant'?'team.admin.grant':'team.admin.revoke',
      array('subject'=>$subject,'valid_from'=>$from,'valid_until'=>$until));
  }
  private function now() {
    $row=queryfx_one($this->connection,'SELECT UNIX_TIMESTAMP() n');
    return (int)$row['n'];
  }
  private static function decode($value) {
    if (!is_string($value) || strlen($value)>32768) {
      throw new RuntimeException('organization_approval_binding_refused');
    }
    $data=json_decode($value,true,16,JSON_THROW_ON_ERROR);
    if (!is_array($data)) { throw new RuntimeException('organization_approval_binding_refused'); }
    self::json($data); return $data;
  }
  private static function load(AphrontDatabaseConnection $conn,$id,$locked) {
    self::identifier($id);
    $row=queryfx_one($conn,
      'SELECT approvalID,operationID,requestDigest,state,approverPHID,approverDigest,'.
      'approvedAt,consumedAt,consumedByOperation,version,'.
      'sourceParticipantVersion,sourceSubjectPHID,sourceRequesterPHID,sourceExecutorPHID,'.
      'CASE WHEN OCTET_LENGTH(bindingJSON)<=32768 THEN bindingJSON ELSE NULL END bindingJSON,'.
      'CASE WHEN OCTET_LENGTH(approverBindingJSON)<=32768 THEN approverBindingJSON ELSE NULL END approverBindingJSON '.
      'FROM organization_approval WHERE BINARY approvalID=BINARY %s'.($locked?' FOR UPDATE':''),$id);
    if (!$row || $row['approvalID']!==$id) { throw new RuntimeException('organization_approval_missing'); }
    if (PhorgeOrganizationService::version($row['version'])<1) {
      throw new RuntimeException('organization_approval_record_refused');
    }
    $data=self::decode($row['bindingJSON']);
    $required=PhabricatorEnv::getEnvConfig('governance.account-recovery.enabled');
    if (!is_bool($required)) { throw new RuntimeException('organization_approval_participants_config_unknown'); }
    self::assertParticipantMetadata($row,$data,$required);
    if (!hash_equals($row['requestDigest'],self::digest($data)) ||
        $row['operationID']!==($data['operationID']??null) ||
        !in_array($row['state'],array('submitted','approved','consumed'),true)) {
      throw new RuntimeException('organization_approval_record_refused');
    }
    return array($row,$data);
  }
  /** Indexed copies are discovery metadata, never an alternative authority. */
  public static function participantMetadata(array $data) {
    $values=array('sourceSubjectPHID'=>$data['parameters']['subject']??null,
      'sourceRequesterPHID'=>$data['requester']['userPHID']??null,
      'sourceExecutorPHID'=>$data['executor']['userPHID']??null);
    foreach ($values as $value) {
      PhorgeRecoveryStopRequest::user($value);
    }
    return array('sourceParticipantVersion'=>1)+$values;
  }

  public static function assertParticipantMetadata(array $row,array $data,$required) {
    if (!is_bool($required)) { throw new InvalidArgumentException('organization_approval_participants_refused'); }
    $expected=self::participantMetadata($data);
    if (in_array($row['sourceParticipantVersion']??null,array(0,'0'),true)) {
      foreach (array('sourceSubjectPHID','sourceRequesterPHID','sourceExecutorPHID') as $key) {
        if (!array_key_exists($key,$row) || $row[$key]!==null) {
          throw new RuntimeException('organization_approval_participants_refused');
        }
      }
      if (!$required) { return; }
      throw new RuntimeException('organization_approval_participants_unindexed');
    }
    if (!in_array($row['sourceParticipantVersion']??null,array(1,'1'),true)) {
      throw new RuntimeException('organization_approval_participants_refused');
    }
    foreach ($expected as $key=>$value) {
      if ($key!=='sourceParticipantVersion' && ($row[$key]??null)!==$value) {
        throw new RuntimeException('organization_approval_participants_refused');
      }
    }
  }

  /** Planning is not authority; all rows and identities are reread under locks. */
  public static function planningPHIDs(array $ids,AphrontDatabaseConnection $conn) {
    if (count($ids)>32 || $conn!==(new PhabricatorUser())->establishConnection('w')) {
      throw new RuntimeException('organization_approval_plan_refused');
    }
    $phids=array();
    foreach ($ids as $id) {
      list($row,$data)=self::load($conn,$id,false);
      foreach (array($data['requester']['userPHID'],$data['executor']['userPHID'],$data['parameters']['subject']) as $phid) {
        PhorgeGovernanceOrganizationApprovalAdapter::validateIdentifier($phid,true); $phids[$phid]=true;
      }
      if ($row['approverPHID']!==null) {
        PhorgeGovernanceOrganizationApprovalAdapter::validateIdentifier($row['approverPHID'],true);
        $phids[$row['approverPHID']]=true;
      }
    }
    $result=array_keys($phids); sort($result,SORT_STRING); return $result;
  }
  private function credential(array $binding) {
    $snapshot=PhorgeGovernanceNativeIdentitySource::lockCredentialBinding($binding,$this->connection);
    if (!$snapshot->isSystemAdmin()) { throw new RuntimeException('organization_approval_admin_required'); }
    return $snapshot;
  }
  private function sources($team,$action,array $parameters,$after=false) {
    self::identifier($team);
    if (strlen($team)>64) { throw new RuntimeException('organization_approval_team_refused'); }
    $accounts=self::property($this->service->get(),'accounts');
    if (!isset($accounts[$parameters['subject']]) ||
        $accounts[$parameters['subject']]['userPHID']!==$parameters['subject']) {
      throw new RuntimeException('organization_approval_identity_plan_changed');
    }
    $identity=PhorgeGovernanceNativeIdentitySource::lockIdentityPHID($parameters['subject'],$this->connection);
    if (in_array($action,array('appointment.grant','member.add-and-appoint'),true) && !$identity['eligible']) {
      throw new RuntimeException('organization_approval_subject_ineligible');
    }
    $unit=queryfx_one($this->connection,'SELECT * FROM organization_unit WHERE BINARY unitID=BINARY %s FOR UPDATE',$team);
    if (!$unit || $unit['unitID']!==$team || $unit['kind']!=='team' || $unit['state']!=='active') {
      throw new RuntimeException('organization_approval_team_refused');
    }
    if (($unit['visibilityModel']??null)!=='restricted-admin-v1' ||
        (string)($unit['visibilityVersion']??'')!=='1') {
      throw new RuntimeException('organization_approval_source_refused');
    }
    if (PhorgeOrganizationService::version($unit['version'])<1) {
      throw new RuntimeException('organization_approval_source_refused');
    }
    $rows=array('unit'=>$unit,'subject'=>$identity);
    foreach (array('member','appointment') as $kind) {
      $row=queryfx_one($this->connection,'SELECT * FROM %T WHERE BINARY unitID=BINARY %s AND BINARY userPHID=BINARY %s FOR UPDATE',
        'organization_'.$kind,$team,$parameters['subject']);
      if ($row) {
        if ($row['unitID']!==$team || $row['userPHID']!==$parameters['subject']) {
          throw new RuntimeException('organization_approval_source_refused');
        }
        if (PhorgeOrganizationService::version($row['version'])<1 ||
            !in_array($row['state'],array('active','ended','suspended'),true) ||
            ($kind==='appointment' && $row['role']!=='team_admin')) {
          throw new RuntimeException('organization_approval_source_refused');
        }
        PhorgeOrganizationService::version($row['validFrom']);
        if ($row['validUntil']!==null &&
            PhorgeOrganizationService::version($row['validUntil'])<PhorgeOrganizationService::version($row['validFrom'])) {
          throw new RuntimeException('organization_approval_source_refused');
        }
      }
      $rows[$kind]=$row;
    }
    if ($action==='appointment.grant') { $this->membership($rows['member'],$parameters,$this->now()); }
    if(isset($parameters['compound'])){
      $c=$parameters['compound'];$now=$this->now();
      if(!$after){
        foreach(array('member','appointment')as$kind){$version=$rows[$kind]?PhorgeOrganizationService::version($rows[$kind]['version']):0;
          if($version!==$c['expected_'.$kind.'_version']){throw new RuntimeException('organization_approval_stale');}}
        if(PhorgeOrganizationService::version($unit['version'])!==$c['expected_team_version']){throw new RuntimeException('organization_approval_stale');}
        if($action==='member.add-and-appoint' && ($rows['member']!==null || $rows['appointment']!==null)){
          throw new RuntimeException('organization_compound_precondition');}
        if($action==='appointment.revoke-and-end' && (!$rows['member']||!$rows['appointment'])){
          throw new RuntimeException('organization_compound_precondition');}
      }else{
        if(PhorgeOrganizationService::version($unit['version'])!==$c['expected_team_version']+1){throw new RuntimeException('organization_compound_postcondition');}
        foreach(array('member','appointment')as$kind){
          $expected_state=$action==='member.add-and-appoint'?'active':'ended';
          if(!$rows[$kind]||$rows[$kind]['state']!==$expected_state||
            PhorgeOrganizationService::version($rows[$kind]['version'])!==$c['expected_'.$kind.'_version']+1){
            throw new RuntimeException('organization_compound_postcondition');}
          if($action==='member.add-and-appoint' && ((int)$rows[$kind]['validFrom']!==$c[$kind.'_from'] ||
            ($rows[$kind]['validUntil']===null?null:(int)$rows[$kind]['validUntil'])!==$c[$kind.'_until'])){
            throw new RuntimeException('organization_compound_postcondition');}
        }
      }
      if($action==='member.add-and-appoint' && ($c['member_from']>$now ||
        ($c['member_until']!==null&&$c['member_until']<=$now)||
        ($c['appointment_until']!==null&&$c['appointment_until']<=$now))){throw new RuntimeException('organization_period_expired');}
    }
    return $rows;
  }
  private function membership($member,array $parameters,$now) {
    if (!$member || $member['state']!=='active' || (int)$member['validFrom']>$now ||
        ($member['validUntil']!==null && (int)$member['validUntil']<=$now) ||
        (int)$member['validFrom']>$parameters['valid_from'] ||
        ($member['validUntil']!==null && ($parameters['valid_until']===null ||
          (int)$member['validUntil']<$parameters['valid_until'])) ||
        ($parameters['valid_until']!==null && $parameters['valid_until']<=$now)) {
      throw new RuntimeException('organization_approval_membership_required');
    }
  }
  private function validTime(array $data,$approved=null) {
    $now=$this->now();
    if (!is_int($data['issuedAt']) || !is_int($data['expiresAt']) ||
        $now<$data['issuedAt'] || $now>=$data['expiresAt'] ||
        ($approved!==null && $now<(int)$approved)) {
      throw new RuntimeException('organization_approval_expired');
    }
    if ($data['action']==='appointment.grant') {
      $member=queryfx_one($this->connection,
        'SELECT * FROM organization_member WHERE BINARY unitID=BINARY %s AND BINARY userPHID=BINARY %s FOR UPDATE',
        $data['team'],$data['parameters']['subject']);
      // Database time must be sampled after even this last possible wait.
      $now=$this->now();
      if ($now>=$data['expiresAt']) { throw new RuntimeException('organization_approval_expired'); }
      $this->membership($member,$data['parameters'],$now);
    }
    if($data['action']==='member.add-and-appoint'){
      $c=$data['parameters']['compound'];$now=$this->now();
      if($now>=$data['expiresAt'] || $c['member_from']>$now ||
        ($c['member_until']!==null&&$c['member_until']<=$now)||
        ($c['appointment_until']!==null&&$c['appointment_until']<=$now)){
        throw new RuntimeException('organization_approval_expired');}
    }
    return $now;
  }
  private function receipt($id,$state,$replayed=false) {
    return array('approval_id'=>$id,'state'=>$state,'replayed'=>$replayed,
      'native_identity'=>true,'can_execute'=>false);
  }
  public function propose($id,$operation,$team,$action,$subject,$from,$until,$ttl,$compound=null) {
    $actor=$this->actor(); self::identifier($id); self::identifier($operation);
    $parameters=self::params($action,$subject,$from,$until,$compound);
    PhorgeGovernanceOrganizationApprovalAdapter::validateTTL($ttl);
    $existing=queryfx_one($this->connection,'SELECT approvalID FROM organization_approval WHERE BINARY approvalID=BINARY %s FOR UPDATE',$id);
    $now=$this->now(); $issued=$now; $expires=$now+$ttl;
    if ($existing) {
      list($row,$old)=self::load($this->connection,$id,true);
      $issued=$old['issuedAt']; $expires=$old['expiresAt'];
      if ($expires-$issued!==$ttl) { throw new RuntimeException('organization_approval_ttl_changed'); }
    }
    $binding=$actor->getSource()->getSnapshotData();
    $data=array('operationID'=>$operation,'requester'=>$binding,'executor'=>$binding,
      'team'=>$team,'action'=>$action,'parameters'=>$parameters,
      'sources'=>$this->sources($team,$action,$parameters),'issuedAt'=>$issued,'expiresAt'=>$expires);
    $this->validTime($data); $this->actor();
    if ($existing) {
      if (!hash_equals($row['requestDigest'],self::digest($data))) { throw new RuntimeException('organization_approval_stale'); }
      return $this->receipt($id,$row['state'],true);
    }
    $participants=self::participantMetadata($data);
    queryfx($this->connection,'INSERT INTO organization_approval (approvalID,operationID,requestDigest,bindingJSON,state,version,sourceParticipantVersion,sourceSubjectPHID,sourceRequesterPHID,sourceExecutorPHID) VALUES (%s,%s,%s,%s,%s,1,1,%s,%s,%s)',
      $id,$operation,self::digest($data),self::json($data),'submitted',
      $participants['sourceSubjectPHID'],$participants['sourceRequesterPHID'],$participants['sourceExecutorPHID']);
    return $this->receipt($id,'submitted');
  }
  private function checkCurrentApproval(array $row,array $data,$compare_sources) {
    $service=$this->service->get();
    $accounts=self::property($service,'accounts');
    $required=array($data['requester']['userPHID'],$data['executor']['userPHID'],$data['parameters']['subject']);
    if ($row['approverPHID']!==null) { $required[]=$row['approverPHID']; }
    foreach ($required as $phid) {
      if (!isset($accounts[$phid]) || $accounts[$phid]['userPHID']!==$phid) {
        throw new RuntimeException('organization_approval_identity_plan_changed');
      }
    }
    $this->credential($data['requester']); $this->credential($data['executor']);
    if ($row['state']!=='submitted') {
      $approver=self::decode($row['approverBindingJSON']);
      $snapshot=$this->credential($approver);
      if ($row['approverPHID']!==$snapshot->getUserPHID() ||
          $snapshot->getUserPHID()===$data['requester']['userPHID'] ||
          $snapshot->getUserPHID()===$data['executor']['userPHID'] ||
          !hash_equals($row['approverDigest'],self::digest($approver))) {
        throw new RuntimeException('organization_approval_independent_admin_required');
      }
    }
    $parameters=self::params($data['action'],$data['parameters']['subject'],
      $data['parameters']['valid_from'],$data['parameters']['valid_until'],idx($data['parameters'],'compound'));
    $sources=$this->sources($data['team'],$data['action'],$parameters,$row['state']==='consumed');
    if ($compare_sources && self::json($sources)!==self::json($data['sources'])) {
      throw new RuntimeException('organization_approval_stale');
    }
    return $this->validTime($data,$row['approvedAt']);
  }
  public function approve($id) {
    $actor=$this->actor(); list($row,$data)=self::load($this->connection,$id,true);
    if ($actor->getUserPHID()===$data['requester']['userPHID'] ||
        $actor->getUserPHID()===$data['executor']['userPHID']) {
      throw new RuntimeException('organization_approval_independent_admin_required');
    }
    $this->checkCurrentApproval($row,$data,true);
    $binding=$actor->getSource()->getSnapshotData();
    if ($row['state']==='approved' && $row['approverPHID']===$actor->getUserPHID() &&
        hash_equals($row['approverDigest'],self::digest($binding))) {
      return $this->receipt($id,'approved',true);
    }
    if ($row['state']!=='submitted') { throw new RuntimeException('organization_approval_transition_refused'); }
    $this->actor(); $now=$this->validTime($data);
    queryfx($this->connection,'UPDATE organization_approval SET state=%s,approverPHID=%s,approverDigest=%s,approverBindingJSON=%s,approvedAt=%d,version=version+1 WHERE BINARY approvalID=BINARY %s AND state=%s',
      'approved',$actor->getUserPHID(),self::digest($binding),self::json($binding),$now,$id,'submitted');
    if ($this->connection->getAffectedRows()!==1) { throw new RuntimeException('organization_approval_transition_refused'); }
    return $this->receipt($id,'approved');
  }
  public function previewCompoundApproval($id) {
    $this->actor();list($row,$data)=self::load($this->connection,$id,true);
    if(!isset($data['parameters']['compound'])||$row['state']!=='submitted'){
      throw new RuntimeException('organization_compound_request_refused');}
    $this->checkCurrentApproval($row,$data,true);
    return $data['parameters']['compound'];
  }
  public function getCompoundForExecution($id) {
    $actor=$this->actor();list($row,$data)=self::load($this->connection,$id,true);
    if(!in_array($row['state'],array('approved','consumed'),true)||!isset($data['parameters']['compound'])||
      $data['executor']['userPHID']!==$actor->getUserPHID()){
      throw new RuntimeException('organization_approval_execution_binding_refused');}
    if($row['state']==='approved'){
      $this->checkCurrentApproval($row,$data,true);
    }else if($row['consumedByOperation']!==$data['operationID']){
      throw new RuntimeException('organization_approval_execution_binding_refused');
    }
    // A committed request is recovered only for its current authorized executor.
    // executeCompound must still find the matching operation-ledger replay;
    // missing/mismatched ledgers reach consume(), which refuses consumed rows.
    return $data['parameters']['compound'];
  }
  public function consume($id,$operation,$team,$action,$subject,$from,$until,$compound=null) {
    $actor=$this->actor(); list($row,$data)=self::load($this->connection,$id,true);
    if ($row['state']!=='approved' || $data['operationID']!==$operation ||
        $data['executor']['userPHID']!==$actor->getUserPHID() ||
        $data['team']!==$team || $data['action']!==$action ||
        self::json($data['parameters'])!==self::json(self::params($action,$subject,$from,$until,$compound)) ||
        self::json($data['executor'])!==self::json($actor->getSource()->getSnapshotData())) {
      throw new RuntimeException('organization_approval_execution_binding_refused');
    }
    $this->checkCurrentApproval($row,$data,true); $this->actor(); $now=$this->validTime($data,$row['approvedAt']);
    queryfx($this->connection,'UPDATE organization_approval SET state=%s,consumedAt=%d,consumedByOperation=%s,version=version+1 WHERE BINARY approvalID=BINARY %s AND state=%s',
      'consumed',$now,$operation,$id,'approved');
    if ($this->connection->getAffectedRows()!==1) { throw new RuntimeException('organization_approval_transition_refused'); }
    return $this->receipt($id,'consumed');
  }
  public function assertCurrent($id,$consumedOperation=null) {
    $actor=$this->actor(); list($row,$data)=self::load($this->connection,$id,true);
    if ($consumedOperation!==null) {
      if ($row['state']!=='consumed' || $row['consumedByOperation']!==$consumedOperation ||
          $data['operationID']!==$consumedOperation ||
          $actor->getUserPHID()!==$data['executor']['userPHID']) {
        throw new RuntimeException('organization_approval_commit_binding_refused');
      }
    } else if (!in_array($row['state'],array('submitted','approved'),true)) {
      throw new RuntimeException('organization_approval_transition_refused');
    }
    $this->checkCurrentApproval($row,$data,$consumedOperation===null);
    $this->actor(); $this->validTime($data,$row['approvedAt']);
    return array('approval_id'=>$id,'commit_gate_checked'=>true);
  }

  /** PRIMARY-ID discovery only. A page never confers approval authority. */
  public static function planWorkbenchRows(array $rows, $limit, $after) {
    if (!is_int($limit) || $limit<1 || $limit>25 ||
        ($after!==null && (!is_string($after) || self::identifier($after)!==$after)) ||
        !array_is_list($rows) || count($rows)>$limit+1) {
      throw new InvalidArgumentException('organization_approval_page_refused');
    }
    $last=$after;$validated=array();
    // Validate the complete bounded metadata response before loading any body.
    foreach ($rows as $row) {
      $keys=is_array($row)?array_keys($row):array();sort($keys);
      $expected=array('approvalID','bindingBytes','approverBytes','sourceParticipantVersion',
        'sourceSubjectPHID','sourceRequesterPHID','sourceExecutorPHID','approverPHID','rowVersion');sort($expected);
      if ($keys!==$expected) { throw new RuntimeException('organization_approval_page_shape_refused'); }
      $id=$row['approvalID'];self::identifier($id);
      if ($last!==null && strcmp($id,$last)<=0) { throw new RuntimeException('organization_approval_page_order_refused'); }
      foreach(array('bindingBytes','approverBytes')as$key){
        $n=$row[$key];
        if ($key==='approverBytes' && $n===null) { continue; }
        if (is_string($n) && preg_match('/\A[1-9][0-9]{0,4}\z/D',$n)) { $n=(int)$n; }
        if (!is_int($n) || $n<1 || $n>32768) { throw new RuntimeException('organization_approval_page_payload_refused'); }
        $row[$key]=$n;
      }
      // Legacy/unindexed metadata is not silently backfilled or guessed.
      if(!in_array($row['sourceParticipantVersion'],array(1,'1'),true)){
        throw new RuntimeException('organization_approval_page_participants_unindexed');
      }
      $row['sourceParticipantVersion']=1;$phids=array();
      foreach(array('sourceSubjectPHID','sourceRequesterPHID','sourceExecutorPHID','approverPHID')as$key){
        if($key==='approverPHID'&&$row[$key]===null){continue;}
        PhorgeRecoveryStopRequest::user($row[$key]);$phids[$row[$key]]=true;
      }
      $row['rowVersion']=PhorgeOrganizationService::version($row['rowVersion']);
      if($row['rowVersion']<1){throw new RuntimeException('organization_approval_page_version_refused');}
      $last=$id;$validated[]=array('row'=>$row,'participants'=>$phids);
    }
    $bytes=0;$subjects=array();$plan=array();
    foreach($validated as$item){
      $row=$item['row'];$next_bytes=$bytes+$row['bindingBytes']+($row['approverBytes']??0);
      $next_subjects=$subjects+$item['participants'];
      // Existing approval plans are bounded to32 and each binding to32KiB.
      // Budget truncation stops BEFORE a body read. Its ID remains for next page.
      if(count($plan)===$limit||$next_bytes>32*32768||count($next_subjects)>32){break;}
      $bytes=$next_bytes;$subjects=$next_subjects;$plan[]=$row;
    }
    $more=count($plan)<count($validated);
    if($more&&!$plan){throw new RuntimeException('organization_approval_page_progress_refused');}
    return array('plan'=>$plan,'next_cursor'=>$more?last($plan)['approvalID']:null,
      'payload_bytes'=>$bytes,'participant_count'=>count($subjects));
  }

  private function workbenchProjection(array $row, array $data) {
    if (in_array($data['action'],array('appointment.grant','appointment.revoke'),true)) { return null; }
    if (!in_array($data['action'],array('member.add-and-appoint','appointment.revoke-and-end'),true)) {
      throw new RuntimeException('organization_approval_action_refused');
    }
    $intent=self::params($data['action'],$data['parameters']['subject'],
      $data['parameters']['valid_from'],$data['parameters']['valid_until'],idx($data['parameters'],'compound'))['compound'];
    if ($intent['approval_id']!==$row['approvalID'] || $intent['operation_id']!==$row['operationID'] ||
        $intent['team']!==$data['team'] || !is_int($data['issuedAt']) || !is_int($data['expiresAt']) ||
        $data['issuedAt']<0 || $data['expiresAt']<=$data['issuedAt']) {
      throw new RuntimeException('organization_approval_projection_refused');
    }
    // Credentials, binding JSON, source snapshots and their hashes never leave this adapter.
    return array('approval_id'=>$row['approvalID'],'state'=>$row['state'],
      'version'=>PhorgeOrganizationService::version($row['version']),
      'team'=>$intent['team'],'target_user'=>$intent['target_user'],'action'=>$intent['action'],
      'requester'=>$data['requester']['userPHID'],'executor'=>$data['executor']['userPHID'],
      'approver'=>$row['approverPHID'],'issued_at'=>$data['issuedAt'],'expires_at'=>$data['expiresAt'],
      'member_from'=>$intent['member_from'],'member_until'=>$intent['member_until'],
      'appointment_from'=>$intent['appointment_from'],'appointment_until'=>$intent['appointment_until'],
      'expected_team_version'=>$intent['expected_team_version'],
      'expected_member_version'=>$intent['expected_member_version'],
      'expected_appointment_version'=>$intent['expected_appointment_version'],
      'may_approve'=>false,'may_execute'=>false,'UI_hints_only'=>true);
  }

  public function workbenchPage($limit,$after) {
    $this->actor();self::planWorkbenchRows(array(),$limit,$after);
    $rows=queryfx_all($this->connection,
      'SELECT approvalID,OCTET_LENGTH(bindingJSON) bindingBytes,OCTET_LENGTH(approverBindingJSON) approverBytes,'.
      'sourceParticipantVersion,sourceSubjectPHID,sourceRequesterPHID,sourceExecutorPHID,approverPHID,version rowVersion '.
      'FROM organization_approval FORCE INDEX (PRIMARY) WHERE approvalID>%s '.
      'ORDER BY approvalID LIMIT %d LOCK IN SHARE MODE',$after??'',$limit+1);
    // All lengths are checked before any payload read; no unindexed state scan.
    $page=self::planWorkbenchRows($rows,$limit,$after);$result=array();
    foreach ($page['plan'] as $item) {
      list($row,$data)=self::load($this->connection,$item['approvalID'],true);
      if (strlen($row['bindingJSON'])!==$item['bindingBytes'] ||
          ($row['approverBindingJSON']===null?null:strlen($row['approverBindingJSON']))!==$item['approverBytes']) {
        throw new RuntimeException('organization_approval_page_changed');
      }
      if(!in_array($row['sourceParticipantVersion'],array(1,'1'),true)||PhorgeOrganizationService::version($row['version'])!==$item['rowVersion']){throw new RuntimeException('organization_approval_page_changed');}
      foreach(array('sourceSubjectPHID','sourceRequesterPHID','sourceExecutorPHID','approverPHID')as$key){
        if($row[$key]!==$item[$key]){throw new RuntimeException('organization_approval_page_changed');}
      }
      $value=$this->workbenchProjection($row,$data);
      if ($value!==null) { $result[]=$value; }
    }
    $this->actor();
    return array('rows'=>$result,'next_cursor'=>$page['next_cursor'],
      'bounded_scanned_rows'=>count($rows),'bounded_payload_rows'=>count($page['plan']),
      'bounded_payload_bytes'=>$page['payload_bytes'],'bounded_participant_count'=>$page['participant_count'],'UI_hints_only'=>true);
  }

  public function workbenchDetail($id) {
    $actor=$this->actor();list($row,$data)=self::load($this->connection,$id,true);
    $result=$this->workbenchProjection($row,$data);
    if ($result===null) { throw new RuntimeException('organization_compound_request_refused'); }
    if ($row['state']!=='consumed') {
      // Expiry, participant credentials and every original source are checked by
      // the same native adapter used by approve/consume. Unknown errors propagate.
      $this->checkCurrentApproval($row,$data,true);
      $result['may_approve']=$row['state']==='submitted' &&
        $actor->getUserPHID()!==$data['requester']['userPHID'] &&
        $actor->getUserPHID()!==$data['executor']['userPHID'];
      $result['may_execute']=$row['state']==='approved' &&
        $actor->getUserPHID()===$data['executor']['userPHID'];
    }
    $this->actor();return $result;
  }
}
