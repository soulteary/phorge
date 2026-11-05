<?php

/** Committed audit observation. Metadata never grants access to its object. */
final class PhorgeGovernanceNoticeSource extends Phobject {
  public static function load(array $metadata) {
    $event=PhorgeGovernanceNoticeEventValue::fromMetadata($metadata);
    if($event['metadata']!==$metadata){throw new RuntimeException('governance_notice_source_shape_refused');}
    $kind=$metadata['source_kind'];$target=null;
    if($kind==='project-approval'){
      $dao=new PhabricatorProject();$conn=$dao->establishConnection('w');
      $table='governance_executionaudit';$id='id';
      $columns='id,operationID,actorPHID,action,createdAt,targetPHID';
    }elseif($kind==='native-admin-grant'){
      $dao=new PhabricatorUser();$conn=$dao->establishConnection('w');
      $table=(new PhabricatorUserTransaction())->getTableName();$id='id';
      $columns='id,phid,objectPHID,authorPHID,transactionType,dateCreated,'.
        'CASE WHEN OCTET_LENGTH(oldValue)<=5 THEN oldValue ELSE NULL END oldValue,'.
        'CASE WHEN OCTET_LENGTH(newValue)<=5 THEN newValue ELSE NULL END newValue';
    }else{
      $dao=new PhabricatorUser();$conn=$dao->establishConnection('w');
      $table=$kind==='organization'?'organization_audit':'recovery_stop_audit';
      $id=$kind==='organization'?'id':'nativeTransactionID';
      $columns=$kind==='organization'?'id,operationID,actorPHID,action,createdAt':
        'nativeTransactionID,operationID,actorPHID,createdAt';
    }
    if(PhabricatorEnv::getEnvConfig('cluster.databases')!==array()||PhabricatorEnv::isReadOnly()!==false){
      throw new RuntimeException('governance_notice_primary_required');}
    $meta_reference=new PhabricatorMetaMTAGorgeOutbox();
    $meta_uuid=PhorgeGovernanceNoticeOutbox::primary($meta_reference->establishConnection('w'),$meta_reference);
    if(PhorgeGovernanceNoticeOutbox::primary($conn,$dao)!==$meta_uuid||
      PhorgeGovernanceNoticeOutbox::primary((new PhabricatorUser())->establishConnection('w'),new PhabricatorUser())!==$meta_uuid){
      throw new RuntimeException('governance_notice_same_primary_required');}
    // Only fixed columns and tables above. Never fetch audit snapshots/reasons.
    $row=queryfx_one($conn,'SELECT %Q FROM %T WHERE %C=%s LIMIT 1 LOCK IN SHARE MODE',
      qsprintf($conn,$columns),$table,$id,$metadata['source_id']);
    if(!$row){throw new RuntimeException('governance_notice_source_missing');}
    if($kind==='native-admin-grant'){
      $actual=self::nativeAdminGrantAudit($row);
    }else{
    $actual=array('source_kind'=>$kind,'source_id'=>(string)$row[$id],
      'operation_id'=>$row['operationID'],'actor_phid'=>$row['actorPHID'],
      'action'=>$kind==='recovery-stop'?'classified-stopped':$row['action'],
      'created_at'=>(int)$row['createdAt']);
    }
    if(PhorgeGovernanceNoticeEventValue::fromAudit($actual)!==$event){
      throw new RuntimeException('governance_notice_source_changed');}
    if($kind==='project-approval'){$target=$row['targetPHID'];}
    $source=array('event'=>$event,'target'=>$target);
    if($kind==='native-admin-grant'){$source['native_subject']=$row['objectPHID'];}
    return$source;
  }
  /** Exact persisted native USER event; never infer an actor by email/name. */
  public static function nativeAdminGrantAudit(array $row){
    $keys=array_keys($row);sort($keys);
    if($keys!==array('authorPHID','dateCreated','id','newValue','objectPHID','oldValue','phid','transactionType')||
      !is_string($row['phid'])||!preg_match('/\APHID-XACT-USER-[a-zA-Z0-9]{15}\z/D',$row['phid'])||
      $row['transactionType']!==PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE||
      $row['oldValue']!=='false'||$row['newValue']!=='true'){
      throw new RuntimeException('notice_native_admin_source_refused');}
    foreach(array('authorPHID','objectPHID')as$key){
      if(!is_string($row[$key])||!preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D',$row[$key])){
        throw new RuntimeException('notice_native_admin_source_refused');}}
    if((!is_int($row['id'])&&!is_string($row['id']))||$row['authorPHID']===$row['objectPHID']||
      (!is_int($row['dateCreated'])&&!is_string($row['dateCreated']))||
      !preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$row['dateCreated'])||
      (string)(int)$row['dateCreated']!==(string)$row['dateCreated']){
      throw new RuntimeException('notice_native_admin_source_refused');}
    $audit=array('source_kind'=>'native-admin-grant','source_id'=>(string)$row['id'],
      'operation_id'=>'native-admin-grant:'.$row['phid'].':'.$row['objectPHID'],
      'actor_phid'=>$row['authorPHID'],'action'=>'admin.granted','created_at'=>(int)$row['dateCreated']);
    PhorgeGovernanceNoticeEventValue::fromAudit($audit);return$audit;
  }
  public static function canReceive(PhabricatorUser $user,array $source){
    if(!$user->getPHID()||!$user->getIsAdmin()||$user->getIsDisabled()||!$user->getIsApproved()||
      $user->getIsSystemAgent()||$user->getIsMailingList()||!$user->canEstablishWebSessions()){return false;}
    if(($source['event']['metadata']['source_kind']??null)==='native-admin-grant'){
      if(!is_string($source['native_subject']??null)||!preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D',$source['native_subject'])){return false;}
      try{$subject=(new PhabricatorPeopleQuery())->setViewer($user)->withPHIDs(array($source['native_subject']))->executeOne();}
      catch(PhabricatorPolicyException$error){return false;}
      if(!$subject||$subject->getPHID()!==$source['native_subject']){return false;}
    }
    if($source['target']!==null){
      try{$object=(new PhabricatorProjectQuery())->setViewer($user)->withPHIDs(array($source['target']))->executeOne();}
      catch(PhabricatorPolicyException $error){return false;}
      if(!$object||$object->getPHID()!==$source['target']){return false;}
    }
    return true;
  }
  public static function path(array $source){
    return $source['target']!==null?'/object/'.$source['target'].'/':
      ($source['event']['metadata']['source_kind']==='organization'?'/people/organization/history/':'/people/');
  }
}
