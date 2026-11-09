<?php
/** Callable physical SQL compatibility cases. Not business provider wiring. */
function governance_recovery_borrowed_oauth_authorization_obligations(){
  $out=array();foreach(array('INSERT','UPDATE')as$operation){foreach(array('commit','rollback')as$ending){foreach(array(
    'borrowed-oauth-authorization-owner-held-MDL-and-InnoDB-',
    'borrowed-oauth-authorization-native-SAVE-waits-on-real-store-owner-',
    'borrowed-oauth-authorization-owner-terminal-native-producer-resumes-',
    'borrowed-oauth-authorization-next-store-event-new-oauth-authorization-binding-')as$prefix){$out[]=$prefix.strtolower($operation).'-'.$ending;}}}
  $out=array_merge($out,array('oauth-authorization-Store-submit-binds-real-five-source-readset','oauth-authorization-Store-repeat-preview-independent-review-blocked','oauth-authorization-native-empty-scope-UPDATE-refuses-old-durable-review','oauth-authorization-Store-execute-still-requires-all13'));
  sort($out,SORT_STRING);return$out;
}
/** Real durable Store, real stopped subject and native empty-scope UPDATE. */
function governance_recovery_oauth_authorization_store_cases($fixture,array$state,callable$record){
  require_once __DIR__.'/restore-review-cases.php';
  $fixture->assertOwned();$requester=$state['issuer_auth'];$approver=$state['owner_auth'];$subject=$state['subjects']['commit'];
  if($requester['context']->getUserPHID()===$approver['context']->getUserPHID()){throw new RuntimeException('oauth_authorization_independent_admin_required');}
  $conn=(new PhabricatorUser())->establishConnection('w');if($conn->isInsideTransaction()){throw new RuntimeException('oauth_authorization_store_outer_transaction');}
  $client=PhabricatorOAuthServerClient::initializeNewClient($requester['viewer'])->setName('Synthetic durable OAuth authorization')->setRedirectURI('https://fixture.invalid/oauth/')->save();
  $row=(new PhabricatorOAuthServer())->setUser($subject)->setClient($client)->authorizeClient(array());
  (new PhabricatorUserTransactionEditor())->setActor($requester['viewer'])
    ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
    ->applyTransactions($subject,array((new PhabricatorUserTransaction())->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue(true)));
  if($conn->isInsideTransaction()){throw new RuntimeException('oauth_authorization_stop_not_committed');}
  $event=queryfx_one($conn,'SELECT id FROM %R WHERE BINARY objectPHID=BINARY %s AND transactionType=%s ORDER BY id DESC LIMIT 1',new PhabricatorUserTransaction(),$subject->getPHID(),PhabricatorUserDisableTransaction::TRANSACTIONTYPE);
  if(!$event){throw new RuntimeException('oauth_authorization_stop_missing');}$stop=(string)$event['id'];$suffix=substr($fixture->getNamespace(),-10);
  PhorgeRecoveryStopService::recordAlreadyStopped($requester['context'],$subject->getPHID(),$stop,'technical-pause','Synthetic authorization source','oauth-authz-stop-'.$suffix);
  governance_recovery_restore_prime_space_cache($fixture,$conn,array($requester['viewer'],$approver['viewer'],$subject));
  $fingerprint=static function()use($conn){$rows=array();foreach(array('recovery_restore_request','recovery_restore_operation','recovery_restore_audit')as$table){$rows[$table]=queryfx_all($conn,'SELECT * FROM %T ORDER BY 1',$table);}return hash('sha256',phutil_json_encode($rows));};
  $input=array('native_stop_transaction'=>$stop,'operation_id'=>'oauth-authz-submit-'.$suffix,'reason'=>'Synthetic durable authorization','subject'=>$subject->getPHID(),'ttl_seconds'=>600);
  $receipt=PhorgeRecoveryRestoreService::submit($requester['context'],$input);$id=$receipt['request_id'];
  $stored=queryfx_one($conn,'SELECT bindingJSON FROM recovery_restore_request WHERE requestID=%s',$id);$binding=json_decode($stored['bindingJSON'],true,32,JSON_THROW_ON_ERROR);
  $readset=$binding['sources']['credential_readset'];
  $record($binding['sources']['schema_version']===6&&$readset['provider_schema']===3&&count($readset['sources'])===5&&
    is_string($readset['sources']['oauth-authorization']['source_digest'])&&$readset['complete']===false&&
    $receipt['review_state']==='submitted-blocked'&&$receipt['restoration_authorized']===false,'oauth-authorization-Store-submit-binds-real-five-source-readset');
  $before=$fingerprint();$replay=PhorgeRecoveryRestoreService::submit($requester['context'],$input);
  $preview=PhorgeRecoveryRestoreService::preview($approver['context'],$id,'1');$repeat=PhorgeRecoveryRestoreService::preview($approver['context'],$id,'1');
  if($replay['replayed']!==true||$before!==$fingerprint()||$preview!==$repeat||$preview['source_schema_version']!==6||count($preview['incomplete_families'])!==13||$preview['preview_is_authorization']!==false){throw new RuntimeException('oauth_authorization_Store_replay_or_preview_unproved');}
  $review=PhorgeRecoveryRestoreService::review($approver['context'],$id,'1','oauth-authz-review-'.$suffix);
  $reviewed=queryfx_one($conn,'SELECT reviewState,permitState,version,approverPHID FROM recovery_restore_request WHERE requestID=%s',$id);
  $record($review['review_state']==='reviewed-blocked'&&$review['restoration_authorized']===false&&is_array($reviewed)&&array_keys($reviewed)===array('reviewState','permitState','version','approverPHID')&&$reviewed['reviewState']==='reviewed-blocked'&&$reviewed['permitState']==='blocked'&&(string)$reviewed['version']==='2'&&$reviewed['approverPHID']===$approver['context']->getUserPHID(),'oauth-authorization-Store-repeat-preview-independent-review-blocked');
  $oldModified=(int)$row->getDateModified();$deadline=microtime(true)+3;while(time()<=$oldModified&&microtime(true)<$deadline){usleep(20000);}
  if(time()<=$oldModified){throw new RuntimeException('oauth_authorization_native_clock_not_advanced');}
  $row->setScope(array())->save();if((int)$row->getDateModified()<=$oldModified||$row->getScope()!==array()){throw new RuntimeException('oauth_authorization_native_update_missing');}
  $before=$fingerprint();$stale=false;
  try{PhorgeRecoveryRestoreService::review($approver['context'],$id,'2','oauth-authz-stale-'.$suffix);}catch(RuntimeException$error){if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='recovery_restore_sources_stale'){throw$error;}$stale=true;}
  $record($stale&&!$conn->isInsideTransaction()&&$before===$fingerprint(),'oauth-authorization-native-empty-scope-UPDATE-refuses-old-durable-review');
  $input['operation_id']='oauth-authz-fresh-'.$suffix;$fresh=PhorgeRecoveryRestoreService::submit($requester['context'],$input);
  PhorgeRecoveryRestoreService::review($approver['context'],$fresh['request_id'],'1','oauth-authz-fresh-review-'.$suffix);$before=$fingerprint();$blocked=false;
  try{PhorgeRecoveryRestoreService::execute($requester['context'],$fresh['request_id'],'2');}catch(RuntimeException$error){if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='recovery_restore_sources_incomplete'){throw$error;}$blocked=true;}
  $record($blocked&&!$conn->isInsideTransaction()&&$before===$fingerprint(),'oauth-authorization-Store-execute-still-requires-all13');
}

function governance_recovery_borrowed_oauth_authorization_cases($fixture,mysqli $control,array $state,callable $check){
  $fixture->assertOwned();require_once __DIR__.'/borrowed-session-observer.php';require_once __DIR__.'/oauth-authorization-producer.php';require_once __DIR__.'/oauth-authorization-diagnostic.php';require_once __DIR__.'/session-lease-cases.php';
  require_once dirname(__DIR__).'/native-organization/oauth-authorization-workers.php';
  $actor=$state['issuer_auth'];$context=$state['owner_auth']['context'];
  if($actor['context']->getUserPHID()===$context->getUserPHID()||in_array($actor['context']->getUserPHID(),array_map(static fn($u)=>$u->getPHID(),$state['subjects']),true)){throw new RuntimeException('borrowed_issuer_in_parent_lockset');}
  foreach($state['subjects']as$subject){if(strcmp($actor['context']->getUserPHID(),$subject->getPHID())>=0){throw new RuntimeException('borrowed_issuer_lock_order_unknown');}}
  if(get_class($context)!==PhorgeGovernanceNativeActorContext::class){throw new RuntimeException('borrowed_native_actor_required');}
  $store=new ReflectionMethod(PhorgeRecoveryRestoreService::class,'withTransaction');
  $identities=array();$executed=array();$record=static function($ok,$label)use($check,&$executed){
    if($ok!==true||!in_array($label,governance_recovery_borrowed_oauth_authorization_obligations(),true)||isset($executed[$label])){throw new RuntimeException('borrowed_case_failed');}
    $check(true,$label);$executed[$label]=true;
  };
  governance_recovery_borrowed_with_observer($fixture,$control,static function($observer)use($fixture,$state,$actor,$context,$store,$record,&$identities){
    foreach(array('INSERT','UPDATE')as$operation){foreach(array('commit','rollback')as$ending){
      $subject=$state['subjects'][$ending];
      if(get_class($subject)!==PhabricatorUser::class){throw new RuntimeException('borrowed_native_subject_required');}
      $owner_original=queryfx_one((new PhabricatorUser())->establishConnection('w'),'SELECT realName FROM %R WHERE id=%d',new PhabricatorUser(),$subject->getID())['realName'];
      $owner_probe='Synthetic owner '.$operation.' '.$ending;
      $handles=array();$handle=null;$before=null;$returned=null;$primary=null;$rollback=new RuntimeException('borrowed_intentional_owner_rollback');
      try{
        $preflightWriter=(new PhabricatorUser())->establishConnection('w');
        $handle=governance_oauth_authorization_native_start_worker($fixture,array('action'=>'recovery.oauth-authorization.borrowed-owner.save','operation'=>$operation,'subject'=>$subject->getPHID(),'token'=>$actor['token']));$handles[]=$handle;
        try{governance_oauth_authorization_native_wait_borrowed_oauth_authorization_preflight($handle,$observer);}
        catch(Throwable$error){
          require_once __DIR__.'/borrowed-session-diagnostic.php';
          try{$GLOBALS['governance_oauth_authorization_worker_diagnostic']=governance_recovery_oauth_authorization_child_diagnostic($handle);}
          catch(Throwable$diagnostic){$GLOBALS['governance_oauth_authorization_worker_diagnostic']=array('child_available'=>false);}
          throw$error;
        }
        try{
          $returned=$store->invoke(null,$context,array($subject->getPHID()),static function($conn)use($fixture,$observer,$subject,$actor,$ending,$operation,$record,&$handles,&$handle,&$before,$rollback,$preflightWriter,$owner_probe){
            if($conn!==$preflightWriter){throw new RuntimeException('borrowed_preflight_writer_changed');}
            // Fixed isolated data probe distinguishes the owner COMMIT/ROLLBACK.
            // The credential producer itself still uses the real native DAO.
            queryfx($conn,'UPDATE %R SET realName=%s WHERE id=%d',new PhabricatorUser(),$owner_probe,$subject->getID());
            $before=PhorgeRecoveryRestoreService::captureBorrowedOAuthAuthorizationFacts($conn,$subject->getPHID());
            $writer=queryfx_one($conn,'SELECT CAST(CONNECTION_ID() AS CHAR) id')['id'];
            $table=new PhabricatorOAuthClientAuthorization();
            $instrument=queryfx_one($observer,'SELECT ENABLED FROM performance_schema.setup_instruments WHERE NAME=%s','wait/lock/metadata/sql/mdl');
            $mdl=queryfx_all($observer,'SELECT m.OBJECT_TYPE,m.OBJECT_SCHEMA,m.OBJECT_NAME,m.LOCK_DURATION,m.LOCK_STATUS FROM performance_schema.metadata_locks m JOIN performance_schema.threads t ON t.THREAD_ID=m.OWNER_THREAD_ID WHERE t.PROCESSLIST_ID=%s AND m.OBJECT_TYPE=%s AND m.OBJECT_SCHEMA=%s AND m.OBJECT_NAME=%s',$writer,'TABLE',$fixture->getNamespace().'_oauth_server',$table->getTableName());
            $granted=false;foreach($mdl as$row){if($row===array('OBJECT_TYPE'=>'TABLE','OBJECT_SCHEMA'=>$fixture->getNamespace().'_oauth_server','OBJECT_NAME'=>$table->getTableName(),'LOCK_DURATION'=>'TRANSACTION','LOCK_STATUS'=>'GRANTED')){$granted=true;}}
            // This query is deliberately AFTER actual capture's MDL acquisition.
            $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$fixture->getNamespace().'_oauth_server',$table->getTableName());
            $record($instrument===array('ENABLED'=>'YES')&&$granted&&$engine===array('ENGINE'=>'InnoDB'),'borrowed-oauth-authorization-owner-held-MDL-and-InnoDB-'.strtolower($operation).'-'.$ending);
            governance_oauth_authorization_native_continue_borrowed_oauth_authorization($handle);
            $deadline=microtime(true)+5;$waiting=false;
            do{
              $rows=queryfx_all($observer,'SELECT CAST(r.PROCESSLIST_ID AS CHAR) requester,CAST(b.PROCESSLIST_ID AS CHAR) blocker,l.OBJECT_SCHEMA schema_name,l.OBJECT_NAME table_name,l.INDEX_NAME index_name,l.LOCK_STATUS status,l.LOCK_MODE mode,l.LOCK_DATA lock_data '.
                'FROM performance_schema.data_lock_waits w JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID '.
                'JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID WHERE l.ENGINE=%s AND w.ENGINE=%s AND r.PROCESSLIST_ID=%s AND b.PROCESSLIST_ID=%s','INNODB','INNODB',$handle['mysql_thread'],$writer);
              $waiting=count($rows)===1&&$rows[0]['requester']===(string)$handle['mysql_thread']&&$rows[0]['blocker']===$writer&&
                $rows[0]['schema_name']===$fixture->getNamespace().'_user'&&$rows[0]['table_name']==='user'&&$rows[0]['index_name']==='PRIMARY'&&
                $rows[0]['lock_data']===(string)$subject->getID()&&$rows[0]['status']==='WAITING'&&str_starts_with($rows[0]['mode'],'S');
              if($waiting){break;}usleep(20000);
            }while(microtime(true)<$deadline);
            $record($waiting&&$before===PhorgeRecoveryRestoreService::captureBorrowedOAuthAuthorizationFacts($conn,$subject->getPHID()),'borrowed-oauth-authorization-native-SAVE-waits-on-real-store-owner-'.strtolower($operation).'-'.$ending);
            if($ending==='rollback'){throw$rollback;}
            return array('result'=>$before,'bindings'=>array(),'deadline'=>null);
          });
          if($ending!=='commit'){throw new RuntimeException('borrowed_expected_owner_rollback');}
        }catch(Throwable$error){if($ending!=='rollback'||$error!==$rollback){throw$error;}}
        $terminal=$GLOBALS['governance_recovery_borrowed_terminal']??null;
        $result=governance_oauth_authorization_native_finish_worker($handle);governance_oauth_authorization_native_threads_ended($observer,$handles);
        $conn=(new PhabricatorUser())->establishConnection('w');$owner_value=queryfx_one($conn,'SELECT realName FROM %R WHERE id=%d',new PhabricatorUser(),$subject->getID())['realName'];
        if($owner_value!==($ending==='commit'?$owner_probe:$owner_original)){throw new RuntimeException('oauth_authorization_owner_persistent_value_unproved');}
        $rows=queryfx_all($conn,'SELECT id,phid,userPHID,clientPHID,scope,dateCreated,dateModified FROM %R WHERE BINARY userPHID=BINARY %s ORDER BY id LIMIT 65',new PhabricatorOAuthClientAuthorization(),$subject->getPHID());
        $matched=array();foreach($rows as$row){if(hash('sha256',(string)$row['id'])===$result['authorization_id_digest']){$matched[]=$row;}}
        if(count($rows)>64||count($matched)!==1||$result['operation']!==$operation||
          $result['subject_id_digest']!==hash('sha256',(string)$subject->getID())||$result['subject_phid_digest']!==hash('sha256',$subject->getPHID())||
          $matched[0]['userPHID']!==$subject->getPHID()||hash('sha256',$matched[0]['clientPHID'])!==$result['client_phid_digest']||
          json_decode($matched[0]['scope'],true,8,JSON_THROW_ON_ERROR)!==array()||(int)$matched[0]['dateModified']!==$result['new_modified']){
          throw new RuntimeException('oauth_authorization_persisted_identity_unproved');}
        $identities[]=array('operation'=>$operation,'owner_end'=>$ending,'subject_id_digest'=>$result['subject_id_digest'],
          'subject_phid_digest'=>$result['subject_phid_digest'],'client_phid_digest'=>$result['client_phid_digest'],
          'authorization_id_digest'=>$result['authorization_id_digest'],'old_modified'=>$result['old_modified'],'new_modified'=>$result['new_modified'],
          'owner_persistent_probe'=>$ending==='commit'?'committed-probe':'rolled-back-original');

        $record(($GLOBALS['governance_recovery_borrowed_cleanup_proved']??null)===true&&is_array($terminal)&&
          $terminal['cleanup_proved']===true&&$terminal['state']===($ending==='commit'?'committed':'rolled-back')&&
          $result['status']==='committed'&&($ending!=='commit'||$returned===$before),
          'borrowed-oauth-authorization-owner-terminal-native-producer-resumes-'.strtolower($operation).'-'.$ending);
        $after=$store->invoke(null,$context,array($subject->getPHID()),static function($conn)use($subject){return array('result'=>PhorgeRecoveryRestoreService::captureBorrowedOAuthAuthorizationFacts($conn,$subject->getPHID()),'bindings'=>array(),'deadline'=>null);});
        $record($before['source_binding']!==$after['source_binding']&&$after['cross_connection_atomicity_proved']===false&&$after['restoration_authorized']===false,
          'borrowed-oauth-authorization-next-store-event-new-oauth-authorization-binding-'.strtolower($operation).'-'.$ending);
        // Restore this display-only test probe only after both writers and
        // child exit are known. No reset appears on any failure/unknown path.
        if($ending==='commit'){queryfx($conn,'UPDATE %R SET realName=%s WHERE id=%d',new PhabricatorUser(),$owner_original,$subject->getID());}
      }catch(Throwable$error){$GLOBALS['governance_recovery_borrowed_cleanup_proved']=false;$primary=$error;
        if($handle!==null){$GLOBALS['governance_oauth_authorization_worker_diagnostic']=governance_recovery_oauth_authorization_child_diagnostic($handle);}
        throw$error;}
      finally{
        $errors=$primary===null?array():array('primary'=>$primary);
        try{governance_oauth_authorization_native_stop_fixture_handles($handles);}catch(Throwable$error){$errors['workers']=$error;}
        try{governance_oauth_authorization_native_threads_ended($observer,$handles);}catch(Throwable$error){$GLOBALS['governance_recovery_borrowed_cleanup_proved']=false;$errors['worker-SQL']=$error;}
        if(count($errors)>($primary===null?0:1)){throw new PhutilAggregateException('borrowed_physical_case_cleanup_failed',$errors);}
      }
    }}
    governance_recovery_oauth_authorization_store_cases($fixture,$state,$record);
  });
  $labels=array_keys($executed);sort($labels,SORT_STRING);
  if($labels!==governance_recovery_borrowed_oauth_authorization_obligations()||($GLOBALS['governance_recovery_borrowed_fixture_cleanup']['cleanup_proved']??null)!==true){throw new RuntimeException('borrowed_complete_case_set_required');}
  return array('profile'=>'isolated-borrowed-real-store-oauth-authorization-owner-v1-dual-writer','oauth_authorization_only_native_producer'=>true,'executed_experiments'=>4,'source_identities'=>$identities,'native_scope_registry_unchanged'=>true,'both_owned_native_SQL_threads_observed'=>true,'owned_child_exit0_proved'=>true,'child_preflight_committed_before_owner'=>true,'child_preflight_no_record_locks_observed'=>true,'continuous_admin_lease_proved'=>false,'old_covering_positive_query_used'=>false,'executed_case_obligations'=>$labels,
    'real_store_owner_and_native_oauth_authorization_INSERT_UPDATE'=>true,'participant_owns_transaction'=>false,
    'production_observer_factory_available'=>false,'business_submit_review_integration_proved'=>true,
    'durable_review_compatible'=>true,'Task_File_RR_combined'=>false,'all_sources_complete'=>false,
    'restoration_authorized'=>false,'execution_blocked'=>true,'all_13_sources_complete'=>false,'permit'=>false);
}
