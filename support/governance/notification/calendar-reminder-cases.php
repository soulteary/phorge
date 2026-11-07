<?php
/** Owned real scheduler body, SQL marks and queued mail; no daemon or SMTP. */
function governance_calendar_reminder_obligations() {
  return array(
    'calendar-private-real-queue-ready-no-worker',
    'calendar-private-two-real-attending-events-different-view-policies',
    'calendar-private-scheduler-persists-two-independent-event-mails',
    'calendar-private-stored-mail-events-manually-relay-and-deduplicate-in-real-queue',
    'calendar-private-scheduler-native-mark-keys-cover-each-event',
    'calendar-private-repeated-scheduler-keeps-mail-and-mark-counts',
    'calendar-private-each-original-event-current-query-and-egress-allow',
    'calendar-private-persisted-and-wire-payloads-have-no-private-content',
    'calendar-private-view-policy-alone-does-not-remove-native-attendee-access',
    'calendar-private-uninvited-and-hidden-event-current-query-denied',
    'calendar-private-single-event-revocation-denies-only-own-physical-callback',
    'calendar-private-restored-view-does-not-revive-quarantined-mail',
    'calendar-private-recipient-disable-denies-remaining-physical-callback',
    'calendar-private-recipient-reenable-does-not-revive-quarantined-mail',
    'calendar-private-disabled-recipient-not-selected-by-native-scheduler',
    'calendar-native-off-scheduler-preserves-one-two-event-digest',
    'calendar-native-off-repeat-keeps-native-occurrence-marks',
    'calendar-native-recurring-parent-and-persisted-child-mark-keys-preserved',
    'calendar-native-recurring-scheduler-does-not-resend-same-occurrence',
    'calendar-private-unpersisted-ghost-has-no-invented-related-object-authority',
    'calendar-private-owned-queue-cleanup');
}
function governance_calendar_reminder_source_inputs() {
  return array(
    'src/applications/calendar/notifications/PhabricatorCalendarNotificationEngine.php',
    'src/applications/calendar/notifications/PhabricatorCalendarEventNotificationView.php',
    'src/applications/calendar/storage/PhabricatorCalendarEvent.php',
    'src/applications/calendar/storage/PhabricatorCalendarEventInvitee.php',
    'src/applications/calendar/storage/PhabricatorCalendarNotification.php',
    'src/applications/calendar/query/PhabricatorCalendarEventQuery.php',
    'src/applications/metamta/storage/PhabricatorMetaMTAMail.php',
    'src/infrastructure/governance/notification/PhorgePrivateMailPolicy.php',
    'support/governance/completion/pipeline-gorge.php',
    'support/governance/notification/calendar-reminder-cases.php',
    'support/governance/notification/governance-notice-cases.php',
    'support/governance/native-organization/test-native-organization-mysql.php');
}
function governance_calendar_reminder_scope(array $queue) {
  return PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.private-mail.enabled',true)
    ->overrideEnvConfig('governance.media-exchange.enabled',false)
    ->overrideEnvConfig('governance.media-exchange.http-enabled',false)
    ->overrideEnvConfig('governance.organization.enabled',false)->overrideEnvConfig('governance.notifications.enabled',false)
    ->overrideEnvConfig('metamta.gorge-delivery-mode','native')->overrideEnvConfig('gorge.taskqueue.owner','gorge')
    ->overrideEnvConfig('gorge.taskqueue.uri',$queue['uri'])->overrideEnvConfig('gorge.taskqueue.token',$queue['token'])
    ->overrideEnvConfig('gorge.service-policies',array('file'=>'required','taskqueue'=>'required','mailer'=>'required'))
    ->overrideEnvConfig('cluster.mailers',array(array('key'=>'calendar-isolated-serialization','type'=>'gorge','inbound'=>false,
        'options'=>array('uri'=>$queue['uri'],'token'=>$queue['token']))));
}
function governance_calendar_reminder_cases($fixture,callable $check) {
  $fixture->assertOwned();require_once dirname(__DIR__).'/completion/pipeline-gorge.php';
  require_once __DIR__.'/governance-notice-cases.php';
  require_once dirname(__DIR__).'/native-organization/workers.php';
  $revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
  if(!is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/D',$revision)){
    throw new RuntimeException('calendar_reminder_source_revision_required');}
  if(PhorgePrivateMailPolicy::enabled()||(new ReflectionProperty(PhabricatorWorker::class,'runAllTasksInProcess'))->getValue()!==false){
    throw new RuntimeException('calendar_reminder_inherited_delivery_refused');}
  $queue=null;$scope=null;$time=null;$queue_cleanup=null;$executed=array();$primary=null;
  $record=static function($value,$name)use($check,&$executed){
    if($value!==true||!in_array($name,governance_calendar_reminder_obligations(),true)||isset($executed[$name])){
      throw new RuntimeException('calendar_reminder_case_refused');}
    $executed[$name]=true;$check(true,$name);
  };
  try {
    $queue=governance_completion_start_gorge($fixture,'taskqueue',$check);
    $scope=governance_calendar_reminder_scope($queue);
    $record(is_array((new PhabricatorGorgeTaskQueueClient())->getStats()),'calendar-private-real-queue-ready-no-worker');
    $now=PhabricatorTime::getNow();$time=PhabricatorTime::pushTime($now,'UTC');$suffix=substr($fixture->getNamespace(),-10);
    $new_user=static function($label)use($suffix){
      $user=(new PhabricatorUser())->setUsername('pmcal'.$label.$suffix)->setRealName('Synthetic Calendar account')
        ->setIsApproved(1)->setIsEmailVerified(1)->save();
      (new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')
        ->setIsPrimary(1)->setIsVerified(1)->save();
      return (new PhabricatorPeopleQuery())->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withPHIDs(array($user->getPHID()))->needUserSettings(true)->executeOne();
    };
    $host=$new_user('host');$recipient=$new_user('recipient');$off_recipient=$new_user('off');$recurring_recipient=$new_user('recurring');
    $make_event=static function($label,$viewer,$policy,$start)use($host){
      $event=PhabricatorCalendarEvent::initializeNewCalendarEvent($host)->setName('SYNTHETIC_PRIVATE_CALENDAR_'.$label)
        ->setDescription('SYNTHETIC_PRIVATE_CALENDAR_DESCRIPTION')->setViewPolicy($policy)
        ->setStartDateTime(PhutilCalendarAbsoluteDateTime::newFromEpoch($start,'UTC'))
        ->setEndDateTime(PhutilCalendarAbsoluteDateTime::newFromEpoch($start+1800,'UTC'))->save();
      $invitee=PhabricatorCalendarEventInvitee::initializeNewCalendarEventInvitee($host,$event)
        ->setInviteePHID($viewer->getPHID())->setStatus(PhabricatorCalendarEventInvitee::STATUS_ATTENDING)->save();
      return array($event,$invitee);
    };
    $first=$make_event('A',$recipient,PhabricatorPolicies::POLICY_NOONE,$now+300);
    $second=$make_event('B',$recipient,PhabricatorPolicies::POLICY_USER,$now+600);
    $record($first[0]->getViewPolicy()!==$second[0]->getViewPolicy()&&$first[1]->isAttending()&&$second[1]->isAttending(),
      'calendar-private-two-real-attending-events-different-view-policies');
    $mail_table=new PhabricatorMetaMTAMail();$mark_table=new PhabricatorCalendarNotification();
    $mail_conn=$mail_table->establishConnection('w');$calendar_conn=$mark_table->establishConnection('w');
    $latest=static function()use($mail_table,$mail_conn){return(int)queryfx_one($mail_conn,'SELECT COALESCE(MAX(id),0) n FROM %R',$mail_table)['n'];};
    $load=static function($after,$target)use($mail_table){$out=array();
      foreach($mail_table->loadAllWhere('id>%d ORDER BY id',$after)as$mail){
        if($mail->getPrivateMailPolicyValue('governance.mail-producer')===PhabricatorCalendarNotificationEngine::class&&
          $mail->getToPHIDs()===array($target)){$out[]=$mail;}}
      return$out;
    };
    $marks=static function($target)use($mark_table,$calendar_conn){return queryfx_all($calendar_conn,
      'SELECT eventPHID,targetPHID,utcInitialEpoch,didNotifyEpoch FROM %R WHERE targetPHID=%s ORDER BY eventPHID,utcInitialEpoch',$mark_table,$target);};
    $run=static function()use($now){$engine=(new PhabricatorCalendarNotificationEngine())->setCursor($now);
      // Execute the real native body, including event/user queries and marks.
      // This is not the daemon/global-lock/installed-application wrapper.
      (new ReflectionMethod(PhabricatorCalendarNotificationEngine::class,'sendNotifications'))->invoke($engine);
    };
    $start=$latest();$run();$mails=$load($start,$recipient->getPHID());$by_event=array();
    foreach($mails as$mail){$by_event[$mail->getRelatedPHID()]=$mail;}
    $record(count($mails)===2&&count($by_event)===2&&isset($by_event[$first[0]->getPHID()],$by_event[$second[0]->getPHID()]),
      'calendar-private-scheduler-persists-two-independent-event-mails');
    $relayed=true;$task_ids=array();$queue_owner='notice-'.bin2hex(random_bytes(16));
    foreach($mails as$mail){
      $event_id='mail.prepare/'.$mail->getPHID().'/1';
      $event=(new PhabricatorMetaMTAGorgeOutbox())->loadOneWhere('eventID=%s',$event_id);
      if(!$event||$event->getDeliveredEpoch()!==null){throw new RuntimeException('calendar_reminder_native_outbox_required');}
      $payload=json_decode($event->getPayload(),true,16,JSON_THROW_ON_ERROR);
      if(($payload['eventID']??null)!==$event_id||($payload['task']['taskClass']??null)!=='GorgeMailDeliveryWorker'||
        ($payload['task']['data']??null)!==phutil_json_encode((int)$mail->getID())){
        throw new RuntimeException('calendar_reminder_native_outbox_payload_refused');}
      $task=governance_notice_queue_http($queue,'enqueue-event',$payload,$queue_owner);
      $again=governance_notice_queue_http($queue,'enqueue-event',$payload,$queue_owner);
      $id=$task['id']??null;
      if(!is_int($id)||$id<1||($again['id']??null)!==$id){throw new RuntimeException('calendar_reminder_queue_dedup_refused');}
      $active=(new PhabricatorWorkerActiveTask())->load($id);
      $data=$active?(new PhabricatorWorkerTaskData())->load($active->getDataID()):null;
      $relayed=$relayed&&$active&&$active->getTaskClass()==='GorgeMailDeliveryWorker'&&$data&&$data->getData()===(int)$mail->getID();
      $task_ids[]=$id;
    }
    $record((bool)$relayed&&count(array_unique($task_ids))===2,
      'calendar-private-stored-mail-events-manually-relay-and-deduplicate-in-real-queue');
    $rows=$marks($recipient->getPHID());$actual=array();foreach($rows as$row){
      $actual[$row['eventPHID']]=array($row['targetPHID'],(int)$row['utcInitialEpoch'],(int)$row['didNotifyEpoch']);}
    $record(count($rows)===2&&($actual[$first[0]->getPHID()]??null)===array($recipient->getPHID(),$now+300,$now)&&
      ($actual[$second[0]->getPHID()]??null)===array($recipient->getPHID(),$now+600,$now),
      'calendar-private-scheduler-native-mark-keys-cover-each-event');
    $run();$record(count($load($start,$recipient->getPHID()))===2&&$marks($recipient->getPHID())===$rows,
      'calendar-private-repeated-scheduler-keeps-mail-and-mark-counts');
    $snapshots=array();$allowed=true;$safe=true;
    $GLOBALS['governance_calendar_attempt']='calendar-private-each-original-event-current-query-and-egress-allow';
    $GLOBALS['governance_calendar_egress_probe']=array('schema'=>'calendar-egress-bool-v1','rows'=>array());
    foreach($by_event as$phid=>$mail){
      $index=count($GLOBALS['governance_calendar_egress_probe']['rows']);
      $GLOBALS['governance_calendar_egress_probe']['rows'][$index]=array_fill_keys(array(
        'object_query_attempted','object_query_found','object_identity_checked','object_identity_matched',
        'prepare_attempted','queue_before','snapshot_cached_before','quarantined_before',
        'snapshot_void','snapshot_authorization_present','snapshot_message_present','quarantined_after','queue_after',
        'physical_callback_attempted','physical_callback_allowed'),false);
      $probe=&$GLOBALS['governance_calendar_egress_probe']['rows'][$index];
      $probe['object_query_attempted']=true;
      $object=(new PhabricatorCalendarEventQuery())->setViewer($recipient)->withPHIDs(array($phid))->executeOne();
      $probe['object_query_found']=(bool)$object;
      $probe['queue_before']=$mail->getStatus()===PhabricatorMailOutboundStatus::STATUS_QUEUE;
      $probe['snapshot_cached_before']=($mail->getParameters()['gorge.delivery-snapshot']??null)!==null;
      $probe['quarantined_before']=$mail->getPrivateMailPolicyValue('governance.delivery-quarantined',false)===true;
      $probe['prepare_attempted']=true;
      $snapshot=$mail->prepareGorgeDelivery();$snapshots[$phid]=$snapshot;
      $probe['snapshot_void']=$snapshot===array('state'=>'void');
      $probe['snapshot_authorization_present']=isset($snapshot['authorization']);
      $probe['snapshot_message_present']=isset($snapshot['message']);
      $probe['quarantined_after']=$mail->getPrivateMailPolicyValue('governance.delivery-quarantined',false)===true;
      $probe['queue_after']=$mail->getStatus()===PhabricatorMailOutboundStatus::STATUS_QUEUE;
      $allowed=$allowed&&$object&&($probe['object_identity_checked']=true)&&
        ($probe['object_identity_matched']=$object->getPHID()===$phid)&&isset($snapshot['authorization'])&&
        ($probe['physical_callback_attempted']=true)&&
        ($probe['physical_callback_allowed']=$mail->authorizeGorgePhysicalDelivery($snapshot['deliveryID'],$snapshot['authorization']['messageHash'],phutil_json_encode($snapshot['message']))===array('allowed'=>true));
      unset($probe);
      $reloaded=(new PhabricatorMetaMTAMail())->load($mail->getID());$raw=phutil_json_encode($snapshot);
      $safe=$safe&&!str_contains($raw,'SYNTHETIC_PRIVATE')&&!str_contains($reloaded->getBody(),'SYNTHETIC_PRIVATE')&&
        str_contains($reloaded->getBody(),'/object/'.$phid.'/')&&$reloaded->getHTMLBody()===null&&!$reloaded->getAttachments()&&
        !isset($snapshot['message']['htmlBody'])&&!isset($snapshot['message']['attachments']);
    }
    $record((bool)$allowed,'calendar-private-each-original-event-current-query-and-egress-allow');
    unset($GLOBALS['governance_calendar_attempt']);
    $record((bool)$safe,'calendar-private-persisted-and-wire-payloads-have-no-private-content');
    $second[0]->setViewPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
    $allowed_now=PhorgePrivateMailPolicy::authorize($by_event[$second[0]->getPHID()]);
    $record($allowed_now,'calendar-private-view-policy-alone-does-not-remove-native-attendee-access');
    $first[1]->setStatus(PhabricatorCalendarEventInvitee::STATUS_UNINVITED)->save();
    $visible=(new PhabricatorCalendarEventQuery())->setViewer($recipient)->withPHIDs(array($first[0]->getPHID()))->executeOne();
    $record($visible===null,'calendar-private-uninvited-and-hidden-event-current-query-denied');
    $callback=static function($phid)use($by_event,$snapshots){$mail=(new PhabricatorMetaMTAMail())->load($by_event[$phid]->getID());$s=$snapshots[$phid];
      return$mail->authorizeGorgePhysicalDelivery($s['deliveryID'],$s['authorization']['messageHash'],phutil_json_encode($s['message']));};
    $record($callback($first[0]->getPHID())===array('allowed'=>false)&&$callback($second[0]->getPHID())===array('allowed'=>true),
      'calendar-private-single-event-revocation-denies-only-own-physical-callback');
    $first[1]->setStatus(PhabricatorCalendarEventInvitee::STATUS_ATTENDING)->save();
    $record($callback($first[0]->getPHID())===array('allowed'=>false),
      'calendar-private-restored-view-does-not-revive-quarantined-mail');
    $recipient->setIsDisabled(1)->save();
    $record($callback($second[0]->getPHID())===array('allowed'=>false),
      'calendar-private-recipient-disable-denies-remaining-physical-callback');
    $recipient->setIsDisabled(0)->save();
    $record($callback($second[0]->getPHID())===array('allowed'=>false),
      'calendar-private-recipient-reenable-does-not-revive-quarantined-mail');
    $disabled=$new_user('disabled');$make_event('DISABLED',$disabled,PhabricatorPolicies::POLICY_USER,$now+420);$disabled->setIsDisabled(1)->save();
    $before=$latest();$run();$record($load($before,$disabled->getPHID())===array()&&$marks($disabled->getPHID())===array(),
      'calendar-private-disabled-recipient-not-selected-by-native-scheduler');
    $off_a=$make_event('OFF_A',$off_recipient,PhabricatorPolicies::POLICY_USER,$now+360);
    $off_b=$make_event('OFF_B',$off_recipient,PhabricatorPolicies::POLICY_USER,$now+540);
    $off=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.private-mail.enabled',false);
    try{$before=$latest();$run();$off_mails=$load($before,$off_recipient->getPHID());
      $record(count($off_mails)===1&&head($off_mails)->getRelatedPHID()===$off_a[0]->getPHID()&&
        str_contains(head($off_mails)->getBody(),'SYNTHETIC_PRIVATE_CALENDAR_OFF_A')&&
        str_contains(head($off_mails)->getBody(),'SYNTHETIC_PRIVATE_CALENDAR_OFF_B')&&count($marks($off_recipient->getPHID()))===2,
        'calendar-native-off-scheduler-preserves-one-two-event-digest');
      $off_marks=$marks($off_recipient->getPHID());$run();
      $record(count($load($before,$off_recipient->getPHID()))===1&&$marks($off_recipient->getPHID())===$off_marks,
        'calendar-native-off-repeat-keeps-native-occurrence-marks');
    }finally{unset($off);}
    // A concrete recurring child has its own persisted PHID and inherits the
    // native parent mark identity. Do not infer a PHID for ephemeral ghosts.
    $parent=$make_event('SERIES',$recurring_recipient,PhabricatorPolicies::POLICY_NOONE,$now-86400+720);
    $parent[0]->setIsRecurring(1)->setParameter('recurrenceCount',2)
      ->setRecurrenceRule((new PhutilCalendarRecurrenceRule())->setFrequency(PhutilCalendarRecurrenceRule::FREQUENCY_DAILY))->save();
    $child=(new ReflectionMethod(PhabricatorCalendarEvent::class,'newChild'))->invoke($parent[0],$host,1)
      ->setName('SYNTHETIC_PRIVATE_CALENDAR_CHILD')->save();
    PhabricatorCalendarEventInvitee::initializeNewCalendarEventInvitee($host,$child)
      ->setInviteePHID($recurring_recipient->getPHID())->setStatus(PhabricatorCalendarEventInvitee::STATUS_ATTENDING)->save();
    $before=$latest();$run();$child_marks=$marks($recurring_recipient->getPHID());$child_mails=$load($before,$recurring_recipient->getPHID());
    $mark_phids=array_column($child_marks,'eventPHID');
    $record(in_array($parent[0]->getPHID(),$mark_phids,true)&&in_array($child->getPHID(),$mark_phids,true)&&
      count($child_mails)===1&&head($child_mails)->getRelatedPHID()===$child->getPHID(),
      'calendar-native-recurring-parent-and-persisted-child-mark-keys-preserved');
    $run();$record(count($load($before,$recurring_recipient->getPHID()))===1&&$marks($recurring_recipient->getPHID())===$child_marks,
      'calendar-native-recurring-scheduler-does-not-resend-same-occurrence');
    $ghost=$parent[0]->newGhost($host,1);$view=(new PhabricatorCalendarEventNotificationView())->setViewer($recurring_recipient)
      ->setEvent($ghost)->setEpoch($ghost->getStartDateTimeEpoch());
    $ghost_mails=(new ReflectionMethod(PhabricatorCalendarNotificationEngine::class,'newMailMessages'))
      ->invoke(new PhabricatorCalendarNotificationEngine(),$recurring_recipient,array($view));
    $ghost_mail=head($ghost_mails)->save();
    $record($ghost->getPHID()===null&&$ghost_mail->getRelatedPHID()===null&&$ghost_mail->prepareGorgeDelivery()===array('state'=>'void'),
      'calendar-private-unpersisted-ghost-has-no-invented-related-object-authority');
  } catch(Throwable $error) {
    $primary=$error;throw$error;
  } finally {
    $cleanup_errors=array();if($primary!==null){$cleanup_errors['primary']=$primary;}
    try{unset($time);}catch(Throwable $error){$cleanup_errors['clock']=$error;}
    try{unset($scope);}catch(Throwable $error){$cleanup_errors['configuration']=$error;}
    try{if($queue!==null){$queue_cleanup=governance_completion_stop_gorge($queue);
      $record($queue_cleanup['process_stopped']===true&&$queue_cleanup['private_storage_removed']===true,
        'calendar-private-owned-queue-cleanup');}}
    catch(Throwable $error){$cleanup_errors['queue']=$error;}
    if(count($cleanup_errors)>($primary!==null?1:0)){
      throw new PhutilAggregateException('calendar_reminder_owned_cleanup_failed',$cleanup_errors);}
  }
  $labels=array_keys($executed);sort($labels);$expected=governance_calendar_reminder_obligations();sort($expected);
  if($labels!==$expected){throw new RuntimeException('calendar_reminder_obligations_incomplete');}
  $hashes=array();foreach(governance_calendar_reminder_source_inputs()as$path){$hashes[$path]=hash_file('sha256',dirname(__DIR__,3).'/'.$path);}
  return array('profile'=>'native-private-calendar-reminder-v1','source_revision'=>$revision,'source_inputs_sha256'=>$hashes,
    'executed_case_obligations'=>$labels,'actual_native_scheduler_body'=>true,'native_global_lock_or_daemon_proved'=>false,
    'actual_native_occurrence_marks'=>true,'actual_paired_taskqueue'=>true,
    'manual_stored_event_queue_relay_proved'=>true,'automatic_outbox_relay_or_queue_daemon_proved'=>false,'queue_cleanup'=>$queue_cleanup,
    'physical_SMTP_or_browser_proved'=>false,'ephemeral_ghost_delivery_supported'=>false,
    'all_calendar_producer_paths_complete'=>false,'activation_blocked'=>true);
}
