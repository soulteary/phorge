<?php

/** Closed request shape. A digest is a freshness check, never authorization. */
final class PhorgeProjectHandoffRequest extends Phobject {
  public static function parse(array $data,$commit) {
    $keys=array_keys($data);sort($keys,SORT_STRING);
    $expected=array('operationID','projectPHID','recipientPHID');
    if($commit){$expected=array_merge($expected,array('acknowledge','expectedDigest','expiresAt'));}
    sort($expected,SORT_STRING);
    if($keys!==$expected){throw new InvalidArgumentException('handoff_fields');}
    foreach(array('projectPHID'=>'PROJ','recipientPHID'=>'USER')as$key=>$type){
      if(!is_string($data[$key])||!preg_match('/\APHID-'.$type.'-[a-zA-Z0-9]{20}\z/D',$data[$key])){
        throw new InvalidArgumentException('handoff_identity');}
    }
    if(!is_string($data['operationID'])||!preg_match('/\A[a-zA-Z0-9_-]{16,80}\z/D',$data['operationID'])){
      throw new InvalidArgumentException('handoff_operation');}
    if($commit&&($data['acknowledge']!==true||!is_int($data['expiresAt'])||$data['expiresAt']<1||
      !is_string($data['expectedDigest'])||!preg_match('/\A[a-f0-9]{64}\z/D',$data['expectedDigest']))){
      throw new InvalidArgumentException('handoff_confirmation');}
    // Construct detached scalar values rather than retaining caller references.
    $result=array();foreach($expected as$key){$result[$key]=$data[$key];}return$result;
  }
  public static function digest(array $value) {
    return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
  }
}
