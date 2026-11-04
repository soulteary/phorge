<?php

/** Explicit first native family. Approval never supplies missing capabilities. */
final class PhorgeProjectPolicyApprovalService extends Phobject {
  public static function call(PhorgeGovernanceNativeActorContext $context,$action,array $payload) {
    if (!PhorgeProjectExecutionCatalog::enabled()) {
      throw new RuntimeException('project_execution_service_unavailable');
    }
    if (!in_array($action,array('submit','approve','history'),true)) {
      throw PhorgeProjectPolicyRefusalException::newForReason('request-input');
    }
    if ($action==='submit') {
      $intent=PhorgeProjectPolicyFailure::requestIntent($payload);
      $target=$payload['target_phid']; $policy=$intent->getPolicy();
    } else {
      if (array_keys($payload)!==array('approval_id')) {
        throw PhorgeProjectPolicyRefusalException::newForReason('request-input');
      }
      $intent=PhorgeProjectPolicyApprovalStore::intent($payload['approval_id']);
      $target=$intent['targetPHID']; $policy=$intent['newPolicy'];$intent=$intent['intent'];
    }
    if (!is_string($target) || !preg_match('/\APHID-PROJ-[a-zA-Z0-9]{20}\z/',$target)) {
      throw PhorgeProjectPolicyRefusalException::newForReason('request-input');
    }
    $row=queryfx_one((new PhabricatorProject())->establishConnection('w'),
      'SELECT id,phid FROM %R WHERE BINARY phid=BINARY %s',new PhabricatorProject(),$target);
    $object=$row?(new PhabricatorProject())->loadFromArray($row):null;
    if (!$object || $object->getPHID()!==$target) { throw PhorgeProjectPolicyRefusalException::newForReason('target-unavailable'); }
    $scope=PhorgeProjectNativeExecutionScope::open($context,$object,$policy,
      $action==='submit'?'propose':$action,$action==='submit'?null:$payload['approval_id'],$intent);
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
