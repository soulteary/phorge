<?php
/** Test-only actual Calendar DAO and physical-policy observations. Never a grant. */
function governance_calendar_revocation_observe($fixture,PhabricatorCalendarEventInvitee $invitee,array $mails){
  if(getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'||PHP_INT_SIZE!==8){throw new RuntimeException('calendar_measurement_isolated_only');}
  $revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
  if(!is_string($revision)||preg_match('/\A[a-f0-9]{40}\z/D',$revision)!==1){throw new RuntimeException('calendar_measurement_revision_required');}
  $fixture->assertOwned();
  $boot=trim(file_get_contents('/proc/sys/kernel/random/boot_id'));
  if(preg_match('/\A[a-f0-9-]{36}\z/D',$boot)!==1){throw new RuntimeException('calendar_measurement_clock_required');}
  $connection=$invitee->establishConnection('w');
  if(get_class($connection)!==AphrontMySQLiDatabaseConnection::class||$connection->isInsideTransaction()||$connection->isReadLocking()||$connection->isWriteLocking()||count($mails)!==2||!PhorgePrivateMailPolicy::enabled()){
    throw new RuntimeException('calendar_measurement_scope_refused');}
  if((new ReflectionProperty(LiskDAO::class,'forcedConnection'))->getValue($invitee)!==null){throw new RuntimeException('calendar_measurement_forced_writer_refused');}
  // MySQL rejects this statement inside a raw START TRANSACTION even when
  // autocommit remains1 and native client bookkeeping is zero. Never rollback caller state.
  queryfx($connection,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
  $physical=queryfx_one($connection,'SELECT @@autocommit a');if(!is_array($physical)||!in_array($physical['a']??null,array(1,'1'),true)){throw new RuntimeException('calendar_measurement_autocommit_required');}
  $namespace=$fixture->getNamespace();
  if(preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)!==1||$invitee->getAphrontRefDatabaseName()!==$namespace.'_calendar'){
    throw new RuntimeException('calendar_measurement_fixture_route_refused');}
  $id=$invitee->getID();
  if(!is_int($id)&&!is_string($id)||preg_match('/\A[1-9][0-9]*\z/D',(string)$id)!==1){throw new RuntimeException('calendar_measurement_identity_required');}
  $load=static function()use($id,$invitee){$row=(new PhabricatorCalendarEventInvitee())->load($id);
    if(!$row||$row->getEventPHID()!==$invitee->getEventPHID()||$row->getInviteePHID()!==$invitee->getInviteePHID()){throw new RuntimeException('calendar_measurement_source_changed');}return$row;};
  $before=$load();if(!$before->isAttending()){throw new RuntimeException('calendar_measurement_attendee_required');}
  $snapshots=array();$mail_ids=array();
  foreach($mails as$i=>$mail){
    if(get_class($mail)!==PhabricatorMetaMTAMail::class||$mail->getAphrontRefDatabaseName()!==$namespace.'_metamta'||$mail->getToPHIDs()!==array($invitee->getInviteePHID())||($i===0&&$mail->getRelatedPHID()!==$invitee->getEventPHID())){
      throw new RuntimeException('calendar_measurement_mail_binding_refused');}
    $stored=(new PhabricatorMetaMTAMail())->load($mail->getID());$s=$stored?$stored->getParameters()['gorge.delivery-snapshot']??null:null;
    if(!is_array($s)||!is_array($s['authorization']??null)||!isset($s['deliveryID'],$s['authorization']['messageHash'],$s['message'])||!PhorgePrivateMailPolicy::snapshotSafe($stored,$s)){
      throw new RuntimeException('calendar_measurement_prepared_snapshot_required');}
    $snapshots[]=$s;$mail_ids[]=$mail->getID();
  }
  if(count(array_unique($mail_ids,SORT_REGULAR))!==2||$mails[0]->getRelatedPHID()===$mails[1]->getRelatedPHID()){
    throw new RuntimeException('calendar_measurement_distinct_events_required');}
  $probe=static function($i)use($mails,$snapshots,$fixture){
    $fixture->assertOwned();$mail=(new PhabricatorMetaMTAMail())->load($mails[$i]->getID());$s=$snapshots[$i];
    $start=hrtime(true);$outcome='unknown';
    try{$result=$mail->authorizeGorgePhysicalDelivery($s['deliveryID'],$s['authorization']['messageHash'],phutil_json_encode($s['message']));
      if($result===array('allowed'=>true)){$outcome='allowed';}elseif($result===array('allowed'=>false)){$outcome='denied';}}
    catch(Throwable$error){/* Native source/transport failures remain unknown; no raw error/token exported. */}
    $finish=hrtime(true);return array('label'=>$i===0?'A':'B','start_ns'=>$start,'finish_ns'=>$finish,'outcome'=>$outcome);
  };
  $pre=array($probe(0),$probe(1));
  if($pre[0]['outcome']!=='allowed'||$pre[1]['outcome']!=='allowed'){throw new RuntimeException('calendar_measurement_positive_control_required');}
  $fixture->assertOwned();if($connection->isInsideTransaction()||$before->establishConnection('w')!==$connection){throw new RuntimeException('calendar_measurement_writer_changed');}$start=hrtime(true);
  // Native DAO save uses its normal autocommit path; never invent a transaction group.
  $before->setStatus(PhabricatorCalendarEventInvitee::STATUS_UNINVITED)->save();$returned=hrtime(true);
  if($connection->isInsideTransaction()||$before->establishConnection('w')!==$connection||$load()->getStatus()!==PhabricatorCalendarEventInvitee::STATUS_UNINVITED){throw new RuntimeException('calendar_measurement_commit_return_unproved');}
  $post=array($probe(0),$probe(1),$probe(0));
  $binding=hash('sha256',phutil_json_encode(array($namespace,$id,$invitee->getEventPHID(),$invitee->getInviteePHID(),$start,$returned,PhabricatorCalendarEventInvitee::STATUS_UNINVITED)));
  return array('schema'=>'calendar-physical-policy-operation-observation-v1','source_revision'=>$revision,'producer_sha256'=>hash_file('sha256',__FILE__),
    'boot_id_sha256'=>hash('sha256',$boot),'transition_binding_sha256'=>$binding,
    'boundary'=>'native-invitee-dao-save-no-outer-operation-call-return','operation_start_ns'=>$start,'commit_confirmed_return_ns'=>$returned,
    'persisted_uninvited_observed'=>true,'native_transaction_record_available'=>false,'attempts'=>array_merge($pre,$post),
    'physical_SMTP_proved'=>false,'measurement_acceptance'=>false,'SLA_proved'=>false,'convergence_proved'=>false,'restoration_authorized'=>false);
}
