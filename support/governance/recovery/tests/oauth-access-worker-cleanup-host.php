<?php
/** Actual NEW worker cleanup gate/body; state/SQL handles explicit collaborators. */
$root=$argv[1];$mode=$argv[2];
class PhorgeRecoveryConduitProducerFence{private static $retained=array();}
class Conn{public$active=true;function isInsideTransaction(){return$this->active;}function killTransaction(){$GLOBALS['rollbacks']++;$this->active=false;}}
class LiskDAO{private static $connections;static function closeAllConnections(){$GLOBALS['closes']++;}}
class Guard{function dispose(){$GLOBALS['disposes']++;}}
class Proof{function close(){$GLOBALS['proof_closes']++;}}
$kind=$mode==='old'?'recovery.session.borrowed-owner.issue':'recovery.oauth-access.borrowed-owner.issue';$result=['status'=>$mode==='failed'?'failed':'committed','error_type'=>'Original'];
if($mode==='retained'){(new ReflectionProperty(PhorgeRecoveryConduitProducerFence::class,'retained'))->setValue(null,[new stdClass()]);}
(new ReflectionProperty(LiskDAO::class,'connections'))->setValue(null,[new Conn()]);$guard=new Guard();$proof=new Proof();$rollbacks=$closes=$disposes=$proof_closes=0;
$text=file_get_contents($root.'/support/governance/native-organization/oauth-access-worker.php');$a=strpos($text,'  $conduit_cleanup_known=');$b=strpos($text,"  if ($"."scope_owned &&",$a);eval(substr($text,$a,$b-$a));
echo json_encode(['rollbacks'=>$rollbacks,'closes'=>$closes,'disposes'=>$disposes,'proof_closes'=>$proof_closes,'status'=>$result['status'],'original_failure_preserved'=>($result['error_type']??null)==='Original','SQL'=>false]);
