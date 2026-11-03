<?php

/** Pure storage decoder; not credential validation or a recovery decision. */
final class PhorgeRecoveryNativeWebFacts extends Phobject {
  const MAX_ROWS = 64;
  const MAX_BYTES = 65536;
  const MAX_PROPERTIES = 4096;

  private static function number($value, $positive = false) {
    if ((!is_int($value) && !is_string($value)) ||
        !preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string)$value) ||
        (float)$value > 4294967295 || ($positive && (string)$value === '0')) {
      throw new RuntimeException('recovery_web_storage_shape_unknown');
    }
    return (int)$value;
  }
  private static function bit($value) {
    if (!in_array($value,array(0,1,'0','1'),true)) { throw new RuntimeException('recovery_web_storage_shape_unknown'); }
    return (string)$value === '1';
  }
  private static function text($value,$maximum,$empty=false) {
    if (!is_string($value) || (!$empty && $value==='') || strlen($value)>$maximum) {
      throw new RuntimeException('recovery_web_storage_budget_unknown');
    }
    return $value;
  }
  private static function keys(array $row,array $expected) {
    $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
    if($actual!==$expected){throw new RuntimeException('recovery_web_storage_shape_unknown');}
  }

  public static function describe(array $sessions,array $tokens,$subject,$now) {
    if(!is_string($subject)||!preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D',$subject)||
        !is_int($now)||$now<0||!array_is_list($sessions)||!array_is_list($tokens)||
        count($sessions)>self::MAX_ROWS||count($tokens)>self::MAX_ROWS){
      throw new RuntimeException('recovery_web_capture_budget_unknown');}
    $result_sessions=array();$result_tokens=array();$ids=array();$bindings=array();$bytes=0;
    $unknown=array('external-identity-sessions-uncovered'=>true,
      'email-and-unassociated-temporary-resources-uncovered'=>true,
      'unfenced-transient-ABA-and-future-producers'=>true);
    foreach($sessions as$row){
      if(!is_array($row)){throw new RuntimeException('recovery_web_storage_shape_unknown');}
      self::keys($row,array('id','phid','userPHID','type','sessionKey','sessionStart',
        'sessionExpires','highSecurityUntil','isPartial','signedLegalpadDocuments'));
      $id=self::number($row['id'],true);
      if(isset($ids['s'.$id])||$row['userPHID']!==$subject){throw new RuntimeException('recovery_web_foreign_or_duplicate_owner');}
      $ids['s'.$id]=true;
      $phid=self::text($row['phid'],64);
      if(!preg_match('/\APHID-SSSN-[a-zA-Z0-9]{20}\z/D',$phid)){throw new RuntimeException('recovery_web_storage_shape_unknown');}
      $type=self::text($row['type'],32);$key=self::text($row['sessionKey'],64);
      $identity='phid:'.hash('sha256',$phid);$secret='key:'.hash('sha256',$key);
      if(isset($ids[$identity])||isset($ids[$secret])){throw new RuntimeException('recovery_web_foreign_or_duplicate_owner');}
      $ids[$identity]=true;$ids[$secret]=true;
      $start=self::number($row['sessionStart']);$expires=self::number($row['sessionExpires']);
      $high=$row['highSecurityUntil']===null?null:self::number($row['highSecurityUntil']);
      $partial=self::bit($row['isPartial']);$signed=self::bit($row['signedLegalpadDocuments']);
      $binding=hash('sha256',serialize(array($id,$phid,$subject,$type,$key,$start,$expires,$high,$partial,$signed)));
      $bindings['session:'.$id]=$binding;$bytes+=strlen($phid)+strlen($subject)+strlen($type)+strlen($key)+128;
      if($type!=='web'){$unknown['legacy-or-unknown-session-kind']=true;}
      $result_sessions[]=array('row_binding_sha256'=>$binding,'kind'=>$type==='web'?'web':'unsupported-session-kind',
        'observation'=>$start>$now?'future-start':($expires<=$now?'expired':($partial?'partial-stored-session':'unexpired-stored-session')),
        'is_partial'=>$partial,'starts_at'=>$start,'expires_at'=>$expires,'high_security_until'=>$high,
        'credential_permission'=>false);
    }
    $known=array('login:onetime','login:password','mfa.sync');
    foreach($tokens as$row){
      if(!is_array($row)){throw new RuntimeException('recovery_web_storage_shape_unknown');}
      self::keys($row,array('id','tokenResource','tokenType','tokenExpires','tokenCode','userPHID','properties'));
      $id=self::number($row['id'],true);
      if(isset($ids['t'.$id])){throw new RuntimeException('recovery_web_foreign_or_duplicate_owner');}$ids['t'.$id]=true;
      $resource=self::text($row['tokenResource'],64);$type=self::text($row['tokenType'],64);
      $code=self::text($row['tokenCode'],64);$properties=self::text($row['properties'],self::MAX_PROPERTIES,true);
      $owner=$row['userPHID']===null?null:self::text($row['userPHID'],64);
      if($resource!==$subject&&$owner!==$subject){throw new RuntimeException('recovery_temporary_unassociated_row');}
      $expires=self::number($row['tokenExpires']);$association='direct-subject';
      if($owner!==null&&$owner!==$subject){$association='foreign-owner-conflict';}
      else if($resource!==$subject){$association='ambiguous-resource';}
      if($association!=='direct-subject'){$unknown[$association]=true;}
      $kind=in_array($type,$known,true)?$type:'unknown-token-kind';
      if($kind==='unknown-token-kind'){$unknown['unknown-token-kind']=true;}
      $binding=hash('sha256',serialize(array($id,$resource,$type,$expires,$code,$owner,$properties)));
      $bindings['temporary:'.$id]=$binding;
      $bytes+=strlen($resource)+strlen($type)+strlen($code)+strlen($owner??'')+strlen($properties)+128;
      $result_tokens[]=array('row_binding_sha256'=>$binding,'kind'=>$kind,'association'=>$association,
        'expires_at'=>$expires,'observation'=>$expires<=$now?'expired':'unexpired-stored-token',
        'credential_permission'=>false);
    }
    if($bytes>self::MAX_BYTES){throw new RuntimeException('recovery_web_capture_budget_unknown');}
    ksort($bindings,SORT_STRING);ksort($unknown,SORT_STRING);
    return array('schema_version'=>1,'profile'=>'native-direct-web-and-temporary-partial',
      'observed_at'=>$now,'sessions'=>$result_sessions,'temporary_tokens'=>$result_tokens,
      'storage_set_digest'=>hash('sha256',serialize($bindings)),
      'unknown_reasons'=>array_keys($unknown),'consistency'=>'single-sample-no-execution-attestation',
      'web_family_complete'=>false,'all_sources_complete'=>false,'can_execute'=>false);
  }
}
