<?php
/** Explicit native-mail collaborators, actual binding validation; no SQL proof. */
require dirname(__DIR__).'/calendar-wire-producer.php';
class PhabricatorCalendarNotificationEngine {}
class MailValue {
  public function __construct(public $id,public $phid,public $event,public $tos,public $producer){}
  function getID(){return $this->id;}function getPHID(){return $this->phid;}
  function getRelatedPHID(){return $this->event;}function getToPHIDs(){return $this->tos;}
  function getPrivateMailPolicyValue($key){return $this->producer;}
}
$user='PHID-USER-'.str_repeat('u',20);$now=1700000000;$events=array();$marks=array();$mails=array();
foreach(array('a','b')as$i=>$letter){$phid='PHID-CEVT-'.str_repeat($letter,20);$epoch=$now+300*($i+1);
 $events[]=array('event_phid'=>$phid,'epoch'=>$epoch);
 $marks[]=array('eventPHID'=>$phid,'targetPHID'=>$user,'utcInitialEpoch'=>(string)$epoch,'didNotifyEpoch'=>(string)$now);
 $mails[]=new MailValue($i+1,'PHID-MTAM-'.str_repeat($letter,20),$phid,array($user),PhabricatorCalendarNotificationEngine::class);}
$tests=0;$ok=static function($v)use(&$tests){if($v!==true){throw new RuntimeException('binding_host_failed');}$tests++;};
$deny=static function($e,$m,$r,$u,$n,$enabled)use($ok){try{governance_calendar_wire_binding($e,$m,$r,$u,$n,$enabled);}
 catch(RuntimeException $error){$ok(true);return;}throw new RuntimeException('binding_host_missing_denial');};
$result=governance_calendar_wire_binding($events,$mails,$marks,$user,$now,true);
$ok(count($result)===2&&$result[0]['event_phid']!==$result[1]['event_phid']&&$result[0]['relay_event_id']!==$result[1]['relay_event_id']);
$off=governance_calendar_wire_binding($events,array($mails[0]),$marks,$user,$now,false);$ok(count($off)===1);
$deny($events,array($mails[0]),$marks,$user,$now,true);
$deny($events,$mails,$marks,$user,$now,false);
$bad=clone$mails[1];$bad->event=$mails[0]->event;$deny($events,array($mails[0],$bad),$marks,$user,$now,true);
$bad=clone$mails[1];$bad->id=$mails[0]->id;$deny($events,array($mails[0],$bad),$marks,$user,$now,true);
$bad=clone$mails[1];$bad->tos[]='PHID-USER-'.str_repeat('x',20);$deny($events,array($mails[0],$bad),$marks,$user,$now,true);
$bad=clone$mails[1];$bad->producer='caller';$deny($events,array($mails[0],$bad),$marks,$user,$now,true);
$bad=$marks;$bad[1]['eventPHID']=$marks[0]['eventPHID'];$deny($events,$mails,$bad,$user,$now,true);
$bad=$marks;$bad[1]['utcInitialEpoch']++;$deny($events,$mails,$bad,$user,$now,true);
$bad=$marks;$bad[1]['targetPHID']='PHID-USER-'.str_repeat('x',20);$deny($events,$mails,$bad,$user,$now,true);
$bad=$marks;$bad[1]['didNotifyEpoch']++;$deny($events,$mails,$bad,$user,$now,true);
$deny($events,array($mails[1]),$marks,$user,$now,false);
$deny($events,$mails,$marks,$user,$now,1);
echo json_encode(array('passed'=>$tests,'SQL'=>false,'SMTP'=>false,'browser'=>false),JSON_THROW_ON_ERROR)."\n";
