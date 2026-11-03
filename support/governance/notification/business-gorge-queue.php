<?php
/** Fixed fixture-only versioned queue operations; never a production adapter. */
function governance_business_gorge_http(array $queue,$operation,array $body,$lease_owner){
  if(!in_array($operation,array('lease','resolve','finalize'),true)||
    !preg_match('/\Ahttp:\/\/127\.0\.0\.1:([1-9][0-9]{0,4})\z/D',$queue['uri']??'',$match)||
    (int)$match[1]>65535||!preg_match('/\A[a-f0-9]{64}\z/D',$queue['token']??'')||
    !preg_match('/\Abusiness-gorge-[a-f0-9]{32}\z/D',$lease_owner)){
    throw new RuntimeException('business_gorge_queue_scope_refused');
  }
  $future=(new HTTPSFuture($queue['uri'].'/api/queue/'.$operation))->setTimeout(5)
    ->setMethod('POST')->addHeader('Content-Type','application/json')
    ->addHeader('X-Service-Token',$queue['token'])->addHeader('X-Lease-Owner',$lease_owner)
    ->setData(phutil_json_encode($body));
  list($status,$raw)=$future->resolve();
  if(!($status instanceof HTTPFutureHTTPResponseStatus)||$status->getStatusCode()!==200||strlen($raw)>524288){
    throw new RuntimeException('business_gorge_queue_http_refused');
  }
  $result=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
  if(!is_array($result)||($result['error']??null)!==null||!array_key_exists('data',$result)||$result['data']===null){
    throw new RuntimeException('business_gorge_queue_envelope_refused');
  }
  if($operation!=='lease'&&$result['data']!==array('status'=>$operation==='finalize'?'finalized':'resolved')){
    throw new RuntimeException('business_gorge_queue_outcome_refused');
  }
  return $result['data'];
}
function governance_business_gorge_lease($fixture,array $queue,$target,$owner){
  $fixture->assertOwned();
  // The service supports class-filtered leasing, not an arbitrary task-ID API.
  // Bound the owned class set; return every non-target lease without executing it.
  $dao=new PhabricatorWorkerActiveTask();
  $count=(int)queryfx_one($dao->establishConnection('r'),'SELECT COUNT(*) n FROM %R WHERE taskClass=%s',$dao,'PhabricatorApplicationTransactionPublishWorker')['n'];
  if($count<1||$count>64){throw new RuntimeException('business_gorge_lease_budget_refused');}
  $rows=governance_business_gorge_http($queue,'lease',array('limit'=>64,'taskClasses'=>array('PhabricatorApplicationTransactionPublishWorker')),$owner);
  if(!is_array($rows)||count($rows)>64){throw new RuntimeException('business_gorge_lease_shape_refused');}
  $found=null;
  foreach($rows as$row){
    if(!is_array($row)||!is_int($row['id']??null)||($row['leaseOwner']??null)!==$owner||!is_int($row['leaseExpires']??null)){
      throw new RuntimeException('business_gorge_lease_shape_refused');
    }
    if($row['id']===$target){if($found!==null){throw new RuntimeException('business_gorge_duplicate_lease');}$found=$row;}
    else{governance_business_gorge_http($queue,'resolve',array('taskID'=>$row['id'],'leaseOwner'=>$owner,'leaseExpires'=>$row['leaseExpires'],'outcome'=>'yield','duration'=>0),$owner);}
  }
  if($found===null){throw new RuntimeException('business_gorge_target_not_leased');}
  return $found;
}
