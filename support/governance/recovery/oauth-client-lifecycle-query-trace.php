<?php

/** Fixture-only query timing observation. Does not prove a granted record lock. */
function governance_oauth_client_lifecycle_query_trace($fixture,
  PhabricatorOAuthServerClient $client, $operation, callable $first_query_returned) {
  if(get_class($fixture)!==PhorgeGovernanceCompletionFixture::class||
    get_class($client)!==PhabricatorOAuthServerClient::class){
    throw new RuntimeException('oauth_client_lifecycle_trace_scope_unknown');}
  $fixture->assertOwned();$namespace=$fixture->getNamespace();
  if(!is_string($namespace)||preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)!==1||
    !preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$client->getID())||
    (float)$client->getID()>4294967295||!is_string($client->getPHID())||
    preg_match('/\APHID-OASC-[a-zA-Z0-9]{20}\z/D',$client->getPHID())!==1){
    throw new RuntimeException('oauth_client_lifecycle_trace_identity_unknown');}
  if(!in_array($operation,array('disabled','redirect','trust','secret','authorization-delete','client-cascade'),true)){throw new RuntimeException('oauth_client_lifecycle_operation_unknown');}
  $pause_kind=$operation==='authorization-delete'?'authorization':'client';
  $tables=array('client'=>$client->getTableName(),
    'authorization'=>(new PhabricatorOAuthClientAuthorization())->getTableName(),
    'access'=>(new PhabricatorOAuthServerAccessToken())->getTableName(),
    'code'=>(new PhabricatorOAuthServerAuthorizationCode())->getTableName());
  $state=(object)array('active'=>false,'first_returned'=>false,
    'paused'=>false,'events'=>array(),'pending'=>array(),'unknown'=>false);
  // This listener never copies query/config/arguments to state or output. The
  // profiler's own transient process memory remains subject to its native rules.
  PhutilServiceProfiler::getInstance()->addListener(
    static function($phase,$id,array$data)use($state,$tables,$namespace,$client,$pause_kind,$first_query_returned){
      if(!$state->active){return;}
      if(($data['type']??null)!=='query'){return;}
      $query=$data['query']??null;
      if(!is_string($query)||strlen($query)>262144){$state->unknown=true;return;}
      $kind=null;
      foreach($tables as$name=>$table){
        if(str_contains($query,'FROM `'.$namespace.'_oauth_server`.`'.$table.'`')){$kind=$name;break;}}
      if($kind===null||!str_ends_with($query,'LOCK IN SHARE MODE')||
        preg_match('/\bWHERE 1\s*=\s*0\b/',$query)){return;}
      if($phase==='begin'){
        if(!is_int($id)||isset($state->pending[$id])||count($state->events)>=256){
          $state->unknown=true;return;}
        if($kind==='client'){
          if(!str_contains($query,'FORCE INDEX (PRIMARY)')||
            !str_contains($query,'id IN ('.(string)$client->getID().')')||
            !str_contains($query,$client->getPHID())){$state->unknown=true;return;}
        }else if(!$state->first_returned){$state->unknown=true;}
        $state->pending[$id]=$kind;$state->events[]=array('phase'=>'begin','kind'=>$kind);
      }else if($phase==='end'){
        if(!isset($state->pending[$id])||$state->pending[$id]!==$kind){$state->unknown=true;return;}
        unset($state->pending[$id]);$state->events[]=array('phase'=>'end','kind'=>$kind);
        if($kind==='client'){$state->first_returned=true;}
        if($kind===$pause_kind&&!$state->paused){
          $state->paused=true;
          // Native query-end alone is NOT success/lock proof. The caller must
          // independently observe exact granted client PRIMARY S and two IDs
          // before it may assert that the lifecycle writer is blocked by it.
          $first_query_returned();
        }
      }else{$state->unknown=true;}
    });
  return$state;
}
