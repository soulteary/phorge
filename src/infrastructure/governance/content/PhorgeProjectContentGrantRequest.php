<?php

/** Fixed Project-description grant values. No caller actor or capability flags. */
final class PhorgeProjectContentGrantRequest extends Phobject {
  const OPERATION = 'project.description';
  public static function submit(array $p) {
    $keys=array_keys($p);sort($keys,SORT_STRING);
    if ($keys!==array('action','grant_id','reason','subject_id','subject_kind','target_phid','valid_for_seconds') ||
        !in_array($p['action'],array('grant','revoke'),true) ||
        !is_string($p['target_phid']) || !preg_match('/\APHID-PROJ-[a-zA-Z0-9]{20}\z/D',$p['target_phid']) ||
        !in_array($p['subject_kind'],array('user','business-team'),true) ||
        !is_string($p['reason']) || trim($p['reason'])==='' || strlen($p['reason'])>2048 ||
        !phutil_is_utf8($p['reason'])) { throw new InvalidArgumentException('content_grant_request_refused'); }
    if ($p['subject_kind']==='user') {
      if (!PhorgeGovernanceOrganizationRequest::userID($p['subject_id'])) { throw new InvalidArgumentException('content_grant_subject_refused'); }
    } else if (!PhorgeGovernanceOrganizationRequest::identifier($p['subject_id'],64)) {
      throw new InvalidArgumentException('content_grant_subject_refused');
    }
    if ($p['action']==='grant') {
      if ($p['grant_id']!==null || !is_int($p['valid_for_seconds']) ||
          $p['valid_for_seconds']<1 || $p['valid_for_seconds']>86400) {
        throw new InvalidArgumentException('content_grant_period_refused');
      }
    } else {
      self::identifier($p['grant_id']);
      if ($p['valid_for_seconds']!==null) { throw new InvalidArgumentException('content_grant_period_refused'); }
    }
    return array('action'=>$p['action'],'target_phid'=>$p['target_phid'],
      'operation'=>self::OPERATION,'subject_kind'=>$p['subject_kind'],
      'subject_id'=>$p['subject_id'],'grant_id'=>$p['grant_id'],
      'valid_for_seconds'=>$p['valid_for_seconds'],'reason'=>$p['reason']);
  }
  public static function stored(array $p) {
    if (($p['operation']??null)!==self::OPERATION) { throw new InvalidArgumentException('content_grant_operation_refused'); }
    $copy=$p;unset($copy['operation']);$checked=self::submit($copy);
    if (PhorgeProjectExecutionValue::canonical($checked)!==PhorgeProjectExecutionValue::canonical($p)) { throw new InvalidArgumentException('content_grant_binding_refused'); }
    return $checked;
  }
  public static function identifier($id) {
    if (!is_string($id)||!preg_match('/\A[a-f0-9]{64}\z/D',$id)) { throw new InvalidArgumentException('content_grant_identifier_refused'); }
    return $id;
  }
}
