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
    PhorgeProjectPolicyTicketState::identifier($id);
    $row=queryfx_one($conn,'SELECT approvalID,operationID,targetPHID,bindingDigest,state,approvedAt,consumedAt,nativeGroupID,version,'.
      'CASE WHEN OCTET_LENGTH(bindingJSON)<=65536 THEN bindingJSON ELSE NULL END bindingJSON,'.
      'CASE WHEN OCTET_LENGTH(approverBindingJSON)<=65536 THEN approverBindingJSON ELSE NULL END approverBindingJSON '.
      'FROM governance_projectapproval WHERE BINARY approvalID=BINARY %s'.($lock?' FOR UPDATE':''),$id);
    return PhorgeProjectPolicyTicketState::decode($id,$row);
  }
  public static function planning(AphrontDatabaseConnection $conn,$id) {
    if ($conn!==(new PhabricatorProject())->establishConnection('w')) { throw new RuntimeException('project_execution_writer_required'); }
    list($row,$data)=self::row($conn,$id,false);
    $ids=array_merge(array($data['executor']['userPHID']),PhorgeProjectExecutionIntent::newFromBinding($data)->getMembers());
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
    return array('targetPHID'=>$data['targetPHID'],'newPolicy'=>$data['newPolicy'],
      'intent'=>PhorgeProjectExecutionIntent::newFromBinding($data));
  }
  private function now() { return (int)queryfx_one($this->connection,'SELECT UNIX_TIMESTAMP() n')['n']; }
  private function validTime(array $data,$approved=null) {
    $this->scope->assertOpen(); $now=$this->now();
    return PhorgeProjectPolicyTicketState::validAt($data,$approved,$now);
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
    $intent=$this->scope->getIntent();
    $delta=$intent->isMembership()?$this->scope->getProposedMembershipDelta():null;
    if ($intent->isMembership()?(!$delta['added']&&!$delta['removed']):
        ($intent->isViewPolicy()?$this->scope->getObject()->getViewPolicy():$this->scope->getObject()->getEditPolicy())===$this->scope->getNewPolicy()) {
      throw new RuntimeException('project_execution_no_change');
    }
    $id=bin2hex(random_bytes(32)); $now=$this->now();
    $data=array('targetPHID'=>$this->scope->getObject()->getPHID(),
      'operationID'=>bin2hex(random_bytes(16)),'operation'=>$intent->getOperation(),
      'newPolicy'=>$this->scope->getNewPolicy(),'reason'=>$reason,
      'executor'=>$this->scope->getSnapshot()->getSource()->getSnapshotData(),
      'sources'=>$this->scope->getSources(),'issuedAt'=>$now,'expiresAt'=>$now+$ttl);
    if($intent->isMembership()||$intent->isViewPolicy()){$data['intent']=$intent->getDescriptor();}
    queryfx($this->connection,'INSERT INTO governance_projectapproval (approvalID,operationID,targetPHID,bindingJSON,bindingDigest,reason,state,version) VALUES (%s,%s,%s,%s,%s,%s,%s,1)',
      $id,$data['operationID'],$data['targetPHID'],PhorgeProjectExecutionValue::canonical($data),
      PhorgeProjectExecutionValue::digest($data),$reason,'submitted');
    $this->audit($id,$data,'submitted',array('operation'=>$intent->getOperation()));
    $this->validTime($data);
    return array('approval_id'=>$id);
  }
  private function validate(array $row,array $data,$compare_sources=true) {
    $this->scope->assertOpen();
    $intent=PhorgeProjectExecutionIntent::newFromBinding($data);
    if ($data['targetPHID']!==$this->scope->getObject()->getPHID() ||
        PhorgeProjectExecutionValue::canonical($intent->getDescriptor())!==
          PhorgeProjectExecutionValue::canonical($this->scope->getIntent()->getDescriptor())) {
      throw PhorgeProjectPolicyRefusalException::newForReason('ticket-binding');
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
      throw PhorgeProjectPolicyRefusalException::newForReason('sources-stale');
    }
    return $this->validTime($data,$row['approvedAt']);
  }
  public function approve($id) {
    if ($this->scope->getMode()!=='approve' || !$this->scope->getSnapshot()->isSystemAdmin()) {
      throw new RuntimeException('project_execution_approver_admin_required');
    }
    list($row,$data)=self::row($this->connection,$id,true);
    $binding=$this->scope->getSnapshot()->getSource()->getSnapshotData();
    if ($binding['userPHID']===$data['executor']['userPHID']) { throw PhorgeProjectPolicyRefusalException::newForReason('independent-approver'); }
    $this->validate($row,$data);
    if ($row['state']==='approved' &&
        PhorgeProjectExecutionValue::canonical(json_decode($row['approverBindingJSON'],true))===PhorgeProjectExecutionValue::canonical($binding)) {
      return array('approval_id'=>$id,'replayed'=>true);
    }
    PhorgeProjectPolicyTicketState::requireSubmitted($row['state']);
    $now=$this->validTime($data);
    queryfx($this->connection,'UPDATE governance_projectapproval SET state=%s,approverBindingJSON=%s,approvedAt=%d,version=version+1 WHERE BINARY approvalID=BINARY %s AND state=%s',
      'approved',PhorgeProjectExecutionValue::canonical($binding),$now,$id,'submitted');
    PhorgeProjectPolicyTicketState::requireUpdated($this->connection->getAffectedRows());
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
    $result=array('approval_id'=>$id,'target_phid'=>$data['targetPHID'],'operation'=>$data['operation'],
      'new_policy'=>$data['newPolicy'],'reason'=>$data['reason'],'state'=>$row['state'],
      'native_group_id'=>$row['nativeGroupID'],'audit'=>$rows);
    if(isset($data['intent'])){$result['intent']=$data['intent'];}
    return $result;
  }
  public function consume($id) {
    if ($this->scope->getMode()!=='execute') { throw new RuntimeException('project_execution_scope_required'); }
    list($row,$data)=self::row($this->connection,$id,true);
    if ($row['state']!=='approved' ||
        PhorgeProjectExecutionValue::canonical($data['executor'])!==
          PhorgeProjectExecutionValue::canonical($this->scope->getSnapshot()->getSource()->getSnapshotData())) {
      throw PhorgeProjectPolicyRefusalException::newForReason('execution-binding');
    }
    $this->validate($row,$data); $now=$this->validTime($data,$row['approvedAt']);
    queryfx($this->connection,'UPDATE governance_projectapproval SET state=%s,consumedAt=%d,version=version+1 WHERE BINARY approvalID=BINARY %s AND state=%s',
      'consumed',$now,$id,'approved');
    PhorgeProjectPolicyTicketState::requireUpdated($this->connection->getAffectedRows());
  }
  public function finishNative($id,array $xactions) {
    list($row,$data)=self::row($this->connection,$id,true);
    if ($row['state']!=='consumed' || count($xactions)!==1) { throw new RuntimeException('project_execution_commit_binding_refused'); }
    $this->validate($row,$data,false);
    $group=$xactions[0]->getTransactionGroupID();
    $native=queryfx_one($this->connection,'SELECT phid,objectPHID,authorPHID,transactionType,oldValue,newValue,metadata FROM %R WHERE id=%d LOCK IN SHARE MODE',
      new PhabricatorProjectTransaction(),$xactions[0]->getID());
    $stored=$native?(new PhabricatorProjectTransaction())->loadFromArray($native):null;
    if (!$native || $native['phid']!==$xactions[0]->getPHID() || $native['objectPHID']!==$data['targetPHID'] ||
        $native['authorPHID']!==$this->scope->getSnapshot()->getUserPHID() ||
        $stored->getTransactionGroupID()!==$group) {
      throw new RuntimeException('project_execution_native_record_mismatch');
    }
    $intent=PhorgeProjectExecutionIntent::newFromBinding($data);
    if($intent->isMembership()){
      if($native['transactionType']!==PhabricatorTransactions::TYPE_EDGE ||
        $stored->getMetadataValue('edge:type')!==PhabricatorProjectProjectHasMemberEdgeType::EDGECONST){
        throw new RuntimeException('project_execution_native_record_mismatch');}
      $change=PhabricatorEdgeChangeRecord::newFromTransaction($stored);
      $added=$change->getAddedPHIDs();$removed=$change->getRemovedPHIDs();
      sort($added,SORT_STRING);sort($removed,SORT_STRING);
      $expected=$this->scope->getMembershipDelta();
      if($added!==$expected['added']||$removed!==$expected['removed']){
        throw new RuntimeException('project_execution_native_record_mismatch');}
    }else if($native['transactionType']!==$intent->getTransactionType() || $stored->getNewValue()!==$data['newPolicy']){
      throw new RuntimeException('project_execution_native_record_mismatch');
    }
    queryfx($this->connection,'UPDATE governance_projectapproval SET nativeGroupID=%s WHERE BINARY approvalID=BINARY %s AND state=%s',
      $group,$id,'consumed');
    $this->audit($id,$data,'executed',array('nativeTransactionPHID'=>$native['phid'],'nativeGroupID'=>$group));
    $this->validate($row,$data,false);
  }
}
