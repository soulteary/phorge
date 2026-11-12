<?php

/** Actual native account/team rows under the live Project writer. Not a permit. */
final class PhorgeProjectContentSource extends Phobject {
  private static function connection(PhorgeProjectNativeExecutionScope $scope) {
    PhorgeProjectContentGrantService::assertEnabled();
    if (get_class($scope)!==PhorgeProjectNativeExecutionScope::class || !in_array($scope->getMode(),array('history','content-description'),true)) {
      throw new RuntimeException('content_grant_native_scope_required');
    }
    $scope->assertOpen();
    return (new ReflectionProperty(PhorgeProjectNativeExecutionScope::class,'connection'))->getValue($scope);
  }
  private static function team(PhorgeProjectNativeExecutionScope $scope,$id,$active) {
    if (!PhorgeGovernanceOrganizationRequest::identifier($id,64) ||
        PhabricatorEnv::getEnvConfig('governance.organization.enabled')!==true) {
      throw new RuntimeException('content_grant_team_source_unavailable');
    }
    $conn=self::connection($scope);$unit=new PhorgeOrganizationUnit();$member=new PhorgeOrganizationMember();
    $schema=PhorgeProjectNativeExecutionScope::sourceDatabase($conn,$unit);
    if (PhorgeProjectNativeExecutionScope::sourceDatabase($conn,$member)!==$schema) {
      throw new RuntimeException('content_grant_team_primary_refused');
    }
    foreach (array('organization_guard'=>'id','organization_unit'=>'id','organization_member'=>'id') as $table=>$key) {
      queryfx_one($conn,'SELECT %C FROM %T.%T WHERE 1=0 LOCK IN SHARE MODE',$key,$schema,$table);
      $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$schema,$table);
      if (!$engine || $engine['ENGINE']!=='InnoDB') { throw new RuntimeException('content_grant_team_engine_refused'); }
    }
    $guard=queryfx_one($conn,'SELECT id,version FROM %T.%T WHERE id=1 LOCK IN SHARE MODE',$schema,'organization_guard');
    if (!$guard || !preg_match('/\A[1-9][0-9]{0,18}\z/D',(string)$guard['version'])) { throw new RuntimeException('content_grant_team_generation_refused'); }
    $row=queryfx_one($conn,'SELECT unitID,kind,state,version,visibilityModel,visibilityVersion FROM %R WHERE unitID=%s LOCK IN SHARE MODE',$unit,$id);
    if (!$row || $row['unitID']!==$id || $row['kind']!=='team' ||
        !in_array($row['state'],$active?array('active'):array('active','archived'),true) ||
        !preg_match('/\A[1-9][0-9]{0,18}\z/D',(string)$row['version']) ||
        $row['visibilityModel']!=='restricted-admin-v1' || (string)$row['visibilityVersion']!=='1') {
      throw new RuntimeException('content_grant_team_source_refused');
    }
    return array($conn,$row);
  }
  public static function subject(PhorgeProjectNativeExecutionScope $scope,$kind,$id,$active) {
    if (!is_bool($active)) { throw new InvalidArgumentException('content_grant_source_mode_refused'); }
    $conn=self::connection($scope);
    if ($kind==='user') {
      $planned=(new ReflectionProperty(PhorgeProjectNativeExecutionScope::class,'plannedIdentities'))->getValue($scope);
      if (!in_array($id,$planned,true)) { throw new RuntimeException('content_grant_identity_plan_required'); }
      $row=PhorgeGovernanceNativeIdentitySource::lockIdentityPHID($id,$conn);
      if ($active && !$row['eligible']) { throw new RuntimeException('content_grant_subject_ineligible'); }
      return array('kind'=>'user','id'=>$id,'digest'=>PhorgeProjectExecutionValue::digest($row),'authority'=>false);
    }
    if ($kind!=='business-team') { throw new RuntimeException('content_grant_subject_refused'); }
    list($conn,$row)=self::team($scope,$id,$active);
    return array('kind'=>'business-team','id'=>$id,'digest'=>PhorgeProjectExecutionValue::digest($row),'authority'=>false);
  }
  /** Row decoding only; a supplied array is never a native source receipt. */
  public static function decodeMembership($row,$team,$user,$now) {
    if (!is_int($now)||$now<0) { throw new InvalidArgumentException('content_grant_time_refused'); }
    if ($row===null) { return false; }
    $keys=is_array($row)?array_keys($row):array();sort($keys,SORT_STRING);
    if ($keys!==array('state','unitID','userPHID','validFrom','validUntil','version') ||
        $row['unitID']!==$team || $row['userPHID']!==$user ||
        !in_array($row['state'],array('active','ended','suspended'),true) ||
        (!is_int($row['version'])&&!is_string($row['version'])) || !preg_match('/\A[1-9][0-9]{0,18}\z/D',(string)$row['version'])) {
      throw new RuntimeException('content_grant_member_source_refused');
    }
    $times=array();
    foreach (array('validFrom','validUntil') as $key) {
      $value=$row[$key];
      if ($value===null && $key==='validUntil') { $times[$key]=null;continue; }
      $text=is_int($value)?(string)$value:$value;$max=(string)PHP_INT_MAX;
      if (!is_string($text)||!preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$text)||strlen($text)>strlen($max)||(strlen($text)===strlen($max)&&strcmp($text,$max)>0)) {
        throw new RuntimeException('content_grant_member_period_refused');
      }
      $times[$key]=(int)$text;
    }
    if ($times['validUntil']!==null && $times['validUntil']<=$times['validFrom']) { throw new RuntimeException('content_grant_member_period_refused'); }
    return $row['state']==='active' && $times['validFrom']<=$now && ($times['validUntil']===null||$times['validUntil']>$now);
  }
  /** Direct membership only: never traverses parent/team/department/Project edges. */
  public static function directMembership(PhorgeProjectNativeExecutionScope $scope,$team,$user) {
    $conn=self::connection($scope);
    $planned=(new ReflectionProperty(PhorgeProjectNativeExecutionScope::class,'plannedIdentities'))->getValue($scope);
    if (!in_array($user,$planned,true)) { throw new RuntimeException('content_grant_identity_plan_required'); }
    $identity=PhorgeGovernanceNativeIdentitySource::lockIdentityPHID($user,$conn);
    list($conn,$unit)=self::team($scope,$team,true);
    $now=(int)queryfx_one($conn,'SELECT UNIX_TIMESTAMP() n')['n'];
    $row=queryfx_one($conn,'SELECT unitID,userPHID,state,validFrom,validUntil,version FROM %R WHERE unitID=%s AND userPHID=%s LOCK IN SHARE MODE',new PhorgeOrganizationMember(),$team,$user);
    $eligible=$identity['eligible'] && self::decodeMembership($row,$team,$user,$now);
    return array('observed_direct_member'=>(bool)$eligible,
      'source_digest'=>PhorgeProjectExecutionValue::digest(array($unit,$row,$identity)),
      'authority'=>false,'can_execute'=>false);
  }
}
