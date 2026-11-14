<?php

/** Same-scope completion seal, never a reusable permission grant. */
final class PhorgeTaskContentGrantCommitProof extends Phobject {
  private $scope;
  private $plan;
  private $actorBinding;
  private $validBefore;
  private function __construct() {}
  private function __clone() {}
  public function __debugInfo() { return array('scope'=>'sealed-task-metadata-completion'); }
  public function __serialize() { throw new RuntimeException('task_grant_commit_proof_serialization_refused'); }
  public function __unserialize(array $data) { throw new RuntimeException('task_grant_commit_proof_serialization_refused'); }
  public static function newFromStore(PhorgeTaskContentGrantWriteScope $scope,$validBefore) {
    $frame=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,2)[1]??array();
    $plan=$scope->getMetadataPlan();
    if (($frame['class']??null)!==PhorgeTaskContentGrantStore::class ||
        !in_array($frame['function']??null,array('submit','approve'),true) ||
        $plan===null || $plan->getPurpose()!==$frame['function'] ||
        !is_int($validBefore) || $validBefore<1) {
      throw new RuntimeException('task_grant_store_completion_required');
    }
    $proof=new self();$proof->scope=WeakReference::create($scope);$proof->plan=$plan;
    $proof->actorBinding=PhorgeProjectExecutionValue::digest($scope->getCredentialBinding());
    $proof->validBefore=$validBefore;return $proof;
  }
  public function assertForScope(PhorgeTaskContentGrantWriteScope $scope) {
    if ($this->scope===null || $this->scope->get()!==$scope ||
        $scope->getMetadataPlan()!==$this->plan ||
        !hash_equals($this->actorBinding,PhorgeProjectExecutionValue::digest($scope->getCredentialBinding()))) {
      throw new RuntimeException('task_grant_commit_proof_scope_refused');
    }
  }
  public function getValidBefore() { return $this->validBefore; }
}
