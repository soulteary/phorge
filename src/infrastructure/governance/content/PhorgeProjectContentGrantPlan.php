<?php

/** Sealed bounded identity-lock plan. It supplies no actor or capability. */
final class PhorgeProjectContentGrantPlan extends Phobject {
  private $target;
  private $identities;
  private function __construct($target,array $ids) {
    if (!is_string($target)||!preg_match('/\APHID-PROJ-[a-zA-Z0-9]{20}\z/D',$target)||count($ids)>2) {
      throw new InvalidArgumentException('content_grant_plan_refused');
    }
    foreach ($ids as $id) {
      if (!PhorgeGovernanceOrganizationRequest::userID($id)) { throw new InvalidArgumentException('content_grant_plan_refused'); }
    }
    $ids=array_values(array_unique($ids));sort($ids,SORT_STRING);$this->target=$target;$this->identities=$ids;
  }
  private function __clone() {}
  public function __serialize() { throw new RuntimeException('content_grant_plan_serialization_refused'); }
  public function __unserialize(array $d) { throw new RuntimeException('content_grant_plan_serialization_refused'); }
  public static function newForSubmission(array $r) {
    $r=PhorgeProjectContentGrantRequest::stored($r);
    return new self($r['target_phid'],$r['action']==='grant'&&$r['subject_kind']==='user'?array($r['subject_id']):array());
  }
  public static function newForStoredApproval($target,array $ids) {
    $trace=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,2);$frame=$trace[1]??array();
    if (($frame['class']??null)!==PhorgeProjectContentGrantStore::class || ($frame['function']??null)!=='planning') {
      throw new RuntimeException('content_grant_stored_plan_source_required');
    }
    return new self($target,$ids);
  }
  public function assertScope($mode,$target) {
    if ($mode!=='history'||$target!==$this->target) { throw new RuntimeException('project_execution_content_participant_plan_refused'); }
  }
  public function getTargetPHID() { return $this->target; }
  public function getIdentityPHIDs() { return $this->identities; }
}
