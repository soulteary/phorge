<?php
/** Actual owned handle/FD marker + native event classifier; observer SQL is a collaborator. */
$root=$argv[1];$mode=$argv[2];$queries=array();
function phutil_register_library_map($map){foreach($map['class']as$c=>$p)$GLOBALS['paths'][$c]=$GLOBALS['base'].'/'.$p;}
foreach(array('/support/runtime/src','/src')as$b){$GLOBALS['base']=$root.$b;require$root.$b.'/__phutil_library_map__.php';}spl_autoload_register(static function($c){if(isset($GLOBALS['paths'][$c]))require_once$GLOBALS['paths'][$c];});
function queryfx_all($observer,$q,...$args){$GLOBALS['queries'][]=$q;$mode=$GLOBALS['mode'];
 if(str_contains($q,'setup_consumers')){if($mode==='late-reply')usleep(8100000);$out=array();foreach(array('global_instrumentation','thread_instrumentation','events_transactions_current')as$name)$out[]=array('NAME'=>$name,'ENABLED'=>'YES');if($mode==='missing-consumer')array_pop($out);return$out;}
 if(str_contains($q,'events_transactions_current'))return array(array('THREAD_ID'=>'77','EVENT_ID'=>'88','STATE'=>$mode==='active'?'ACTIVE':($mode==='rolled-back'?'ROLLED BACK':'COMMITTED'),'END_EVENT_ID'=>$mode==='active'?null:'99','ISOLATION_LEVEL'=>$mode==='wrong-isolation'?'REPEATABLE READ':'READ COMMITTED'));
 throw new LogicException('querychanged');}
function queryfx_one($observer,$q,...$args){$GLOBALS['queries'][]=$q;$mode=$GLOBALS['mode'];
 if(str_contains($q,'setup_instruments'))return array('ENABLED'=>$mode==='disabled-instrument'?'NO':'YES','TIMED'=>'YES');
 if(str_starts_with($q,'SELECT INSTRUMENTED'))return array('INSTRUMENTED'=>$mode==='disabled-thread'?'NO':'YES');
 if(str_contains($q,'INNODB_TRX'))return array('active_transactions'=>$mode==='active-trx'?'1':($mode==='boolean-zero'?false:'0'),'record_locks'=>$mode==='subject-lock'?'1':'0');throw new LogicException('querychanged');}
require$root.'/support/governance/native-organization/workers.php';require$root.'/support/governance/recovery/borrowed-session-producer.php';require$root.'/support/governance/recovery/borrowed-session-diagnostic.php';
$path=realpath(sys_get_temp_dir()).'/governance-native-worker-'.bin2hex(random_bytes(12));mkdir($path,0700);$nonce=str_repeat('a',64);governance_native_worker_atomic($path,'owner',$nonce);governance_native_worker_atomic($path,'borrowed-preflight-ready.json',array('status'=>'borrowed-preflight-committed','mysql_thread'=>$mode==='wrong-marker'?99:11));$st=lstat($path);$worker=array('index'=>0,'action'=>'recovery.session.borrowed-owner.issue','private'=>$path,'dev'=>$st['dev'],'ino'=>$st['ino'],'nonce'=>$nonce,'cleaned'=>false,'mysql_thread'=>11,'process'=>null);$GLOBALS['governance_native_workers'][0]=$worker;$ok=false;$error=null;try{$ok=governance_native_wait_borrowed_session_preflight($worker,new stdClass());}catch(Throwable$e){$error=get_class($e);}
foreach(glob($path.'/*')as$f)unlink($f);rmdir($path);echo json_encode(array('passed'=>$ok,'error'=>$error,'queries'=>count($queries),'native_classifier'=>(new ReflectionMethod(PhorgeRecoverySessionProducerFence::class,'leaseClassifyEvent'))->getDeclaringClass()->getName(),'physical_SQL_executed'=>false));
