<?php
/** Real unsaved native factory, adapted from the reviewed SQL-factory regression. */
$root=dirname(__DIR__,4);
require $root.'/support/runtime/src/init/init-library.php';phutil_load_library($root.'/src/');
$stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDefaultSource());
$stack->pushSource(new PhabricatorConfigDictionarySource(array('phabricator.base-uri'=>'https://app.example.invalid/')));
(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);
(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());PhabricatorEnv::setLocaleCode('en_US');
$user=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('u',20));
(new ReflectionProperty(PhabricatorUser::class,'settingCache'))->setValue($user,array('timezone'=>'UTC'));
(new ReflectionProperty(PhabricatorUser::class,'settingCacheKeys'))->setValue($user,array('timezone'=>true));
PhabricatorCaches::getRequestCache()->setKey(PhabricatorSpacesNamespaceQuery::KEY_ALL,array());
$source=file_get_contents(dirname(__DIR__).'/calendar-wire-producer.php');
if(!preg_match('/PhabricatorCalendarEvent::([A-Za-z_][A-Za-z0-9_]*)\(\$host\)/',$source,$matches)){
 throw new RuntimeException('calendar_wire_factory_source_absent');}
$method=new ReflectionMethod(PhabricatorCalendarEvent::class,$matches[1]);$event=$method->invoke(null,$user);
$old_error=null;try{PhabricatorCalendarEvent::initializeNewEvent($user);}catch(Throwable$error){$old_error=get_class($error);}
if($method->getName()!=='initializeNewCalendarEvent'||!$method->isPublic()||!$method->isStatic()||
 realpath($method->getFileName())!==realpath($root.'/src/applications/calendar/storage/PhabricatorCalendarEvent.php')||
 get_class($event)!=='PhabricatorCalendarEvent'||$event->getHostPHID()!==$user->getPHID()||$event->getSpacePHID()!==null||
 $event->getID()!==null||function_exists('mysqli_init')||$old_error!=='Error'){
 throw new RuntimeException('calendar_wire_real_factory_regression_failed');}
echo json_encode(array('passed'=>1,'SQL'=>false,'SMTP'=>false,'browser'=>false,'actual_unsaved_native_factory'=>true),JSON_THROW_ON_ERROR)."\n";
