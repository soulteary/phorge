<?php

/** Pure persisted-row decoding. A decoded row never grants native EDIT. */
final class PhorgeTaskContentGrantValue extends Phobject {
  public static function active(array $row,$target,$subjectKind,$subjectID,$now) {
    $keys=array_keys($row);sort($keys,SORT_STRING);
    if ($keys!==array('grantID','operation','revokedAt','subjectID','subjectKind','targetPHID','validFrom','validUntil','version') ||
        !is_int($now)||$now<0 || !is_string($target)||!preg_match('/\APHID-TASK-[a-zA-Z0-9]{20}\z/D',$target) ||
        $row['targetPHID']!==$target || $row['operation']!==PhorgeTaskContentGrantRequest::OPERATION ||
        !in_array($subjectKind,array('user','business-team'),true) || $row['subjectKind']!==$subjectKind || $row['subjectID']!==$subjectID) {
      throw new RuntimeException('task_grant_row_binding_refused');
    }
    PhorgeTaskContentGrantRequest::identifier($row['grantID']);
    if ($subjectKind==='user'?!PhorgeGovernanceOrganizationRequest::userID($subjectID):!PhorgeGovernanceOrganizationRequest::identifier($subjectID,64)) { throw new RuntimeException('task_grant_subject_refused'); }
    foreach (array('validFrom','validUntil','version') as $key) {
      $value=$row[$key];$text=is_int($value)?(string)$value:$value;$max=(string)PHP_INT_MAX;
      if (!is_string($text)||!preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$text)||strlen($text)>strlen($max)||(strlen($text)===strlen($max)&&strcmp($text,$max)>0)) { throw new RuntimeException('task_grant_row_period_refused'); }
      $row[$key]=(int)$text;
    }
    if ($row['version']!==1 || $row['revokedAt']!==null || $row['validFrom']>$now || $row['validUntil']<=$now || $row['validUntil']<=$row['validFrom']) { throw new RuntimeException('task_grant_row_inactive'); }
    return array('active_at_observation'=>true,'authority'=>false,'can_execute'=>false);
  }
}
