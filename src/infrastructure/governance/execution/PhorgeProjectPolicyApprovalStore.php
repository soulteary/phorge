<?php

/** Native Project-specific tickets; never consumes on a separate People writer. */
final class PhorgeProjectPolicyApprovalStore extends Phobject {
  private $scope;
  private $connection;
  private function __construct() {}
  private function __clone() {}
  public function __serialize() { throw new RuntimeException('project_execution_store_serialization_refused'); }
  public function __unserialize(array $value) { throw new RuntimeException('project_execution_store_serialization_refused'); }
  public function __debugInfo() { return array('scope'=>'project-native-approval-store'); }
  public static function forScope(PhorgeProjectNativeExecutionScope $scope) {
    if (get_class($scope)!==PhorgeProjectNativeExecutionScope::class) { throw new RuntimeException('project_execution_scope_required'); }
    $scope->assertOpen(); $store=new self(); $store->scope=$scope;
    $store->connection=(new ReflectionProperty(PhorgeProjectNativeExecutionScope::class,'connection'))->getValue($scope);
    return $store;
  }
  private static function row(AphrontDatabaseConnection $conn,$id,$lock) {
    PhorgeProjectExecutionValue::identifier($id);
    $row=queryfx_one($conn,'SELECT approvalID,operationID,targetPHID,bindingDigest,state,approvedAt,consumedAt,nativeGroupID,version,'.
      'CASE WHEN OCTET_LENGTH(bindingJSON)<=65536 THEN bindingJSON ELSE NULL END bindingJSON,'.
      'CASE WHEN OCTET_LENGTH(approverBindingJSON)<=65536 THEN approverBindingJSON ELSE NULL END approverBindingJSON '.
      'FROM governance_projectapproval WHERE BINARY approvalID=BINARY %s'.($lock?' FOR UPDATE':''),$id);
    if (!$row || $row['approvalID']!==$id || !is_string($row['bindingJSON']) ||
        !in_array($row['state'],array('submitted','approved','consumed'),true) || (int)$row['version']<1) {
      throw new RuntimeException('project_execution_ticket_refused');
    }
    $data=json_decode($row['bindingJSON'],true,16,JSON_THROW_ON_ERROR);
    if (!is_array($data) || !hash_equals($row['bindingDigest'],PhorgeProjectExecutionValue::digest($data)) ||
        $row['targetPHID']!==($data['targetPHID']??null) || $row['operationID']!==($data['operationID']??null)) {
      throw new RuntimeException('project_execution_ticket_refused');
    }
    return array($row,$data);
  }
  public static function planning(AphrontDatabaseConnection $conn,$id) {
    if ($conn!==(new PhabricatorProject())->establishConnection('w')) { throw new RuntimeException('project_execution_writer_required'); }
    list($row,$data)=self::row($conn,$id,false);
    $ids=array($data['executor']['userPHID']);
    if ($row['approverBindingJSON']!==null) {
      $approver=json_decode($row['approverBindingJSON'],true,16,JSON_THROW_ON_ERROR);
      $ids[]=$approver['userPHID'];
    }
    foreach ($ids as $phid) {
      if (!is_string($phid) || !preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/',$phid)) {
        throw new RuntimeException('project_execution_identity_plan_refused');
      }
    }
    return array_values(array_unique($ids));
  }
  public static function intent($id) {
    $conn=(new PhabricatorProject())->establishConnection('w');
    list($row,$data)=self::row($conn,$id,false);
    return array('targetPHID'=>$data['targetPHID'],'newPolicy'=>$data['newPolicy']);
  }
  private function now() { return (int)queryfx_one($this->connection,'SELECT UNIX_TIMESTAMP() n')['n']; }
  private function validTime(array $data,$approved=null) {
    $this->scope->assertOpen(); $now=$this->now();
    if (!is_int($data['issuedAt']) || !is_int($data['expiresAt']) ||
        $now<$data['issuedAt'] || $now>=$data['expiresAt'] || ($approved!==null && $now<(int)$approved)) {
      throw new RuntimeException('project_execution_ticket_expired');
    }
    return $now;
  }
  private function audit($id,array $data,$action,$details) {
    queryfx($this->connection,'INSERT INTO governance_executionaudit (approvalID,operationID,targetPHID,actorPHID,action,dataJSON,createdAt) VALUES (%s,%s,%s,%s,%s,%s,%d)',
      $id,$data['operationID'],$data['targetPHID'],$this->scope->getSnapshot()->getUserPHID(),$action,
      PhorgeProjectExecutionValue::canonical($details),$this->now());
  }
  public function submit($reason,$ttl) {
    if ($this->scope->getMode()!=='propose' || !is_string($reason) || trim($reason)==='' ||
        strlen($reason)>2048 || !phutil_is_utf8($reason) || !is_int($ttl) || $ttl<1 || $ttl>86400) {
      throw new RuntimeException('project_execution_proposal_refused');
    }
    if ($this->scope->getObject()->getEditPolicy()===$this->scope->getNewPolicy()) {
      throw new RuntimeException('project_execution_no_change');
    }
    $id=bin2hex(random_bytes(32)); $now=$this->now();
    $data=array('targetPHID'=>$this->scope->getObject()->getPHID(),
      'operationID'=>bin2hex(random_bytes(16)),'operation'=>'project.edit-policy',
      'newPolicy'=>$this->scope->getNewPolicy(),'reason'=>$reason,
      'executor'=>$this->scope->getSnapshot()->getSource()->getSnapshotData(),
      'sources'=>$this->scope->getSources(),'issuedAt'=>$now,'expiresAt'=>$now+$ttl);
    queryfx($this->connection,'INSERT INTO governance_projectapproval (approvalID,operationID,targetPHID,bindingJSON,bindingDigest,reason,state,version) VALUES (%s,%s,%s,%s,%s,%s,%s,1)',
      $id,$data['operationID'],$data['targetPHID'],PhorgeProjectExecutionValue::canonical($data),
      PhorgeProjectExecutionValue::digest($data),$reason,'submitted');
    $this->audit($id,$data,'submitted',array('operation'=>'project.edit-policy'));
    $this->validTime($data);
    return array('approval_id'=>$id);
  }
  private function validate(array $row,array $data,$compare_sources=true) {
    $this->scope->assertOpen();
    if ($data['operation']!=='project.edit-policy' ||
        $data['targetPHID']!==$this->scope->getObject()->getPHID() ||
        $data['newPolicy']!==$this->scope->getNewPolicy()) {
      throw new RuntimeException('project_execution_ticket_binding_changed');
    }
    $this->scope->validateCredential($data['executor']);
    if ($row['state']!=='submitted') {
      if (!is_string($row['approverBindingJSON'])) { throw new RuntimeException('project_execution_approval_missing'); }
      $approver=json_decode($row['approverBindingJSON'],true,16,JSON_THROW_ON_ERROR);
      $this->scope->validateCredential($approver,true);
      if ($approver['userPHID']===$data['executor']['userPHID']) {
        throw new RuntimeException('project_execution_independent_admin_required');
      }
    }
    if ($compare_sources && PhorgeProjectExecutionValue::canonical($data['sources'])!==
        PhorgeProjectExecutionValue::canonical($this->scope->getSources())) {
      throw new RuntimeException('project_execution_sources_stale');
    }
    return $this->validTime($data,$row['approvedAt']);
  }
  public function approve($id) {
    if ($this->scope->getMode()!=='approve' || !$this->scope->getSnapshot()->isSystemAdmin()) {
      throw new RuntimeException('project_execution_approver_admin_required');
    }
    list($row,$data)=self::row($this->connection,$id,true);
    $binding=$this->scope->getSnapshot()->getSource()->getSnapshotData();
    if ($binding['userPHID']===$data['executor']['userPHID']) { throw new RuntimeException('project_execution_independent_admin_required'); }
    $this->validate($row,$data);
    if ($row['state']==='approved' &&
        PhorgeProjectExecutionValue::canonical(json_decode($row['approverBindingJSON'],true))===PhorgeProjectExecutionValue::canonical($binding)) {
      return array('approval_id'=>$id,'replayed'=>true);
    }
    if ($row['state']!=='submitted') { throw new RuntimeException('project_execution_transition_refused'); }
    $now=$this->validTime($data);
    queryfx($this->connection,'UPDATE governance_projectapproval SET state=%s,approverBindingJSON=%s,approvedAt=%d,version=version+1 WHERE BINARY approvalID=BINARY %s AND state=%s',
      'approved',PhorgeProjectExecutionValue::canonical($binding),$now,$id,'submitted');
    if ($this->connection->getAffectedRows()!==1) { throw new RuntimeException('project_execution_transition_refused'); }
    $this->audit($id,$data,'approved',array('independent'=>true)); $this->validTime($data,$now);
    return array('approval_id'=>$id,'replayed'=>false);
  }
  public function assertServiceCurrent($id) {
    list($row,$data)=self::row($this->connection,$id,true);
    if ($this->scope->getMode()==='history') {
      $this->scope->assertOpen();
      if (!$this->scope->getSnapshot()->isSystemAdmin()) { throw new RuntimeException('project_execution_history_admin_required'); }
    } else { $this->validate($row,$data); }
  }
  public function history($id) {
    if ($this->scope->getMode()!=='history' || !$this->scope->getSnapshot()->isSystemAdmin()) {
      throw new RuntimeException('project_execution_history_admin_required');
    }
    list($row,$data)=self::row($this->connection,$id,true);
    $this->scope->assertOpen();
    $rows=queryfx_all($this->connection,'SELECT actorPHID,action,createdAt FROM governance_executionaudit WHERE BINARY approvalID=BINARY %s ORDER BY id LIMIT 65 LOCK IN SHARE MODE',$id);
    if (count($rows)>64) { throw new RuntimeException('project_execution_history_budget'); }
    return array('approval_id'=>$id,'target_phid'=>$data['targetPHID'],'operation'=>$data['operation'],
      'new_policy'=>$data['newPolicy'],'reason'=>$data['reason'],'state'=>$row['state'],
      'native_group_id'=>$row['nativeGroupID'],'audit'=>$rows);
  }
  public function consume($id) {
    if ($this->scope->getMode()!=='execute') { throw new RuntimeException('project_execution_scope_required'); }
    list($row,$data)=self::row($this->connection,$id,true);
    if ($row['state']!=='approved' ||
        PhorgeProjectExecutionValue::canonical($data['executor'])!==
          PhorgeProjectExecutionValue::canonical($this->scope->getSnapshot()->getSource()->getSnapshotData())) {
      throw new RuntimeException('project_execution_execution_binding_refused');
    }
    $this->validate($row,$data); $now=$this->validTime($data,$row['approvedAt']);
    queryfx($this->connection,'UPDATE governance_projectapproval SET state=%s,consumedAt=%d,version=version+1 WHERE BINARY approvalID=BINARY %s AND state=%s',
      'consumed',$now,$id,'approved');
    if ($this->connection->getAffectedRows()!==1) { throw new RuntimeException('project_execution_transition_refused'); }
  }
  public function finishNative($id,array $xactions) {
    list($row,$data)=self::row($this->connection,$id,true);
    if ($row['state']!=='consumed' || count($xactions)!==1) { throw new RuntimeException('project_execution_commit_binding_refused'); }
    $this->validate($row,$data,false);
    $group=$xactions[0]->getTransactionGroupID();
    $native=queryfx_one($this->connection,'SELECT phid,objectPHID,authorPHID,transactionType,newValue,metadata FROM %R WHERE id=%d LOCK IN SHARE MODE',
      new PhabricatorProjectTransaction(),$xactions[0]->getID());
    $stored=$native?(new PhabricatorProjectTransaction())->loadFromArray($native):null;
    if (!$native || $native['phid']!==$xactions[0]->getPHID() || $native['objectPHID']!==$data['targetPHID'] ||
        $native['transactionType']!==PhabricatorTransactions::TYPE_EDIT_POLICY ||
        $native['authorPHID']!==$this->scope->getSnapshot()->getUserPHID() ||
        $stored->getTransactionGroupID()!==$group ||
        $stored->getNewValue()!==$data['newPolicy']) {
      throw new RuntimeException('project_execution_native_record_mismatch');
    }
    queryfx($this->connection,'UPDATE governance_projectapproval SET nativeGroupID=%s WHERE BINARY approvalID=BINARY %s AND state=%s',
      $group,$id,'consumed');
    $this->audit($id,$data,'executed',array('nativeTransactionPHID'=>$native['phid'],'nativeGroupID'=>$group));
    // Row locks prevent credential revocation writes, not passage of time
    // while native record/audit writes wait. Recheck both actual credentials
    // after the last write; only the approved target transition is exempt.
    $this->validate($row,$data,false);
  }
}
