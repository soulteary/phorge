<?php
/** Test-only real scheduler producer. Caller owns queue, environment and hold. */
/** Lisk reloads MySQL integer columns as strings; normalize without coercion. */
function governance_calendar_wire_positive_id($value) {
  if(is_int($value)&&$value>0){return$value;}
  if(is_string($value)&&preg_match('/\A[1-9][0-9]*\z/D',$value)){
    $normalized=(int)$value;
    if($normalized>0&&(string)$normalized===$value){return$normalized;}
  }
  throw new RuntimeException('calendar_wire_native_id_refused');
}
function governance_calendar_wire_binding(array $events,array $mails,array $marks,$recipient,$now,$enabled) {
  if(count($events)!==2||!is_bool($enabled)||!is_int($now)||$now<1||!is_string($recipient)||
    !preg_match('/\APHID-USER-[a-z0-9]{20}\z/D',$recipient)){
    throw new RuntimeException('calendar_wire_binding_refused');}
  $expected=array();foreach($events as$row){
    if(!is_array($row)||array_keys($row)!==array('event_phid','epoch')||
      !is_string($row['event_phid'])||!preg_match('/\APHID-CEVT-[a-z0-9]{20}\z/D',$row['event_phid'])||
      !is_int($row['epoch'])||$row['epoch']<=$now||isset($expected[$row['event_phid']])){
      throw new RuntimeException('calendar_wire_event_refused');}
    $expected[$row['event_phid']]=$row['epoch'];}
  $seen=array();foreach($marks as$row){
    $phid=$row['eventPHID']??null;
    if(!is_string($phid)||!isset($expected[$phid])||isset($seen[$phid])||
      ($row['targetPHID']??null)!==$recipient||!isset($row['utcInitialEpoch'],$row['didNotifyEpoch'])||
      (string)$row['utcInitialEpoch']!==(string)$expected[$phid]||(string)$row['didNotifyEpoch']!==(string)$now){
      throw new RuntimeException('calendar_wire_native_mark_refused');}
    $seen[$phid]=true;}
  if(count($seen)!==2||count($mails)!==($enabled?2:1)){throw new RuntimeException('calendar_wire_native_count_refused');}
  $bindings=array();$ids=array();$phids=array();foreach($mails as$mail){
    $event=$mail->getRelatedPHID();$id=governance_calendar_wire_positive_id($mail->getID());$phid=$mail->getPHID();
    if(!isset($expected[$event])||isset($bindings[$event])||isset($ids[$id])||
      !is_string($phid)||isset($phids[$phid])||!preg_match('/\APHID-MTAM-[a-z0-9]{20}\z/D',$phid)||
      $mail->getToPHIDs()!==array($recipient)||$mail->getPrivateMailPolicyValue('governance.mail-producer')!==PhabricatorCalendarNotificationEngine::class){
      throw new RuntimeException('calendar_wire_native_mail_refused');}
    $ids[$id]=true;$phids[$phid]=true;$bindings[$event]=array('mail_id'=>$id,'mail_phid'=>$phid,'event_phid'=>$event,
      'epoch'=>$expected[$event],'relay_event_id'=>'mail.prepare/'.$phid.'/1');}
  if(!$enabled&&array_key_first($bindings)!==array_key_first($expected)){
    throw new RuntimeException('calendar_wire_native_digest_target_refused');}
  return array_values($bindings);
}
function governance_calendar_wire_produce($fixture,callable $check,$recipient,$host,$now,$enabled) {
  $fixture->assertOwned();
  if(!is_bool($enabled)||PhorgePrivateMailPolicy::enabled()!==$enabled||!is_int($now)||
    (new ReflectionProperty(PhabricatorWorker::class,'runAllTasksInProcess'))->getValue()!==false){
    throw new RuntimeException('calendar_wire_native_scope_refused');}
  $events=array();$invitees=array();$browser_events=array();$event_ids=array();$native_paths=array();
  foreach(array('A'=>300,'B'=>600)as$label=>$offset){
    $event=PhabricatorCalendarEvent::initializeNewCalendarEvent($host)->setName('SYNTHETIC_PRIVATE_CALENDAR_'.$label)
      ->setDescription('SYNTHETIC_PRIVATE_CALENDAR_DESCRIPTION')
      ->setViewPolicy(PhabricatorPolicies::POLICY_NOONE)
      ->setStartDateTime(PhutilCalendarAbsoluteDateTime::newFromEpoch($now+$offset,'UTC'))
      ->setEndDateTime(PhutilCalendarAbsoluteDateTime::newFromEpoch($now+$offset+1800,'UTC'))->save();
    $invitee=PhabricatorCalendarEventInvitee::initializeNewCalendarEventInvitee($host,$event)
      ->setInviteePHID($recipient->getPHID())->setStatus(PhabricatorCalendarEventInvitee::STATUS_ATTENDING)->save();
    $events[]=array('event_phid'=>$event->getPHID(),'epoch'=>$now+$offset);$invitees[]=$invitee;
    $event_id=governance_calendar_wire_positive_id($event->getID());
    $path=$event->getURI();if(!preg_match('@\A/E[1-9][0-9]*\z@D',$path)){throw new RuntimeException('calendar_wire_native_event_path_refused');}
    $browser_events[]=array('label'=>$label,'event_phid'=>$event->getPHID(),'path'=>$path);
    $event_ids[$label]=$event_id;$native_paths[$label]=$path;
  }
  $table=new PhabricatorMetaMTAMail();$conn=$table->establishConnection('w');
  $before=(int)queryfx_one($conn,'SELECT COALESCE(MAX(id),0) n FROM %R',$table)['n'];
  $run=static function()use($now){$engine=(new PhabricatorCalendarNotificationEngine())->setCursor($now);
    (new ReflectionMethod(PhabricatorCalendarNotificationEngine::class,'sendNotifications'))->invoke($engine);};
  $time=PhabricatorTime::pushTime($now,'UTC');
  try{$run();$mails=array();foreach($table->loadAllWhere('id>%d ORDER BY id',$before)as$mail){
      if($mail->getToPHIDs()===array($recipient->getPHID())&&$mail->getPrivateMailPolicyValue('governance.mail-producer')===PhabricatorCalendarNotificationEngine::class){$mails[]=$mail;}}
    $mark=new PhabricatorCalendarNotification();$marks=queryfx_all($mark->establishConnection('w'),
      'SELECT eventPHID,targetPHID,utcInitialEpoch,didNotifyEpoch FROM %R WHERE targetPHID=%s ORDER BY eventPHID,utcInitialEpoch',$mark,$recipient->getPHID());
    $bindings=governance_calendar_wire_binding($events,$mails,$marks,$recipient->getPHID(),$now,$enabled);
    foreach($bindings as$binding){$outbox=(new PhabricatorMetaMTAGorgeOutbox())->loadOneWhere('eventID=%s',$binding['relay_event_id']);
      if(!$outbox||$outbox->getDeliveredEpoch()!==null){throw new RuntimeException('calendar_wire_unrelayed_native_mail_required');}}
    $check(true,'calendar-wire-native-producer-and-unrelayed-outbox-before-any-prepare');
    return array('bindings'=>$bindings,'events'=>$events,'invitees'=>$invitees,'marks'=>$marks,
      'browser_events'=>$browser_events,'event_ids'=>$event_ids,'native_paths'=>$native_paths,
      'native_scheduler_body_proved'=>true,'daemon_or_global_lock_proved'=>false,'SMTP_or_browser_proved'=>false);
  }finally{unset($time);}
}
