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
    if (!$guard || (string)$guard['id']!=='1' || PhorgeOrganizationService::version($guard['version'])<1) {
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
      'CASE WHEN OCTET_LENGTH(bindingJSON)<=32768 THEN bindingJSON ELSE NULL END bindingJSON,'.
      'CASE WHEN OCTET_LENGTH(approverBindingJSON)<=32768 THEN approverBindingJSON ELSE NULL END approverBindingJSON '.
      'FROM organization_approval WHERE BINARY approvalID=BINARY %s'.($locked?' FOR UPDATE':''),$id);
    if (!$row || $row['approvalID']!==$id) { throw new RuntimeException('organization_approval_missing'); }
    if (PhorgeOrganizationService::version($row['version'])<1) {
      throw new RuntimeException('organization_approval_record_refused');
    }
    $data=self::decode($row['bindingJSON']);
    if (!hash_equals($row['requestDigest'],self::digest($data)) ||
        $row['operationID']!==($data['operationID']??null) ||
        !in_array($row['state'],array('submitted','approved','consumed'),true)) {
      throw new RuntimeException('organization_approval_record_refused');
    }
    return array($row,$data);
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
    queryfx($this->connection,'INSERT INTO organization_approval (approvalID,operationID,requestDigest,bindingJSON,state,version) VALUES (%s,%s,%s,%s,%s,1)',
      $id,$operation,self::digest($data),self::json($data),'submitted');
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
    if($row['state']!=='approved'||!isset($data['parameters']['compound'])||
      $data['executor']['userPHID']!==$actor->getUserPHID()){
      throw new RuntimeException('organization_approval_execution_binding_refused');}
    $this->checkCurrentApproval($row,$data,true);
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
}
