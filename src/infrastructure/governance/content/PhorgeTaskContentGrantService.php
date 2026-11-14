<?php

/** Task-body grant governance only: no Task content writes or HTTP route. */
final class PhorgeTaskContentGrantService extends Phobject {
  public static function assertEnabled() {
    PhorgeTaskContentGrantConfiguration::assertEnabled();
  }
  public static function call(PhorgeGovernanceNativeActorContext $context,$action,array $payload) {
    self::assertEnabled();
    if ($action==='submit') {
      $request=PhorgeTaskContentGrantRequest::submit($payload);
      $plan=PhorgeTaskContentGrantPlan::newForSubmission($request);
    } else if ($action==='approve' && array_keys($payload)===array('approval_id')) {
      $plan=PhorgeTaskContentGrantStore::planning($payload['approval_id']);
    } else { throw new InvalidArgumentException('task_grant_action_refused'); }
    $target=$plan->getTargetPHID();$table=new ManiphestTask();
    $row=queryfx_one($table->establishConnection('w'),'SELECT id,phid FROM %R WHERE BINARY phid=BINARY %s',$table,$target);
    if (!$row || $row['phid']!==$target) { throw new RuntimeException('task_grant_target_unavailable'); }
    $object=(new ManiphestTask())->loadFromArray($row);
    $scope=PhorgeTaskContentGrantWriteScope::open($context,$object,$plan);
    try {
      $store=PhorgeTaskContentGrantStore::forScope($scope);
      list($result,$proof)=$action==='submit'?$store->submit($request):$store->approve($payload['approval_id']);
      self::assertEnabled();$scope->commitMetadata($proof);return $result;
    } finally { $scope->close(); }
  }
  public static function executionStatus() {
    return array('operation'=>PhorgeTaskContentGrantRequest::OPERATION,
      'metadata_source_implemented'=>true,'runtime_qualified'=>false,
      'can_execute'=>false,'HTTP_implemented'=>false,
      'reason'=>'task-editor-execution-not-implemented');
  }
}
