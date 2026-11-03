<?php

/** Locked identity facts only. Never an authenticated actor or credential. */
final class PhorgeGovernanceNativeNoncredentialIdentitySource extends Phobject {
  private function __construct() {}
  private function __clone() {}
  public function __serialize(){throw new RuntimeException('governance_noncredential_serialization_refused');}
  public function __unserialize(array $data){throw new RuntimeException('governance_noncredential_serialization_refused');}

  private static function counter($value,$zero){
    if(!is_string($value)||!preg_match($zero?'/\A(?:0|[1-9][0-9]{0,19})\z/D':'/\A[1-9][0-9]{0,19}\z/D',$value)||
      (strlen($value)===20&&strcmp($value,'18446744073709551615')>0)){
      throw new RuntimeException('governance_noncredential_generation_refused');}
    return$value;
  }
  private static function facts(array $identity,$transaction,$epoch){
    $keys=array_keys($identity);sort($keys,SORT_STRING);
    if($keys!==array('accountEpoch','digest','eligible','isAdmin','userPHID')||
      !PhorgeGovernanceOrganizationRequest::userID($identity['userPHID'])||
      !is_bool($identity['eligible'])||!is_bool($identity['isAdmin'])||
      ($identity['accountEpoch']!==null&&(!is_string($identity['accountEpoch'])||!preg_match('/\A[a-f0-9]{64}\z/D',$identity['accountEpoch'])))||
      !is_string($identity['digest'])||!preg_match('/\A[a-f0-9]{64}\z/D',$identity['digest'])){
      throw new RuntimeException('governance_noncredential_identity_refused');}
    // AccountEpoch is a credential-era binding. Do not reverse or reinterpret
    // its hash. Observe the actual two locked native generation sources.
    return array('source_version'=>2,'userPHID'=>$identity['userPHID'],'eligible'=>$identity['eligible'],
      'isAdmin'=>$identity['isAdmin'],'digest'=>$identity['digest'],
      'nativeTransactionID'=>self::counter($transaction,true),'legacyRoleEpoch'=>self::counter($epoch,false));
  }
  public static function lockIdentityPHID($phid,AphrontDatabaseConnection $connection){
    // This proves the actual native writer/open transaction, exact identity,
    // all native account/session/transaction/epoch MDL and InnoDB prerequisites.
    // In particular, a missing table is not the absent-row case below.
    $identity=PhorgeGovernanceNativeIdentitySource::lockIdentityPHID($phid,$connection);
    $transaction=queryfx_one($connection,'SELECT id FROM %R WHERE BINARY objectPHID=BINARY %s ORDER BY id DESC LIMIT 1 LOCK IN SHARE MODE',
      new PhabricatorUserTransaction(),$phid);
    $legacy=queryfx_one($connection,'SELECT userPHID,epoch FROM %R WHERE BINARY userPHID=BINARY %s LOCK IN SHARE MODE',
      new PhorgeGovernanceIdentityEpoch(),$phid);
    if($legacy!==null&&$legacy['userPHID']!==$phid){throw new RuntimeException('governance_noncredential_identity_refused');}
    // Native credential initialization creates epoch1. The mandatory native
    // legacy-role writer starts at epoch2 on its first real role change.
    // Thus absent-row -> first issuer1 has no identity/permission meaning.
    return self::facts($identity,$transaction?(string)$transaction['id']:'0',$legacy?(string)$legacy['epoch']:'1');
  }
}
