<?php

/** Explicit first native family. Approval never supplies missing capabilities. */
final class PhorgeProjectPolicyApprovalService extends Phobject {
  public static function call(PhorgeGovernanceNativeActorContext $context,$action,array $payload) {
    if (!PhorgeProjectExecutionCatalog::enabled() ||
        !in_array($action,array('submit','approve','history'),true)) {
      throw new RuntimeException('project_execution_service_unavailable');
    }
    if ($action==='submit') {
      $keys=array_keys($payload); sort($keys,SORT_STRING);
      if ($keys!==array('new_policy','reason','target_phid','ttl_seconds')) {
        throw new InvalidArgumentException('project_execution_payload_refused');
      }
      $target=$payload['target_phid']; $policy=$payload['new_policy'];
    } else {
      if (array_keys($payload)!==array('approval_id')) {
        throw new InvalidArgumentException('project_execution_payload_refused');
      }
      $intent=PhorgeProjectPolicyApprovalStore::intent($payload['approval_id']);
      $target=$intent['targetPHID']; $policy=$intent['newPolicy'];
    }
    if (!is_string($target) || !preg_match('/\APHID-PROJ-[a-zA-Z0-9]{20}\z/',$target)) {
      throw new InvalidArgumentException('project_execution_target_refused');
    }
    $row=queryfx_one((new PhabricatorProject())->establishConnection('w'),
      'SELECT id,phid FROM %R WHERE BINARY phid=BINARY %s',new PhabricatorProject(),$target);
    $object=$row?(new PhabricatorProject())->loadFromArray($row):null;
    if (!$object || $object->getPHID()!==$target) { throw new RuntimeException('project_execution_target_missing'); }
    $scope=PhorgeProjectNativeExecutionScope::open($context,$object,$policy,
      $action==='submit'?'propose':$action,$action==='submit'?null:$payload['approval_id']);
    try {
      $store=PhorgeProjectPolicyApprovalStore::forScope($scope);
      if ($action==='submit') { $result=$store->submit($payload['reason'],$payload['ttl_seconds']); }
      else if ($action==='approve') { $result=$store->approve($payload['approval_id']); }
      else { $result=$store->history($payload['approval_id']); }
      $store->assertServiceCurrent($result['approval_id']);
      $scope->commitService();
      return $result;
    } finally { $scope->close(); }
  }
}
