<?php
require_once dirname(__DIR__).'/organization-grant-mdl-cutpoint-diagnostic.php';
require_once dirname(__DIR__).'/organization-grant-mdl-acquisition-diagnostic.php';
require_once dirname(__DIR__).'/organization-grant-mdl-v3.php';
$checks=0;
function v3_check($ok){global$checks;if($ok!==true){throw new RuntimeException('v3_host_control_failed');}$checks++;}
function v3_reject(callable$f){try{$f();}catch(Throwable$e){v3_check(true);return;}v3_check(false);}
$holder=array('OBJECT_INSTANCE_BEGIN'=>'9001','LOCK_TYPE'=>'SHARED_WRITE','LOCK_DURATION'=>'TRANSACTION','LOCK_STATUS'=>'GRANTED','OWNER_THREAD_ID'=>'1001','OWNER_EVENT_ID'=>'20','PROCESSLIST_ID'=>'101');
$request=array('OBJECT_INSTANCE_BEGIN'=>'9002','LOCK_TYPE'=>'SHARED_READ_ONLY','LOCK_DURATION'=>'TRANSACTION','LOCK_STATUS'=>'PENDING','OWNER_THREAD_ID'=>'1002','OWNER_EVENT_ID'=>'40','PROCESSLIST_ID'=>'102');
$tx=array('THREAD_ID'=>'1001','EVENT_ID'=>'2','STATE'=>'ACTIVE','END_EVENT_ID'=>null);
$physical=array('TRX_ID'=>'3001','THREAD_ID'=>'1001','PROCESSLIST_ID'=>'101');
$identity=governance_grant_acquisition_identity(array($holder),array($tx),array($physical),'101');
$select=array('THREAD_ID'=>'1001','EVENT_ID'=>'10','END_EVENT_ID'=>'19','EVENT_NAME'=>'statement/sql/select','NESTING_EVENT_ID'=>'2','NESTING_EVENT_TYPE'=>'TRANSACTION');
$revoke=array('THREAD_ID'=>'1002','EVENT_ID'=>'30','END_EVENT_ID'=>null,'EVENT_NAME'=>'statement/sql/revoke','NESTING_EVENT_ID'=>null,'NESTING_EVENT_TYPE'=>null);
$capture=array('private_identity'=>$identity,'private_after_identity'=>$identity,'private_statement_rows'=>array($select,$select),'private_statement_counts'=>array('current'=>1,'history'=>1),'public'=>governance_grant_acquisition_unavailable());
$run=static fn($c,$before,$after,$tickets,$requests)=>governance_grant_mdl_v3_proof($tickets,$c,$before,$after,$requests,'101','102');
$proof=$run($capture,$identity,$identity,array($holder,$request),array($revoke));v3_check($proof['statement']===$select&&$proof['physical']===$physical&&$proof['holder']===$holder&&$proof['request_statement']===$revoke);
// Public diagnostic booleans are deliberately unavailable: private source alone is authoritative.
v3_check($capture['public']['unavailable']===true);
foreach(array('OBJECT_INSTANCE_BEGIN'=>'9003','OWNER_THREAD_ID'=>'1003','OWNER_EVENT_ID'=>'21')as$key=>$value){$bad=$capture;$bad['private_after_identity']['ticket'][$key]=$value;v3_reject(fn()=>$run($bad,$identity,$identity,array($holder,$request),array($revoke)));}
foreach(array('transaction','physical')as$kind){$bad=$capture;$bad['private_after_identity'][$kind][$kind==='transaction'?'EVENT_ID':'TRX_ID']='3002';v3_reject(fn()=>$run($bad,$identity,$identity,array($holder,$request),array($revoke)));}
$late=$identity;$late['physical']['TRX_ID']='3002';v3_reject(fn()=>$run($capture,$identity,$late,array($holder,$request),array($revoke)));
$late=$identity;$late['transaction']['EVENT_ID']='3';v3_reject(fn()=>$run($capture,$late,$late,array($holder,$request),array($revoke)));
$bad=$capture;$bad['private_identity']['physical']['PROCESSLIST_ID']='999';$bad['private_after_identity']=$bad['private_identity'];v3_reject(fn()=>$run($bad,$bad['private_identity'],$bad['private_identity'],array($holder,$request),array($revoke)));
$next=$select;$next['EVENT_ID']='20';$next['END_EVENT_ID']=null;$bad=$capture;$bad['private_statement_rows']=array($select,$next);v3_reject(fn()=>$run($bad,$identity,$identity,array($holder,$request),array($revoke)));
$other=$select;$other['EVENT_ID']='11';$bad=$capture;$bad['private_statement_rows']=array($select,$other);v3_reject(fn()=>$run($bad,$identity,$identity,array($holder,$request),array($revoke)));
$other=$select;$other['END_EVENT_ID']='18';$bad=$capture;$bad['private_statement_rows']=array($select,$other);v3_reject(fn()=>$run($bad,$identity,$identity,array($holder,$request),array($revoke)));
$bad=$capture;$bad['private_statement_counts']=array('current'=>17,'history'=>0);v3_reject(fn()=>$run($bad,$identity,$identity,array($holder,$request),array($revoke)));
$bad=$capture;$bad['private_statement_counts']['current']=true;v3_reject(fn()=>$run($bad,$identity,$identity,array($holder,$request),array($revoke)));
foreach(array('EVENT_NAME'=>'statement/sql/update','NESTING_EVENT_ID'=>'3','NESTING_EVENT_TYPE'=>'STATEMENT','THREAD_ID'=>'1003','END_EVENT_ID'=>null)as$key=>$value){$bad=$capture;$bad['private_statement_rows']=array(array_replace($select,array($key=>$value)),array_replace($select,array($key=>$value)));v3_reject(fn()=>$run($bad,$identity,$identity,array($holder,$request),array($revoke)));}
$extra=$holder;$extra['OBJECT_INSTANCE_BEGIN']='9003';$extra['PROCESSLIST_ID']='103';v3_reject(fn()=>$run($capture,$identity,$identity,array($holder,$request,$extra),array($revoke)));
$extra=$request;$extra['OBJECT_INSTANCE_BEGIN']='9003';v3_reject(fn()=>$run($capture,$identity,$identity,array($holder,$request,$extra),array($revoke)));
$extra=$request;$extra['LOCK_TYPE']='UNKNOWN';v3_reject(fn()=>$run($capture,$identity,$identity,array($holder,$extra),array($revoke)));
$extra=$request;$extra['PROCESSLIST_ID']='103';v3_reject(fn()=>$run($capture,$identity,$identity,array($holder,$extra),array($revoke)));
v3_reject(fn()=>$run($capture,$identity,$identity,array_fill(0,65,$holder),array($revoke)));
foreach(array('EVENT_NAME'=>'statement/sql/select','THREAD_ID'=>'1003','END_EVENT_ID'=>'39')as$key=>$value){$bad=array_replace($revoke,array($key=>$value));v3_reject(fn()=>$run($capture,$identity,$identity,array($holder,$request),array($bad)));}
v3_reject(fn()=>$run($capture,$identity,$identity,array($holder,$request),array()));
$strict=$select;$strict['END_EVENT_ID']='20';$bad=$capture;$bad['private_statement_rows']=array($strict,$strict);v3_check($run($bad,$identity,$identity,array($holder,$request),array($revoke))['statement']===$strict);
$bad=$capture;$bad['private_statement_rows'][0]['EVENT_ID']='18446744073709551616';v3_reject(fn()=>$run($bad,$identity,$identity,array($holder,$request),array($revoke)));
$extra=$holder;$extra['OBJECT_INSTANCE_BEGIN']='9003';$extra['LOCK_TYPE']='SHARED_READ';$extra['PROCESSLIST_ID']='103';v3_check($run($capture,$identity,$identity,array($holder,$request,$extra),array($revoke))['holder']===$holder);
$ended=$tx;$ended['STATE']='COMMITTED';v3_reject(fn()=>governance_grant_mdl_v3_statement(array($select),'1001','20','statement/sql/select',$ended));
echo $checks." MDLv3 host controls passed\n";
