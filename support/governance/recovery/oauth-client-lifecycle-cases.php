<?php
/** Real SQL cases; a source definition is not a completed runtime result. */
require_once __DIR__.'/oauth-client-lifecycle-producer.php';
require_once __DIR__.'/oauth-client-lifecycle-query-trace.php';
function governance_recovery_oauth_client_lifecycle_obligations(){
  $out=array();foreach(governance_oauth_client_lifecycle_operations()as$operation){foreach(array('commit','rollback')as$ending){foreach(array('client-first-and-granted-record','native-writer-exact-record-wait','owner-terminal-writer-native-postcondition','next-store-api-binding-changed')as$kind){$out[]='oauth-client-lifecycle-'.$operation.'-'.$ending.'-'.$kind;}}}
  return array_merge($out,array('oauth-client-lifecycle-durable-submit-five-sources','oauth-client-lifecycle-durable-replay-review-blocked','oauth-client-lifecycle-native-trust-save-invalidates-review','oauth-client-lifecycle-execute-requires-complete-sources'));
}
function governance_recovery_oauth_client_lifecycle_prepare($actor,$subject){
  if(PhabricatorOAuthServerScope::getScopeMap()!==array()){throw new RuntimeException('oauth_client_lifecycle_scope_registry_changed');}
  $client=PhabricatorOAuthServerClient::initializeNewClient($actor)->setName('Synthetic lifecycle '.bin2hex(random_bytes(8)))->setRedirectURI('https://fixture.invalid/lifecycle/original/')->save();
  $server=(new PhabricatorOAuthServer())->setUser($subject)->setClient($client);
  $authorization=$server->authorizeClient(array());$access=$server->generateAccessToken();$code=$server->generateAuthorizationCode(new PhutilURI($client->getRedirectURI()));
  return array($client,$authorization,$access,$code);
}
/** Exact actual record observation, not permission to kill a session. */
function governance_oauth_client_lifecycle_granted_share($observer,$thread,$namespace,$table,$id){
  $rows=queryfx_all($observer,'SELECT l.LOCK_TYPE,l.LOCK_MODE,l.LOCK_STATUS,l.LOCK_DATA FROM performance_schema.data_locks l JOIN performance_schema.threads t ON t.THREAD_ID=l.THREAD_ID WHERE l.ENGINE=%s AND t.PROCESSLIST_ID=%s AND l.OBJECT_SCHEMA=%s AND l.OBJECT_NAME=%s AND l.INDEX_NAME=%s AND l.LOCK_DATA=%s','INNODB',$thread,$namespace.'_oauth_server',$table,'PRIMARY',(string)$id);
  foreach($rows as$row){if($row['LOCK_TYPE']==='RECORD'&&in_array($row['LOCK_MODE'],array('S','S,REC_NOT_GAP'),true)&&$row['LOCK_STATUS']==='GRANTED'&&$row['LOCK_DATA']===(string)$id){return true;}}
  return false;
}
function governance_recovery_oauth_client_lifecycle_cases($fixture,mysqli$control,array$state,callable$check){
  $fixture->assertOwned();require_once __DIR__.'/borrowed-session-observer.php';require_once __DIR__.'/session-lease-cases.php';
  require_once dirname(__DIR__).'/native-organization/oauth-client-lifecycle-workers.php';
  $actor=$state['issuer_auth'];$context=$state['owner_auth']['context'];
  if($actor['context']->getUserPHID()===$context->getUserPHID()){throw new RuntimeException('oauth_client_lifecycle_independent_admin_required');}
  $store=new ReflectionMethod(PhorgeRecoveryRestoreService::class,'withTransaction');$executed=array();$identities=array();
  $allowed=governance_recovery_oauth_client_lifecycle_obligations();
  $record=static function($ok,$label)use($check,&$executed,$allowed){
    if(!in_array($label,$allowed,true)||isset($executed[$label])){throw new RuntimeException('oauth_client_lifecycle_label_unknown');}
    // Record the fixed attempted label even on failure; never report last success.
    $GLOBALS['governance_oauth_client_lifecycle_attempt']=$label;$check($ok===true,$label);$executed[$label]=true;
  };
  governance_recovery_borrowed_with_observer($fixture,$control,static function($observer)use($fixture,$state,$actor,$context,$store,$record,&$identities){
    foreach(governance_oauth_client_lifecycle_operations()as$operation){foreach(array('commit','rollback')as$ending){
      $subject=$state['subjects'][$ending];list($client,$authorization)=governance_recovery_oauth_client_lifecycle_prepare($actor['viewer'],$subject);
      $clientID=(int)$client->getID();$authorizationID=(int)$authorization->getID();$label='oauth-client-lifecycle-'.$operation.'-'.$ending.'-';
      $writer=(new PhabricatorUser())->establishConnection('w');$original=queryfx_one($writer,'SELECT realName FROM %R WHERE id=%d',new PhabricatorUser(),$subject->getID())['realName'];
      $probe='Synthetic lifecycle owner '.$operation.' '.$ending;$handle=null;$handles=array();$before=null;$trace=null;$primary=null;
      $rollback=new RuntimeException('oauth_client_lifecycle_intentional_rollback');
      $workerIndex=count($GLOBALS['governance_oauth_client_lifecycle_native_workers']);
      try{
        $handle=governance_oauth_client_lifecycle_native_start_worker($fixture,array('action'=>'recovery.oauth-client-lifecycle.borrowed-owner.save','operation'=>$operation,'client'=>$client->getPHID(),'subject'=>$subject->getPHID(),'token'=>$actor['token']));$handles[]=$handle;
        governance_oauth_client_lifecycle_native_wait_borrowed_oauth_client_lifecycle_preflight($handle,$observer);
        try{
          $returned=$store->invoke(null,$context,array($subject->getPHID()),static function($conn)use($fixture,$observer,$subject,$context,$operation,$ending,$record,$label,$client,$clientID,$authorization,$authorizationID,&$handle,&$trace,&$before,$rollback,$probe){
            queryfx($conn,'UPDATE %R SET realName=%s WHERE id=%d',new PhabricatorUser(),$probe,$subject->getID());
            $parent=queryfx_one($conn,'SELECT CAST(CONNECTION_ID() AS CHAR) id')['id'];
            if($parent===(string)$handle['mysql_thread']||$parent===(string)$handle['oauth_mysql_thread']){throw new RuntimeException('oauth_client_lifecycle_parent_thread_alias');}
            $trace=governance_oauth_client_lifecycle_query_trace($fixture,$client,$operation,static function()use($fixture,$observer,$operation,$record,$label,$parent,$client,$clientID,$authorization,$authorizationID,&$handle){
              $table=$operation==='authorization-delete'?$authorization->getTableName():$client->getTableName();$id=$operation==='authorization-delete'?$authorizationID:$clientID;
              $record(governance_oauth_client_lifecycle_granted_share($observer,$parent,$fixture->getNamespace(),$client->getTableName(),$clientID)&&
                governance_oauth_client_lifecycle_granted_share($observer,$parent,$fixture->getNamespace(),$table,$id),$label.'client-first-and-granted-record');
              governance_oauth_client_lifecycle_native_continue_borrowed_oauth_client_lifecycle($handle);
              $deadline=microtime(true)+5;$waiting=false;
              do{
                $rows=queryfx_all($observer,'SELECT CAST(r.PROCESSLIST_ID AS CHAR) requester,CAST(b.PROCESSLIST_ID AS CHAR) blocker,l.OBJECT_SCHEMA schema_name,l.OBJECT_NAME table_name,l.INDEX_NAME index_name,l.LOCK_TYPE type,l.LOCK_STATUS status,l.LOCK_MODE mode,l.LOCK_DATA lock_data FROM performance_schema.data_lock_waits w JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID WHERE l.ENGINE=%s AND w.ENGINE=%s AND r.PROCESSLIST_ID=%s AND b.PROCESSLIST_ID=%s','INNODB','INNODB',$handle['oauth_mysql_thread'],$parent);
                $waiting=count($rows)===1&&$rows[0]['requester']===(string)$handle['oauth_mysql_thread']&&$rows[0]['blocker']===$parent&&$rows[0]['schema_name']===$fixture->getNamespace().'_oauth_server'&&$rows[0]['table_name']===$table&&$rows[0]['index_name']==='PRIMARY'&&$rows[0]['type']==='RECORD'&&$rows[0]['status']==='WAITING'&&str_starts_with($rows[0]['mode'],'X')&&$rows[0]['lock_data']===(string)$id;
                if($waiting){break;}usleep(20000);
              }while(microtime(true)<$deadline);
              $record($waiting,$label.'native-writer-exact-record-wait');
            });
            $trace->active=true;
            try{
              $readset=PhorgeRecoveryRestoreService::captureAssessmentCredentialSources($conn,$subject->getPHID());
              $before=PhorgeRecoveryNativeCaptureScope::captureForRestoration($context,$conn,$subject->getPHID());
            }finally{$trace->active=false;}
            if($trace->unknown||!$trace->first_returned||!$trace->paused||$trace->pending||count($readset['sources'])!==5||$readset['complete']!==false){throw new RuntimeException('oauth_client_lifecycle_native_trace_unknown');}
            if($ending==='rollback'){throw$rollback;}
            return array('result'=>$before,'bindings'=>array(),'deadline'=>null);
          });
          if($ending!=='commit'||$returned!==$before){throw new RuntimeException('oauth_client_lifecycle_owner_result_unknown');}
        }catch(Throwable$error){if($ending!=='rollback'||$error!==$rollback){throw$error;}}
        $terminal=$GLOBALS['governance_recovery_borrowed_terminal']??null;
        $result=governance_oauth_client_lifecycle_native_finish_worker($handle);governance_oauth_client_lifecycle_native_threads_ended($observer,$handles);
        $value=queryfx_one($writer,'SELECT realName FROM %R WHERE id=%d',new PhabricatorUser(),$subject->getID())['realName'];
        $record(is_array($terminal)&&$terminal['cleanup_proved']===true&&$terminal['state']===($ending==='commit'?'committed':'rolled-back')&&
          $value===($ending==='commit'?$probe:$original)&&$result['operation']===$operation&&$result['client_id_digest']===hash('sha256',(string)$clientID)&&
          $result['authorization_id_digest']===hash('sha256',(string)$authorizationID)&&$result['subject_id_digest']===hash('sha256',(string)$subject->getID())&&
          $result['subject_phid_digest']===hash('sha256',$subject->getPHID())&&$result['client_phid_digest']===hash('sha256',$client->getPHID()),$label.'owner-terminal-writer-native-postcondition');
        $after=$store->invoke(null,$context,array($subject->getPHID()),static function($conn)use($subject,$context){
          PhorgeRecoveryRestoreService::captureAssessmentCredentialSources($conn,$subject->getPHID());
          return array('result'=>PhorgeRecoveryNativeCaptureScope::captureForRestoration($context,$conn,$subject->getPHID()),'bindings'=>array(),'deadline'=>null);
        });
        $record(is_array($before)&&$before['api_storage_digest']!==$after['api_storage_digest']&&$after['complete']===false&&$after['restoration_authorized']===false,$label.'next-store-api-binding-changed');
        $identities[]=array('operation'=>$operation,'owner_end'=>$ending,'subject_id_digest'=>$result['subject_id_digest'],'subject_phid_digest'=>$result['subject_phid_digest'],'client_id_digest'=>$result['client_id_digest'],'client_phid_digest'=>$result['client_phid_digest'],'authorization_id_digest'=>$result['authorization_id_digest'],'before_client_digest'=>$result['before_client_digest'],'after_client_digest'=>$result['after_client_digest'],'remaining_rows'=>$result['remaining_rows'],'owner_persistent_probe'=>$ending==='commit'?'committed-probe':'rolled-back-original','query_order'=>$trace->events);
        // Success only, after both physical child connections and owner ended.
        if($ending==='commit'){queryfx($writer,'UPDATE %R SET realName=%s WHERE id=%d',new PhabricatorUser(),$original,$subject->getID());}
        $remaining=(new PhabricatorOAuthServerClient())->load($clientID);if($remaining){$remaining->destroyObjectPermanently(new PhabricatorDestructionEngine());}
        if((new PhabricatorOAuthServerClient())->load($clientID)){throw new RuntimeException('oauth_client_lifecycle_owned_client_cleanup_unproved');}
      }catch(Throwable$error){
        $GLOBALS['governance_recovery_borrowed_cleanup_proved']=false;$primary=$error;
        require_once __DIR__.'/oauth-client-lifecycle-diagnostic.php';
        $failed=$handle??($GLOBALS['governance_oauth_client_lifecycle_native_workers'][$workerIndex]??null);
        if(is_array($failed)){try{$GLOBALS['governance_oauth_client_lifecycle_worker_diagnostic']=governance_recovery_oauth_client_lifecycle_child_diagnostic($failed);}catch(Throwable$ignored){$GLOBALS['governance_oauth_client_lifecycle_worker_diagnostic']=array('child_available'=>false);}}
        throw$error;
      }
      finally{
        if($trace!==null){$trace->active=false;}
        // No TERM, retry, rollback or cleanup on failed/unknown source outcomes.
        if($primary===null){governance_oauth_client_lifecycle_native_stop_fixture_handles($handles);governance_oauth_client_lifecycle_native_threads_ended($observer,$handles);}
      }
    }}
    governance_recovery_oauth_client_lifecycle_store_cases($fixture,$state,$record);
  });
  $labels=array_keys($executed);$expected=governance_recovery_oauth_client_lifecycle_obligations();sort($labels,SORT_STRING);sort($expected,SORT_STRING);
  if($labels!==$expected||($GLOBALS['governance_recovery_borrowed_fixture_cleanup']['cleanup_proved']??null)!==true){throw new RuntimeException('oauth_client_lifecycle_complete_cases_required');}
  return array('profile'=>'isolated-native-oauth-client-lifecycle-v1','executed_experiments'=>12,'executed_case_obligations'=>$labels,'source_identities'=>$identities,'native_scope_registry_unchanged'=>true,'real_Store_owner_and_native_lifecycle'=>true,'native_query_order_observed'=>true,'exact_record_lock_wait_observed'=>true,'both_owned_native_SQL_threads_observed'=>true,'owned_child_exit0_proved'=>true,'production_observer_factory_available'=>false,'continuous_admin_lease_proved'=>false,'physical_ACK_loss_proved'=>false,'all_13_sources_complete'=>false,'permit'=>false,'restoration_authorized'=>false,'execution_blocked'=>true);
}

function governance_recovery_oauth_client_lifecycle_store_cases($fixture,array$state,callable$record){
  require_once __DIR__.'/restore-review-cases.php';
  $fixture->assertOwned();$requester=$state['issuer_auth'];$approver=$state['owner_auth'];$subject=$state['subjects']['commit'];
  if($requester['context']->getUserPHID()===$approver['context']->getUserPHID()){throw new RuntimeException('oauth_client_lifecycle_independent_admin_required');}
  $conn=(new PhabricatorUser())->establishConnection('w');if($conn->isInsideTransaction()){throw new RuntimeException('oauth_client_lifecycle_store_outer_transaction');}
  $GLOBALS['governance_oauth_client_lifecycle_attempt']='durable-prepare-client';
  $client=PhabricatorOAuthServerClient::initializeNewClient($requester['viewer'])->setName('Synthetic lifecycle durable')->setRedirectURI('https://fixture.invalid/lifecycle/durable/')->save();
  $row=(new PhabricatorOAuthServer())->setUser($subject)->setClient($client)->authorizeClient(array());
  $GLOBALS['governance_oauth_client_lifecycle_attempt']='durable-disable-subject';
  (new PhabricatorUserTransactionEditor())->setActor($requester['viewer'])
    ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
    ->applyTransactions($subject,array((new PhabricatorUserTransaction())->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue(true)));
  if($conn->isInsideTransaction()){throw new RuntimeException('oauth_client_lifecycle_stop_not_committed');}
  $event=queryfx_one($conn,'SELECT id FROM %R WHERE BINARY objectPHID=BINARY %s AND transactionType=%s ORDER BY id DESC LIMIT 1',new PhabricatorUserTransaction(),$subject->getPHID(),PhabricatorUserDisableTransaction::TRANSACTIONTYPE);
  if(!$event){throw new RuntimeException('oauth_client_lifecycle_stop_missing');}$stop=(string)$event['id'];$suffix=substr($fixture->getNamespace(),-10);
  $GLOBALS['governance_oauth_client_lifecycle_attempt']='durable-record-stop';
  PhorgeRecoveryStopService::recordAlreadyStopped($requester['context'],$subject->getPHID(),$stop,'technical-pause','Synthetic authorization source','oauth-client-life-stop-'.$suffix);
  $GLOBALS['governance_oauth_client_lifecycle_attempt']='durable-prime-spaces';
  governance_recovery_restore_prime_space_cache($fixture,$conn,array($requester['viewer'],$approver['viewer'],$subject));
  $fingerprint=static function()use($conn){$rows=array();foreach(array('recovery_restore_request','recovery_restore_operation','recovery_restore_audit')as$table){$rows[$table]=queryfx_all($conn,'SELECT * FROM %T ORDER BY 1',$table);}return hash('sha256',phutil_json_encode($rows));};
  $input=array('native_stop_transaction'=>$stop,'operation_id'=>'oauth-client-life-submit-'.$suffix,'reason'=>'Synthetic durable authorization','subject'=>$subject->getPHID(),'ttl_seconds'=>600);
  $GLOBALS['governance_oauth_client_lifecycle_attempt']='durable-submit';
  $receipt=PhorgeRecoveryRestoreService::submit($requester['context'],$input);$id=$receipt['request_id'];
  $stored=queryfx_one($conn,'SELECT bindingJSON FROM recovery_restore_request WHERE requestID=%s',$id);$binding=json_decode($stored['bindingJSON'],true,32,JSON_THROW_ON_ERROR);
  $readset=$binding['sources']['credential_readset'];
  $record($binding['sources']['schema_version']===6&&$readset['provider_schema']===3&&count($readset['sources'])===5&&
    is_string($readset['sources']['oauth-client-lifecycle']['source_digest'])&&$readset['complete']===false&&
    $receipt['review_state']==='submitted-blocked'&&$receipt['restoration_authorized']===false,'oauth-client-lifecycle-durable-submit-five-sources');
  $GLOBALS['governance_oauth_client_lifecycle_attempt']='durable-replay-preview';
  $before=$fingerprint();$replay=PhorgeRecoveryRestoreService::submit($requester['context'],$input);
  $preview=PhorgeRecoveryRestoreService::preview($approver['context'],$id,'1');$repeat=PhorgeRecoveryRestoreService::preview($approver['context'],$id,'1');
  if($replay['replayed']!==true||$before!==$fingerprint()||$preview!==$repeat||$preview['source_schema_version']!==6||count($preview['incomplete_families'])!==13||$preview['preview_is_authorization']!==false){throw new RuntimeException('oauth_client_lifecycle_Store_replay_or_preview_unproved');}
  $GLOBALS['governance_oauth_client_lifecycle_attempt']='durable-review';
  $review=PhorgeRecoveryRestoreService::review($approver['context'],$id,'1','oauth-client-life-review-'.$suffix);
  $reviewed=queryfx_one($conn,'SELECT reviewState,permitState,version,approverPHID FROM recovery_restore_request WHERE requestID=%s',$id);
  $record($review['review_state']==='reviewed-blocked'&&$review['restoration_authorized']===false&&is_array($reviewed)&&array_keys($reviewed)===array('reviewState','permitState','version','approverPHID')&&$reviewed['reviewState']==='reviewed-blocked'&&$reviewed['permitState']==='blocked'&&(string)$reviewed['version']==='2'&&$reviewed['approverPHID']===$approver['context']->getUserPHID(),'oauth-client-lifecycle-durable-replay-review-blocked');
  // An actual changed native field, not a sleep or assumed timestamp tick.
  $GLOBALS['governance_oauth_client_lifecycle_attempt']='durable-trust-save';
  $clientConnection=$client->establishConnection('w');
  $oldTrust=queryfx_one($clientConnection,'SELECT isTrusted FROM %R WHERE id=%d',new PhabricatorOAuthServerClient(),$client->getID());
  if(!$oldTrust||!in_array((string)$oldTrust['isTrusted'],array('0','1'),true)){throw new RuntimeException('oauth_client_lifecycle_trust_shape_unknown');}
  $client->setIsTrusted((int)$oldTrust['isTrusted']===0)->save();
  $newTrust=queryfx_one($clientConnection,'SELECT isTrusted FROM %R WHERE id=%d',new PhabricatorOAuthServerClient(),$client->getID());
  if(!$newTrust||(string)$newTrust['isTrusted']===(string)$oldTrust['isTrusted']||$clientConnection->isInsideTransaction()){throw new RuntimeException('oauth_client_lifecycle_native_update_missing');}
  $before=$fingerprint();$stale=false;
  $GLOBALS['governance_oauth_client_lifecycle_attempt']='durable-stale-review';
  try{PhorgeRecoveryRestoreService::review($approver['context'],$id,'2','oauth-client-life-stale-'.$suffix);}catch(RuntimeException$error){if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='recovery_restore_sources_stale'){throw$error;}$stale=true;}
  $record($stale&&!$conn->isInsideTransaction()&&$before===$fingerprint(),'oauth-client-lifecycle-native-trust-save-invalidates-review');
  $GLOBALS['governance_oauth_client_lifecycle_attempt']='durable-fresh-submit-review';
  $input['operation_id']='oauth-client-life-fresh-'.$suffix;$fresh=PhorgeRecoveryRestoreService::submit($requester['context'],$input);
  PhorgeRecoveryRestoreService::review($approver['context'],$fresh['request_id'],'1','oauth-client-life-fresh-review-'.$suffix);$before=$fingerprint();$blocked=false;
  $GLOBALS['governance_oauth_client_lifecycle_attempt']='durable-execute-incomplete';
  try{PhorgeRecoveryRestoreService::execute($requester['context'],$fresh['request_id'],'2');}catch(RuntimeException$error){if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='recovery_restore_sources_incomplete'){throw$error;}$blocked=true;}
  $record($blocked&&!$conn->isInsideTransaction()&&$before===$fingerprint(),'oauth-client-lifecycle-execute-requires-complete-sources');
}
