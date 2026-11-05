<?php
/** Bounded observation scheduler. A late or insufficiently spaced match never passes. */
function governance_grant_owned_x_poll(callable$observe,callable$clock,callable$pause){
  $lastClock=null;
  $now=static function()use($clock,&$lastClock){$value=$clock();if(!is_int($value)||$value<0||($lastClock!==null&&$value<$lastClock)){throw new RuntimeException('grant_owned_x_clock_unknown');}$lastClock=$value;return$value;};
  $start=$now();if($start>PHP_INT_MAX-3000000000){throw new RuntimeException('grant_owned_x_clock_unknown');}$deadline=$start+3000000000;$lastProbe=null;$lastFinished=null;
  $result=array('matched'=>false,'attempts'=>0,'observation_complete'=>true,'deadline_expired'=>false,'interval_proved'=>true,'minimum_interval_us'=>250000,'deadline_ms'=>3000);
  for($i=0;$i<13;$i++){
    $at=$now();if($at>=$deadline){$result['deadline_expired']=true;return$result;}
    if($lastProbe!==null&&$at-$lastFinished<250000000){$result['interval_proved']=false;return$result;}
    $lastProbe=$at;$result['attempts']++;$matched=$observe();
    if(!is_bool($matched)){throw new RuntimeException('grant_owned_x_observation_unknown');}
    $finished=$now();$lastFinished=$finished;if($finished>=$deadline){$result['deadline_expired']=true;return$result;}
    if($matched){$result['matched']=true;return$result;}
    $pause(250000);
  }
  return$result;
}
/** The original owned PRIMARY X / physical transaction query, not a replacement authority. */
function governance_grant_owned_x_observe(mysqli$control,mysqli$fencer){
  if($fencer->thread_id<=0){throw new RuntimeException('grant_owned_x_connection_unknown');}
  return governance_grant_owned_x_poll(static function()use($control,$fencer){
    $result=$control->query("SELECT COUNT(*) n FROM performance_schema.data_locks l JOIN information_schema.INNODB_TRX b ON b.TRX_ID=CAST(l.ENGINE_TRANSACTION_ID AS CHAR) WHERE l.ENGINE='INNODB' AND b.TRX_MYSQL_THREAD_ID=".(int)$fencer->thread_id." AND l.OBJECT_SCHEMA='mysql' AND l.OBJECT_NAME='user' AND l.INDEX_NAME='PRIMARY' AND l.LOCK_TYPE='RECORD' AND l.LOCK_STATUS='GRANTED' AND l.LOCK_MODE IN ('X','X,REC_NOT_GAP')");
    if($result===false){throw new RuntimeException('grant_owned_x_observation_unknown');}
    try{$row=$result->fetch_assoc();if(!is_array($row)||array_keys($row)!==array('n')){throw new RuntimeException('grant_owned_x_observation_unknown');}$n=$row['n'];
      if(is_int($n)&&$n>=0){$n=(string)$n;}
      if(!is_string($n)||!preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$n)||(strlen($n)===20&&strcmp($n,'18446744073709551615')>0)){throw new RuntimeException('grant_owned_x_observation_unknown');}
      return$n!=='0';
    }finally{$result->free();}
  },static function(){return hrtime(true);},static function($microseconds){usleep($microseconds);});
}
function governance_grant_owned_x_projection($value){
  $keys=array('attempts','deadline_expired','deadline_ms','ending','interval_proved','minimum_interval_us','observation_complete','owned_X','tuple');
  if(!is_array($value)){return array('unavailable'=>true);}$actual=array_keys($value);sort($actual);
  if($actual!==$keys||!in_array($value['ending'],array('commit','rollback'),true)||!is_int($value['attempts'])||$value['attempts']<0||$value['attempts']>13||$value['deadline_ms']!==3000||$value['minimum_interval_us']!==250000){return array('unavailable'=>true);}
  foreach(array('deadline_expired','interval_proved','observation_complete','owned_X','tuple')as$key){if(!is_bool($value[$key])){return array('unavailable'=>true);}}
  return$value;
}
