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
    $actual=array('source_kind'=>$kind,'source_id'=>(string)$row[$id],
      'operation_id'=>$row['operationID'],'actor_phid'=>$row['actorPHID'],
      'action'=>$kind==='recovery-stop'?'classified-stopped':$row['action'],
      'created_at'=>(int)$row['createdAt']);
    if(PhorgeGovernanceNoticeEventValue::fromAudit($actual)!==$event){
      throw new RuntimeException('governance_notice_source_changed');}
    if($kind==='project-approval'){$target=$row['targetPHID'];}
    return array('event'=>$event,'target'=>$target);
  }
  public static function canReceive(PhabricatorUser $user,array $source){
    if(!$user->getPHID()||!$user->getIsAdmin()||$user->getIsDisabled()||!$user->getIsApproved()||
      $user->getIsSystemAgent()||$user->getIsMailingList()||!$user->canEstablishWebSessions()){return false;}
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
