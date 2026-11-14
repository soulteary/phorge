<?php

/** Sealed bounded identity-lock plan. It supplies no actor or capability. */
final class PhorgeTaskContentGrantPlan extends Phobject {
  private $target;
  private $identities;
  private $purpose;
  private $binding;
  private $approvalID;
  private $requesterPHID;
  private function __construct($target,array $ids,$purpose,$binding,$approval=null,$requester=null) {
    if (!is_string($target)||!preg_match('/\APHID-TASK-[a-zA-Z0-9]{20}\z/D',$target)||count($ids)>2) {
      throw new InvalidArgumentException('task_content_grant_plan_refused');
    }
    foreach ($ids as $id) {
      if (!PhorgeGovernanceOrganizationRequest::userID($id)) { throw new InvalidArgumentException('task_content_grant_plan_refused'); }
    }
    if (!in_array($purpose,array('submit','approve'),true) || !is_string($binding) || !preg_match('/\A[a-f0-9]{64}\z/D',$binding)) { throw new InvalidArgumentException('task_content_grant_plan_binding_refused'); }
    if ($purpose==='approve' && (!PhorgeGovernanceOrganizationRequest::userID($requester) || !in_array($requester,$ids,true))) { throw new InvalidArgumentException('task_content_grant_requester_plan_refused'); }
    $this->requesterPHID=$requester;
    $this->purpose=$purpose;$this->binding=$binding;$this->approvalID=$approval;
    $ids=array_values(array_unique($ids));sort($ids,SORT_STRING);$this->target=$target;$this->identities=$ids;
  }
  private function __clone() {}
  public function __debugInfo() { return array('scope'=>'sealed-task-grant-plan'); }
  public function __serialize() { throw new RuntimeException('task_content_grant_plan_serialization_refused'); }
  public function __unserialize(array $d) { throw new RuntimeException('task_content_grant_plan_serialization_refused'); }
  public static function newForSubmission(array $r) {
    $r=PhorgeTaskContentGrantRequest::stored($r);
    return new self($r['target_phid'],$r['action']==='grant'&&$r['subject_kind']==='user'?array($r['subject_id']):array(),'submit',PhorgeProjectExecutionValue::digest($r));
  }
  public static function newForStoredApproval($target,array $ids,$approval=null,$binding=null,$requester=null) {
    $trace=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,2);$frame=$trace[1]??array();
    if (($frame['class']??null)!==PhorgeTaskContentGrantStore::class || ($frame['function']??null)!=='planning') {
      throw new RuntimeException('task_content_grant_stored_plan_source_required');
    }
    PhorgeTaskContentGrantRequest::identifier($approval);
    return new self($target,$ids,'approve',$binding,$approval,$requester);
  }
  public function assertScope($mode,$target) {
    if ($mode!=='task-content-grant'||$target!==$this->target) { throw new RuntimeException('task_execution_content_participant_plan_refused'); }
  }
  public function assertSubmission(array $request) {
    $r=PhorgeTaskContentGrantRequest::stored($request);
    if ($this->purpose!=='submit' || $r['target_phid']!==$this->target || !hash_equals($this->binding,PhorgeProjectExecutionValue::digest($r))) { throw new RuntimeException('task_content_grant_submission_plan_refused'); }
  }
  public function assertApproval($approval,$binding) {
    if ($this->purpose!=='approve' || $this->approvalID!==$approval || !is_string($binding) || !hash_equals($this->binding,$binding)) { throw new RuntimeException('task_content_grant_approval_plan_refused'); }
  }
  public function getRequesterPHID() { return $this->requesterPHID; }
  public function getPurpose() { return $this->purpose; }
  public function getApprovalID() { return $this->approvalID; }
  public function getTargetPHID() { return $this->target; }
  public function getIdentityPHIDs() { return $this->identities; }
}
