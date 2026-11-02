<?php

/** Immutable return target, not a file authorization decision. */
final class PhorgeGovernanceMediaTarget extends Phobject {
  private $fields;
  private function __construct(array $fields) { $this->fields=$fields; }
  private function __clone() {}
  public function __serialize() { throw new RuntimeException('media_target_serialize_refused'); }
  public function __unserialize(array $value) { throw new RuntimeException('media_target_serialize_refused'); }
  public function __debugInfo() { return array('scope'=>'prepared-media-target'); }

  public static function fromPath($path, $instance=null) {
    if (!is_string($path)||strlen($path)>2048||!phutil_is_utf8($path)||
        ($instance!==null&&(!is_string($instance)||!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D',$instance)))) {
      throw new InvalidArgumentException('media_exchange_target_refused');
    }
    $prefix='/file/';$parts=explode('/',substr($path,strlen($prefix)));
    if (substr($path,0,strlen($prefix))!==$prefix) { throw new InvalidArgumentException('media_exchange_target_refused'); }
    $kind=array_shift($parts);$found_instance=null;
    if(isset($parts[0])&&substr($parts[0],0,1)==='@'){$found_instance=substr(array_shift($parts),1);}
    if($found_instance!==$instance){throw new InvalidArgumentException('media_exchange_target_refused');}
    $transform=null;$name=null;
    if($kind==='data'||$kind==='download'){
      if(count($parts)!==3){throw new InvalidArgumentException('media_exchange_target_refused');}
      list($key,$phid,$encoded_name)=$parts;$name=rawurldecode($encoded_name);
      if($name===''||strlen($name)>512||!phutil_is_utf8($name)||preg_match('/[\x00-\x1f\x7f\/\\\\]/',$name)||
          $name==='.'||$name==='..'||strpos($name,'%')!==false||rawurlencode($name)!==$encoded_name){throw new InvalidArgumentException('media_exchange_target_refused');}
    }elseif($kind==='xform'){
      if(count($parts)!==4||$parts[3]!==''){throw new InvalidArgumentException('media_exchange_target_refused');}
      list($transform,$phid,$key)=$parts;
      if(!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D',$transform)){throw new InvalidArgumentException('media_exchange_target_refused');}
    }else{throw new InvalidArgumentException('media_exchange_target_refused');}
    if(!preg_match('/\APHID-FILE-[a-zA-Z0-9]{20}\z/D',$phid)||!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D',$key)){
      throw new InvalidArgumentException('media_exchange_target_refused');}
    return new self(array('audience'=>'phorge-files','purpose'=>'private-media','kind'=>$kind,'instance'=>$instance,
      'file_phid'=>$phid,'key'=>$key,'transform'=>$transform,'filename'=>$name,'return_path'=>$path));
  }
  public function getReturnPath(){return $this->fields['return_path'];}
  public function getCanonicalJSON(){return phutil_json_encode($this->fields);}
  public function getDigest(){return hash('sha256',$this->getCanonicalJSON());}
}
