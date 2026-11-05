<?php
require_once dirname(__DIR__).'/organization-grant-owned-x.php';
$checks=0;
function x_check($value){global$checks;if($value!==true){throw new RuntimeException('owned_x_host_control_failed');}$checks++;}
function x_refuses(callable$f){try{$f();}catch(RuntimeException$e){x_check(true);return;}x_check(false);}
function x_run(array$matches,$duration=0,$sleep=250000000){$time=0;$starts=array();$i=0;
  $result=governance_grant_owned_x_poll(static function()use(&$time,&$starts,&$i,$matches,$duration){$starts[]=$time;$time+=$duration;return$matches[min($i++,count($matches)-1)];},static function()use(&$time){return$time;},static function($us)use(&$time,$sleep){if($us!==250000){throw new RuntimeException('pause_contract');}$time+=$sleep;});
  return array($result,$starts);
}
list($r,$starts)=x_run(array(true));x_check($r['matched']===true&&$r['attempts']===1&&$starts===array(0));
list($r,$starts)=x_run(array(false,false,true));x_check($r['matched']===true&&$r['attempts']===3&&$starts===array(0,250000000,500000000));
list($r,$starts)=x_run(array(false));x_check($r['matched']===false&&$r['deadline_expired']===true&&$r['attempts']===12);foreach(array_slice($starts,1)as$i=>$at){x_check($at-$starts[$i]>=250000000);}
list($r,$starts)=x_run(array(true),3000000000);x_check($r['matched']===false&&$r['deadline_expired']===true);
list($r,$starts)=x_run(array(true),2999999999);x_check($r['matched']===true&&$r['deadline_expired']===false);
list($r,$starts)=x_run(array(false,true),0,249999999);x_check($r['matched']===false&&$r['interval_proved']===false&&$r['attempts']===1);
x_refuses(fn()=>x_run(array('1')));
x_refuses(fn()=>governance_grant_owned_x_poll(fn()=>true,fn()=>true,fn($us)=>null));
x_refuses(fn()=>governance_grant_owned_x_poll(fn()=>true,fn()=>PHP_INT_MAX,fn($us)=>null));
$sequence=array(10,9);x_refuses(fn()=>governance_grant_owned_x_poll(fn()=>true,static function()use(&$sequence){return array_shift($sequence);},fn($us)=>null));
$good=array('ending'=>'rollback','tuple'=>false,'owned_X'=>true,'observation_complete'=>true,'attempts'=>1,'deadline_expired'=>false,'interval_proved'=>true,'minimum_interval_us'=>250000,'deadline_ms'=>3000);
x_check(governance_grant_owned_x_projection($good)===$good&&($good['tuple']&&$good['owned_X'])===false);
$bad=$good;$bad['attempts']=true;x_check(governance_grant_owned_x_projection($bad)===array('unavailable'=>true));
$bad=$good;$bad['private_id']='sensitive';x_check(governance_grant_owned_x_projection($bad)===array('unavailable'=>true));
$bad=$good;$bad['owned_X']=1;x_check(governance_grant_owned_x_projection($bad)===array('unavailable'=>true));
list($r,$starts)=x_run(array(false,true),200000000,50000000);x_check($r['matched']===false&&$r['interval_proved']===false&&$r['attempts']===1);
echo $checks." owned-X host controls passed\n";
