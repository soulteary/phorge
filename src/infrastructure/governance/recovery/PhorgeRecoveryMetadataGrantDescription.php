<?php

/** Untrusted SHOW GRANTS syntax only. This is never a metadata visibility lease. */
final class PhorgeRecoveryMetadataGrantDescription extends Phobject {
  public static function describe(array $grants,$currentRole,$proxyUser) {
    if($currentRole!=='NONE'||$proxyUser!==null||!$grants||count($grants)>32){
      throw new RuntimeException('recovery_metadata_grant_profile_unknown');}
    $claims=array('SELECT'=>false,'TRIGGER'=>false,'PROCESS'=>false);$seen=array();
    foreach($grants as$grant){
      if(!is_string($grant)||strlen($grant)>8192||!phutil_is_utf8($grant)||
        preg_match('/[\x00-\x1f\x7f]/',$grant)||isset($seen[$grant])){
        throw new RuntimeException('recovery_metadata_grant_profile_unknown');}
      $seen[$grant]=true;
      // A proxy permission is not proof. The live collector must separately
      // obtain actual @@proxy_user NULL; this describes only supplied syntax.
      if(preg_match('/\AGRANT PROXY ON .+ TO .+\z/D',$grant)){continue;}
      if(!preg_match('/\AGRANT ([A-Z0-9_ ,]+) ON (\*\.\*|`(?:[^`]|``)+`\.\*) TO .+\z/D',$grant,$match)){
        // Includes role grants, partial REVOKE rows and unknown statement forms.
        throw new RuntimeException('recovery_metadata_grant_profile_unknown');}
      $privileges=preg_split('/, */',$match[1]);
      if(count($privileges)!==count(array_unique($privileges))){
        throw new RuntimeException('recovery_metadata_grant_profile_unknown');}
      foreach($privileges as$privilege){
        if(!preg_match('/\A[A-Z][A-Z0-9_]*(?: [A-Z][A-Z0-9_]*)*\z/D',$privilege)){
          throw new RuntimeException('recovery_metadata_grant_profile_unknown');}
      }
      // Scoped TRIGGER does not prove visibility of incoming constraints in
      // other schemas. The first isolated control-identity profile is global.
      if($match[2]==='*.*'){
        foreach($claims as$privilege=>$unused){
          if(in_array($privilege,$privileges,true)||in_array('ALL PRIVILEGES',$privileges,true)){
            $claims[$privilege]=true;
          }
        }
      }
    }
    $canonical=array_keys($seen);sort($canonical,SORT_STRING);
    return array('schema_version'=>1,'scope'=>'untrusted-native-grant-description',
      'required_global_privileges_claimed'=>!in_array(false,$claims,true),
      'source_digest'=>hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR)),
      'actual_live_privileges_proved'=>false,'metadata_absence_proved'=>false,
      'restoration_authorized'=>false);
  }
}
