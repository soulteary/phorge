<?php
/** Fixed actual owned scheduler + prepared mail + native revocation profile. */
function governance_calendar_revocation_obligations(){return array(
 'calendar-measurement-native-two-mails-prepared','calendar-measurement-current-source-revocation-observed',
 'calendar-measurement-serial-clock-and-persisted-transition','calendar-measurement-positive-control-B-retained',
 'calendar-measurement-owned-queue-cleanup');}
function governance_calendar_revocation_measurement_cases($fixture,callable $check){
  $fixture->assertOwned();require_once __DIR__.'/calendar-observer.php';
  require_once dirname(__DIR__).'/notification/calendar-reminder-cases.php';
  require_once dirname(__DIR__).'/notification/calendar-wire-producer.php';
  $queue=null;$scope=null;$event=null;$cleanup=null;$primary=null;
  try{
    $queue=governance_completion_start_gorge($fixture,'taskqueue',$check);
    $scope=governance_calendar_reminder_scope($queue);$suffix=substr($fixture->getNamespace(),-12);
    $new=static function($label)use($suffix){$user=(new PhabricatorUser())->setUsername('n12cal'.$label.$suffix)->setRealName('Synthetic Calendar Measurement')->setIsApproved(1)->setIsEmailVerified(1)->save();
      (new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
      return(new PhabricatorPeopleQuery())->setViewer(PhabricatorUser::getOmnipotentUser())->withPHIDs(array($user->getPHID()))->needUserSettings(true)->executeOne();};
    $recipient=$new('recipient');$host=$new('host');
    // Producer's own fixed assertion is checked locally, not silently added to receipt obligations.
    $produced=governance_calendar_wire_produce($fixture,static function($ok,$label){if($ok!==true||$label!=='calendar-wire-native-producer-and-unrelayed-outbox-before-any-prepare'){throw new RuntimeException('calendar_measurement_producer_refused');}},$recipient,$host,PhabricatorTime::getNow(),true);
    $mails=array();foreach($produced['bindings']as$binding){$mail=(new PhabricatorMetaMTAMail())->load($binding['mail_id']);$snapshot=$mail->prepareGorgeDelivery();if(!isset($snapshot['authorization'],$snapshot['message'])){throw new RuntimeException('calendar_measurement_prepare_refused');}$mails[]=$mail;}
    $check(count($mails)===2,'calendar-measurement-native-two-mails-prepared');
    $event=governance_calendar_revocation_observe($fixture,$produced['invitees'][0],$mails);
    $outcomes=array_column($event['attempts'],'outcome');
    $check($outcomes===array('allowed','allowed','denied','allowed','denied'),'calendar-measurement-current-source-revocation-observed');
    $check($event['persisted_uninvited_observed']===true&&$event['attempts'][1]['finish_ns']<=$event['operation_start_ns']&&$event['commit_confirmed_return_ns']<=$event['attempts'][2]['start_ns'],'calendar-measurement-serial-clock-and-persisted-transition');
    $check($event['attempts'][3]['label']==='B'&&$event['attempts'][3]['outcome']==='allowed','calendar-measurement-positive-control-B-retained');
  }catch(Throwable$error){$primary=$error;throw$error;}
  finally{
    $errors=$primary===null?array():array('primary'=>$primary);
    try{unset($scope);}catch(Throwable$error){$errors['scope_cleanup']=$error;}
    if($queue!==null){try{$cleanup=governance_completion_stop_gorge($queue);}catch(Throwable$error){$errors['queue_cleanup']=$error;}}
    if(count($errors)>($primary===null?0:1)){throw new PhutilAggregateException('calendar_measurement_cleanup_failed',$errors);}
  }
  $check(is_array($cleanup)&&$cleanup['process_stopped']===true&&$cleanup['private_storage_removed']===true,'calendar-measurement-owned-queue-cleanup');
  return array('profile'=>'native-calendar-revocation-measurement-v1','event'=>$event,'queue_cleanup'=>$cleanup,
    'measurement_acceptance'=>false,'SLA_proved'=>false,'physical_SMTP_proved'=>false,'convergence_proved'=>false,'restoration_authorized'=>false);
}
