<?php
/** Actual native Calendar/Mail objects; no SQL, service, session or SMTP proof. */
$root=dirname(__DIR__,4);
require $root.'/support/runtime/src/init/init-library.php';
phutil_load_library($root.'/src/');
$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
$stack=new PhabricatorConfigStackSource();
$stack->pushSource(new PhabricatorConfigDefaultSource());
$stack->pushSource(new PhabricatorConfigDictionarySource(array(
  'governance.private-mail.enabled'=>$input['enabled'],
  'phabricator.base-uri'=>'https://app.example.invalid/')));
(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);
(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
PhabricatorEnv::setLocaleCode('en_US');
$user=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('u',20));
$settings=array('time-format'=>'g:i A','date-format'=>'Y-m-d','timezone'=>'UTC');
(new ReflectionProperty(PhabricatorUser::class,'settingCache'))->setValue($user,$settings);
(new ReflectionProperty(PhabricatorUser::class,'settingCacheKeys'))->setValue($user,array_fill_keys(array_keys($settings),true));
$views=array();
foreach($input['events'] as$id){
  $event=(new PhabricatorCalendarEvent())->setID($id)->setPHID('PHID-CEVT-'.str_repeat((string)$id,20))
    ->setName('SYNTHETIC_PRIVATE_EVENT_'.$id);
  $views[]=(new PhabricatorCalendarEventNotificationView())->setViewer($user)->setEvent($event)->setEpoch(1700000000+$id*60);
}
$engine=new PhabricatorCalendarNotificationEngine();
$batch=new ReflectionMethod(PhabricatorCalendarNotificationEngine::class,'newMailMessages');
$direct=new ReflectionMethod(PhabricatorCalendarNotificationEngine::class,'newMailMessage');
try {
  $mails=$batch->invoke($engine,$user,$views);$rows=array();
  foreach($mails as$mail){
    $before=serialize($mail->getParameters());
    PhorgePrivateMailPolicy::sanitizeStored($mail);
    $rows[]=array('related'=>$mail->getRelatedPHID(),'body'=>$mail->getBody(),
      'subject'=>$mail->getSubject(),'html'=>$mail->getHTMLBody(),'attachments'=>$mail->getAttachments(),
      'to'=>$mail->getToPHIDs(),'cc'=>$mail->getCcPHIDs(),
      'producer'=>$mail->getPrivateMailPolicyValue('governance.mail-producer'),
      'same_parameters_after_sanitizing'=>$before===serialize($mail->getParameters()));
  }
  $native_equal=null;
  if($input['enabled']===false&&$views){
    $native_equal=count($mails)===1&&serialize(head($mails)->getParameters())===serialize($direct->invoke($engine,$user,$views)->getParameters());
  }
  echo json_encode(array('rows'=>$rows,'off_exact_native_digest'=>$native_equal,'error'=>null),JSON_THROW_ON_ERROR);
} catch(RuntimeException $error) {
  echo json_encode(array('error'=>$error->getMessage()),JSON_THROW_ON_ERROR);
}
