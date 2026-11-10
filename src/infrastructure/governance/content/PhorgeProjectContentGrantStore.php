<?php

/** Durable explicit grants and approvals on the live, sealed Project writer. */
final class PhorgeProjectContentGrantStore extends Phobject {
  private $scope;
  private $connection;
  private function __construct() {}
  private function __clone() {}
  public function __serialize() { throw new RuntimeException('content_grant_serialization_refused'); }
  public function __unserialize(array $v) { throw new RuntimeException('content_grant_serialization_refused'); }
  public function __debugInfo() { return array('scope'=>'native-Project-content-grant-preparation'); }
  public static function forScope(PhorgeProjectNativeExecutionScope $scope) {
    PhorgeProjectContentGrantService::assertEnabled();
    if (get_class($scope)!==PhorgeProjectNativeExecutionScope::class || $scope->getMode()!=='history') {
      throw new RuntimeException('content_grant_native_scope_required');
    }
    $scope->assertOpen();
    if (!$scope->getSnapshot()->isSystemAdmin()) { throw new RuntimeException('content_grant_admin_required'); }
    $s=new self();$s->scope=$scope;
    $s->connection=(new ReflectionProperty(PhorgeProjectNativeExecutionScope::class,'connection'))->getValue($scope);
    foreach (array('governance_contentgrant'=>'grantID','governance_contentapproval'=>'approvalID','governance_contentaudit'=>'id') as $table=>$key) {
      queryfx_one($s->connection,'SELECT %C FROM %T WHERE 1=0 LOCK IN SHARE MODE',$key,$table);
      $engine=queryfx_one($s->connection,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table);
      if (!$engine || $engine['ENGINE']!=='InnoDB') { throw new RuntimeException('content_grant_schema_required'); }
    }
    return $s;
  }
  private static function ticket(AphrontDatabaseConnection $conn,$id,$locked) {
    PhorgeProjectContentGrantRequest::identifier($id);
    $row=queryfx_one($conn,'SELECT approvalID,targetPHID,state,bindingDigest,CASE WHEN OCTET_LENGTH(bindingJSON)<=65536 THEN bindingJSON ELSE NULL END bindingJSON FROM governance_contentapproval WHERE approvalID=%s'.($locked?' FOR UPDATE':''),$id);
    if (!$row || $row['approvalID']!==$id || !is_string($row['bindingJSON']) ||
        !in_array($row['state'],array('submitted','applied'),true)) { throw new RuntimeException('content_grant_ticket_refused'); }
    $d=json_decode($row['bindingJSON'],true,16,JSON_THROW_ON_ERROR);
    if (!is_array($d) || PhorgeProjectExecutionValue::digest($d)!==$row['bindingDigest'] ||
        ($d['request']['target_phid']??null)!==$row['targetPHID'] ||
        ($d['request']['operation']??null)!==PhorgeProjectContentGrantRequest::OPERATION) {
      throw new RuntimeException('content_grant_ticket_binding_refused');
    }
    $keys=array_keys($d);sort($keys,SORT_STRING);
    if ($keys!==array('grantExpiresAt','grantID','issuedAt','request','requester','sources','subject','ticketExpiresAt') ||
        PhorgeProjectExecutionValue::canonical($d)!==$row['bindingJSON'] ||
        !is_array($d['request'])||!is_array($d['requester'])||!is_array($d['sources'])||!is_array($d['subject']) ||
        !PhorgeGovernanceOrganizationRequest::userID($d['requester']['userPHID']??null)) {
      throw new RuntimeException('content_grant_ticket_binding_refused');
    }
    PhorgeProjectContentGrantRequest::identifier($d['grantID']);
    PhorgeProjectContentGrantRequest::stored($d['request']);
    return array($row,$d);
  }
  /** Planning only. Current actor and every participant are relocked later. */
  public static function planning($id) {
    $conn=(new PhabricatorProject())->establishConnection('w');
    list($row,$d)=self::ticket($conn,$id,false);
    $ids=array($d['requester']['userPHID']);
    if ($d['request']['subject_kind']==='user' && $d['request']['action']==='grant') { $ids[]=$d['request']['subject_id']; }
    return PhorgeProjectContentGrantPlan::newForStoredApproval($row['targetPHID'],array_values(array_unique($ids)));
  }
  private function now() { return (int)queryfx_one($this->connection,'SELECT UNIX_TIMESTAMP() n')['n']; }
  private function assertCurrentGrantScope() {
    PhorgeProjectContentGrantService::assertEnabled();$this->scope->assertOpen();
    if (!$this->scope->getSnapshot()->isSystemAdmin()) { throw new RuntimeException('content_grant_admin_required'); }
  }
  private function grant($id) {
    PhorgeProjectContentGrantRequest::identifier($id);
    $row=queryfx_one($this->connection,'SELECT grantID,targetPHID,operation,subjectKind,subjectID,validFrom,validUntil,revokedAt,version FROM governance_contentgrant WHERE grantID=%s FOR UPDATE',$id);
    if (!$row || $row['grantID']!==$id) { throw new RuntimeException('content_grant_missing'); }
    return $row;
  }
  private function subject(array $r) {
    // Ending authority must never depend on the departed recipient still being
    // active, present in a team, or even present as an account/directory row.
    if ($r['action']==='revoke') {
      $g=$this->grant($r['grant_id']);
      if ($g['targetPHID']!==$r['target_phid'] || $g['operation']!==$r['operation'] ||
          $g['subjectKind']!==$r['subject_kind'] || $g['subjectID']!==$r['subject_id'] || $g['revokedAt']!==null) {
        throw new RuntimeException('content_grant_revoke_binding_refused');
      }
      return array('revocation_grant_digest'=>PhorgeProjectExecutionValue::digest($g));
    }
    return PhorgeProjectContentSource::subject($this->scope,$r['subject_kind'],$r['subject_id'],true);
  }
  private function audit($approval,$grant,$action) {
    queryfx($this->connection,'INSERT INTO governance_contentaudit (approvalID,grantID,targetPHID,actorPHID,action,createdAt) VALUES (%s,%s,%s,%s,%s,%d)',
      $approval,$grant,$this->scope->getObject()->getPHID(),$this->scope->getSnapshot()->getUserPHID(),$action,$this->now());
  }
  public function submit(array $r) {
    $this->assertCurrentGrantScope();$r=PhorgeProjectContentGrantRequest::stored($r);
    if ($r['target_phid']!==$this->scope->getObject()->getPHID()) { throw new RuntimeException('content_grant_target_refused'); }
    $subject=$this->subject($r);$now=$this->now();$id=bin2hex(random_bytes(32));
    $grant=$r['action']==='grant'?bin2hex(random_bytes(32)):$r['grant_id'];
    $d=array('request'=>$r,'grantID'=>$grant,'requester'=>$this->scope->getSnapshot()->getSource()->getSnapshotData(),
      'sources'=>$this->scope->getSources(),'subject'=>$subject,'issuedAt'=>$now,'ticketExpiresAt'=>$now+300,
      'grantExpiresAt'=>$r['action']==='grant'?$now+$r['valid_for_seconds']:null);
    queryfx($this->connection,'INSERT INTO governance_contentapproval (approvalID,targetPHID,bindingJSON,bindingDigest,state,version) VALUES (%s,%s,%s,%s,%s,1)',
      $id,$r['target_phid'],PhorgeProjectExecutionValue::canonical($d),PhorgeProjectExecutionValue::digest($d),'submitted');
    $this->audit($id,$grant,'submitted');$this->assertCurrentGrantScope();return array('approval_id'=>$id,'execution_authorized'=>false);
  }
  public function approve($id) {
    $this->assertCurrentGrantScope();list($row,$d)=self::ticket($this->connection,$id,true);$r=$d['request'];
    if ($row['state']!=='submitted' || $row['targetPHID']!==$this->scope->getObject()->getPHID() ||
        $d['requester']['userPHID']===$this->scope->getSnapshot()->getUserPHID()) { throw new RuntimeException('content_grant_independent_approver_required'); }
    $requester=$this->scope->validateCredential($d['requester'],true);
    if (PhorgeProjectExecutionValue::digest($this->scope->getSources())!==PhorgeProjectExecutionValue::digest($d['sources']) || PhorgeProjectExecutionValue::digest($this->subject($r))!==PhorgeProjectExecutionValue::digest($d['subject'])) { throw new RuntimeException('content_grant_sources_stale'); }
    $now=$this->now();
    if (!is_int($d['issuedAt']) || !is_int($d['ticketExpiresAt']) || $d['issuedAt']>$now ||
        $d['ticketExpiresAt']<=$now || $d['ticketExpiresAt']-$d['issuedAt']!==300) { throw new RuntimeException('content_grant_ticket_expired'); }
    if ($r['action']==='grant') {
      if (!is_int($d['grantExpiresAt']) || $d['grantExpiresAt']<=$now || $d['grantExpiresAt']-$d['issuedAt']!==$r['valid_for_seconds']) { throw new RuntimeException('content_grant_period_refused'); }
      $existing=queryfx_one($this->connection,'SELECT grantID FROM governance_contentgrant WHERE targetPHID=%s AND operation=%s AND subjectKind=%s AND subjectID=%s AND revokedAt IS NULL AND validUntil>%d LIMIT 1 FOR UPDATE',$r['target_phid'],$r['operation'],$r['subject_kind'],$r['subject_id'],$now);
      if ($existing) { throw new RuntimeException('content_grant_existing_active_refused'); }
      queryfx($this->connection,'INSERT INTO governance_contentgrant (grantID,targetPHID,operation,subjectKind,subjectID,validFrom,validUntil,revokedAt,version) VALUES (%s,%s,%s,%s,%s,%d,%d,NULL,1)',
        $d['grantID'],$r['target_phid'],$r['operation'],$r['subject_kind'],$r['subject_id'],$now,$d['grantExpiresAt']);
    } else {
      queryfx($this->connection,'UPDATE governance_contentgrant SET revokedAt=%d,version=version+1 WHERE grantID=%s AND revokedAt IS NULL',$now,$d['grantID']);
      if ($this->connection->getAffectedRows()!==1) { throw new RuntimeException('content_grant_revoke_stale'); }
    }
    $binding=$this->scope->getSnapshot()->getSource()->getSnapshotData();
    queryfx($this->connection,'UPDATE governance_contentapproval SET state=%s,approverBindingJSON=%s,appliedAt=%d,version=version+1 WHERE approvalID=%s AND state=%s','applied',PhorgeProjectExecutionValue::canonical($binding),$now,$id,'submitted');
    if ($this->connection->getAffectedRows()!==1) { throw new RuntimeException('content_grant_ticket_stale'); }
    $this->audit($id,$d['grantID'],$r['action']==='grant'?'granted':'revoked');
    $this->scope->validateCredential($d['requester'],true);$this->assertCurrentGrantScope();
    if ($this->now()>=$d['ticketExpiresAt'] || ($r['action']==='grant' && $this->now()>=$d['grantExpiresAt'])) { throw new RuntimeException('content_grant_ticket_expired'); }
    return array('approval_id'=>$id,'grant_id'=>$d['grantID'],'grant_persisted'=>$r['action']==='grant','grant_revoked'=>$r['action']==='revoke','execution_authorized'=>false);
  }
}
