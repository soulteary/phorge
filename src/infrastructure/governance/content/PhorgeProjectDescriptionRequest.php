<?php

/** Detached description intent only; no actor, capability or authority fields. */
final class PhorgeProjectDescriptionRequest extends Phobject {
  private $values;
  private function __construct() {}
  private function __clone() {}
  public function __debugInfo(){return array('operation'=>'project.description','authority'=>false);}
  public function __serialize(){throw new RuntimeException('description_request_serialization_refused');}
  public function __unserialize(array $v){throw new RuntimeException('description_request_serialization_refused');}
  public static function newFromPayload(array $p) {
    $keys=array_keys($p);sort($keys,SORT_STRING);
    if($keys!==array('description','expected_description_digest','grant_id','target_phid') ||
       !is_string($p['target_phid']) || !preg_match('/\APHID-PROJ-[a-zA-Z0-9]{20}\z/D',$p['target_phid']) ||
       !is_string($p['description']) || strlen($p['description'])>65536 || !phutil_is_utf8($p['description']) ||
       strpos($p['description'],"\0")!==false || !is_string($p['expected_description_digest']) ||
       !preg_match('/\A[a-f0-9]{64}\z/D',$p['expected_description_digest'])) {
      throw new InvalidArgumentException('description_request_refused');
    }
    PhorgeProjectContentGrantRequest::identifier($p['grant_id']);$r=new self();$r->values=$p;return$r;
  }
  public function getTargetPHID(){return$this->values['target_phid'];}
  public function getGrantID(){return$this->values['grant_id'];}
  public function getDescription(){return$this->values['description'];}
  public function getExpectedDigest(){return$this->values['expected_description_digest'];}
  /** Pure row decoder only. The caller must obtain and lock actual native rows. */
  public static function requireGrantFields($row,self $request,$actor,$now) {
    $keys=is_array($row)?array_keys($row):array();sort($keys,SORT_STRING);
    if($keys!==array('grantID','operation','revokedAt','subjectID','subjectKind','targetPHID','validFrom','validUntil','version')||
       !is_int($now)||$now<0||!PhorgeGovernanceOrganizationRequest::userID($actor)||
       $row['grantID']!==$request->getGrantID()||$row['targetPHID']!==$request->getTargetPHID()||
       $row['operation']!=='project.description'||$row['revokedAt']!==null||self::unsigned($row['version'])!==1||
       self::unsigned($row['validFrom'])>$now||self::unsigned($row['validUntil'])<=$now){
      throw new RuntimeException('description_current_grant_required');
    }
    if($row['subjectKind']==='user'){
      if($row['subjectID']!==$actor){throw new RuntimeException('description_explicit_user_required');}
    }else if($row['subjectKind']!=='business-team'||!PhorgeGovernanceOrganizationRequest::identifier($row['subjectID'],64)){
      throw new RuntimeException('description_subject_kind_refused');
    }
  }
  public static function unsigned($value) {
    $s=is_int($value)?(string)$value:$value;$max=(string)PHP_INT_MAX;
    if(!is_string($s)||!preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$s)||strlen($s)>strlen($max)||
       (strlen($s)===strlen($max)&&strcmp($s,$max)>0)){throw new RuntimeException('description_period_refused');}
    return(int)$s;
  }
}
