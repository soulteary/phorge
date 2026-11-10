<?php

/** Native two-admin preparation only. No route, EDIT grant or editor bypass. */
final class PhorgeProjectContentGrantService extends Phobject {
  public static function assertEnabled() {
    if (PhabricatorEnv::getEnvConfigIfExists('governance.project-content-grants.enabled',false)!==true ||
        !PhorgeProjectExecutionCatalog::enabled()) { throw new RuntimeException('content_grant_disabled'); }
  }
  public static function call(PhorgeGovernanceNativeActorContext $context,$action,array $payload) {
    self::assertEnabled();
    if ($action==='submit') {
      $r=PhorgeProjectContentGrantRequest::submit($payload);$target=$r['target_phid'];
      $plan=PhorgeProjectContentGrantPlan::newForSubmission($r);
    } else if ($action==='approve' && array_keys($payload)===array('approval_id')) {
      $plan=PhorgeProjectContentGrantStore::planning($payload['approval_id']);$target=$plan->getTargetPHID();
    } else { throw new InvalidArgumentException('content_grant_action_refused'); }
    $row=queryfx_one((new PhabricatorProject())->establishConnection('w'),'SELECT id,phid,editPolicy FROM %R WHERE phid=%s',new PhabricatorProject(),$target);
    if (!$row || $row['phid']!==$target) { throw new RuntimeException('content_grant_target_unavailable'); }
    $object=(new PhabricatorProject())->loadFromArray($row);
    $scope=PhorgeProjectNativeExecutionScope::open($context,$object,$row['editPolicy'],'history',null,null,$plan);
    try {
      $store=PhorgeProjectContentGrantStore::forScope($scope);
      $result=$action==='submit'?$store->submit($r):$store->approve($payload['approval_id']);
      self::assertEnabled();$scope->commitService();return $result;
    } finally { $scope->close(); }
  }
  /** Explicit technical gate until ordinary actor + typed editor closure exists. */
  public static function executionStatus() {
    return array('operation'=>'project.description','can_execute'=>false,
      'reason'=>'ordinary-native-source-and-typed-editor-seam-required');
  }
}
