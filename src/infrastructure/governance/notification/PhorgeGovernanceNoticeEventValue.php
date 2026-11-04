<?php

/** Metadata shape only. Neither digests nor queue fields constitute authority. */
final class PhorgeGovernanceNoticeEventValue extends Phobject {
  private static function source($kind,$id,$action,$created){
    $kinds=array('organization'=>array('unit.create','unit.update','unit.metadata','department.assign','department.clear',
      'member.add','member.update','member.suspend','member.end','appointment.grant','appointment.revoke',
      'member.add-and-appoint','appointment.revoke-and-end','approval.propose','approval.approve',
      'transfer.propose','transfer.approve','transfer.execute','handover.propose','handover.approve',
      'handover.execute','handover.project.receipt','handover.due'),
      'project-approval'=>array('submitted','approved','executed'),'recovery-stop'=>array('classified-stopped'));
    if(!is_string($kind)||!isset($kinds[$kind])||!is_string($action)||!in_array($action,$kinds[$kind],true)||
      !is_string($id)||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$id)||
      (strlen($id)===20&&strcmp($id,'18446744073709551615')>0)||!is_int($created)||$created<1){
      throw new InvalidArgumentException('governance_notice_event_source_refused');}
  }
  public static function fromAudit(array $row){
    $keys=array_keys($row);sort($keys);
    if($keys!==array('action','actor_phid','created_at','operation_id','source_id','source_kind')){
      throw new InvalidArgumentException('governance_notice_event_shape_refused');}
    self::source($row['source_kind'],$row['source_id'],$row['action'],$row['created_at']);
    if(!is_string($row['operation_id'])||!preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D',$row['operation_id'])||
      !is_string($row['actor_phid'])||!preg_match('/\APHID-USER-[A-Za-z0-9]{20}\z/D',$row['actor_phid'])){
      throw new InvalidArgumentException('governance_notice_event_value_refused');}
    // Caller operation identifiers can themselves contain sensitive text. Bind
    // their exact bytes without copying them or the actor into the new outbox.
    return self::fromMetadata(array('version'=>1,'source_kind'=>$row['source_kind'],'source_id'=>$row['source_id'],
      'operation_sha256'=>hash('sha256',$row['operation_id']),'actor_sha256'=>hash('sha256',$row['actor_phid']),
      'action'=>$row['action'],'created_at'=>$row['created_at'],'audience'=>'current-native-system-admin'));
  }
  public static function fromMetadata(array $metadata){
    $keys=array_keys($metadata);sort($keys);
    if($keys!==array('action','actor_sha256','audience','created_at','operation_sha256','source_id','source_kind','version')||
      $metadata['version']!==1||$metadata['audience']!=='current-native-system-admin'){
      throw new InvalidArgumentException('governance_notice_event_shape_refused');}
    self::source($metadata['source_kind'],$metadata['source_id'],$metadata['action'],$metadata['created_at']);
    foreach(array('operation_sha256','actor_sha256')as$key){
      if(!is_string($metadata[$key])||!preg_match('/\A[a-f0-9]{64}\z/D',$metadata[$key])){
        throw new InvalidArgumentException('governance_notice_event_digest_refused');}}
    $value=array('version'=>1,'source_kind'=>$metadata['source_kind'],'source_id'=>$metadata['source_id'],
      'operation_sha256'=>$metadata['operation_sha256'],'actor_sha256'=>$metadata['actor_sha256'],
      'action'=>$metadata['action'],'created_at'=>$metadata['created_at'],'audience'=>$metadata['audience']);
    return array('event_id'=>hash('sha256',json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'metadata'=>$value);
  }
  public static function validateTask($data){
    if(!is_array($data)||array_keys($data)!==array('version','event_id')||$data['version']!==1||
      !is_string($data['event_id'])||!preg_match('/\A[a-f0-9]{64}\z/D',$data['event_id'])){
      throw new InvalidArgumentException('governance_notice_task_refused');}
    return $data;
  }
  public static function queuePayload(array $event){
    if(array_keys($event)!==array('event_id','metadata')||!is_array($event['metadata'])||
      $event!==self::fromMetadata($event['metadata'])){throw new InvalidArgumentException('governance_notice_event_digest_refused');}
    return array('eventID'=>'governance.notice/'.$event['event_id'].'/1','task'=>array(
      'taskClass'=>'PhorgeGovernanceNoticeWorker','data'=>json_encode(array('version'=>1,'event_id'=>$event['event_id']),JSON_THROW_ON_ERROR)));
  }
}
