<?php
/** Callable physical SQL compatibility cases. Not business provider wiring. */
function governance_recovery_borrowed_session_obligations(){
  $out=array();foreach(array('commit','rollback')as$ending){foreach(array(
    'borrowed-session-native-INSERT-waits-on-real-store-owner-',
    'borrowed-session-owner-terminal-native-producer-resumes-',
    'borrowed-session-next-store-event-new-session-binding-')as$prefix){$out[]=$prefix.$ending;}}
  sort($out,SORT_STRING);return$out;
}
function governance_recovery_borrowed_session_cases($fixture,mysqli $control,array $state,callable $check){
  $fixture->assertOwned();require_once __DIR__.'/borrowed-session-observer.php';require_once __DIR__.'/session-lease-cases.php';
  require_once dirname(__DIR__).'/native-organization/workers.php';
  $actor=$state['actor'];$context=$actor['context'];
  if(get_class($context)!==PhorgeGovernanceNativeActorContext::class){throw new RuntimeException('borrowed_native_actor_required');}
  $store=new ReflectionMethod(PhorgeRecoveryRestoreService::class,'withTransaction');
  $executed=array();$record=static function($ok,$label)use($check,&$executed){
    if($ok!==true||!in_array($label,governance_recovery_borrowed_session_obligations(),true)||isset($executed[$label])){throw new RuntimeException('borrowed_case_failed');}
    $check(true,$label);$executed[$label]=true;
  };
  governance_recovery_borrowed_with_observer($fixture,$control,static function($observer)use($fixture,$state,$actor,$context,$store,$record){
    foreach(array('commit','rollback')as$ending){
      $subject=$state['subjects'][$ending];
      if(get_class($subject)!==PhabricatorUser::class){throw new RuntimeException('borrowed_native_subject_required');}
      $handles=array();$handle=null;$before=null;$returned=null;$primary=null;$rollback=new RuntimeException('borrowed_intentional_owner_rollback');
      try{
        try{
          $returned=$store->invoke(null,$context,array($subject->getPHID()),static function($conn)use($fixture,$observer,$subject,$actor,$ending,$record,&$handles,&$handle,&$before,$rollback){
            $before=PhorgeRecoveryRestoreService::captureBorrowedSessionFacts($conn,$subject->getPHID());
            $handle=governance_native_start_worker($fixture,array('action'=>'recovery.session.issue','subject'=>$subject->getPHID(),'token'=>$actor['token']));$handles[]=$handle;
            governance_native_wait_session_producer_ready($handle);governance_native_continue_session_producer($handle);
            governance_native_wait_session_covering_control($handle);governance_native_continue_session_fence($handle);
            $writer=queryfx_one($conn,'SELECT CAST(CONNECTION_ID() AS CHAR) id')['id'];$deadline=microtime(true)+5;$waiting=false;
            do{
              $rows=queryfx_all($observer,'SELECT CAST(r.PROCESSLIST_ID AS CHAR) requester,CAST(b.PROCESSLIST_ID AS CHAR) blocker,l.OBJECT_SCHEMA schema_name,l.OBJECT_NAME table_name,l.INDEX_NAME index_name,l.LOCK_STATUS status,l.LOCK_MODE mode,l.LOCK_DATA lock_data '.
                'FROM performance_schema.data_lock_waits w JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID '.
                'JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID WHERE l.ENGINE=%s AND w.ENGINE=%s AND r.PROCESSLIST_ID=%s AND b.PROCESSLIST_ID=%s','INNODB','INNODB',$handle['mysql_thread'],$writer);
              $waiting=count($rows)===1&&$rows[0]['requester']===(string)$handle['mysql_thread']&&$rows[0]['blocker']===$writer&&
                $rows[0]['schema_name']===$fixture->getNamespace().'_user'&&$rows[0]['table_name']==='user'&&$rows[0]['index_name']==='PRIMARY'&&
                $rows[0]['lock_data']===(string)$subject->getID()&&$rows[0]['status']==='WAITING'&&str_starts_with($rows[0]['mode'],'S');
              if($waiting){break;}usleep(20000);
            }while(microtime(true)<$deadline);
            $record($waiting&&$before===PhorgeRecoveryRestoreService::captureBorrowedSessionFacts($conn,$subject->getPHID()),'borrowed-session-native-INSERT-waits-on-real-store-owner-'.$ending);
            if($ending==='rollback'){throw$rollback;}
            return array('result'=>$before,'bindings'=>array(),'deadline'=>null);
          });
          if($ending!=='commit'){throw new RuntimeException('borrowed_expected_owner_rollback');}
        }catch(Throwable$error){if($ending!=='rollback'||$error!==$rollback){throw$error;}}
        $terminal=$GLOBALS['governance_recovery_borrowed_terminal']??null;
        $result=governance_native_finish_worker($handle);governance_recovery_session_lease_workers_ended($observer,$handles);
        $record(($GLOBALS['governance_recovery_borrowed_cleanup_proved']??null)===true&&is_array($terminal)&&
          $terminal['cleanup_proved']===true&&$terminal['state']===($ending==='commit'?'committed':'rolled-back')&&
          $result['status']==='committed'&&($ending!=='commit'||$returned===$before),
          'borrowed-session-owner-terminal-native-producer-resumes-'.$ending);
        $after=$store->invoke(null,$context,array($subject->getPHID()),static function($conn)use($subject){return array('result'=>PhorgeRecoveryRestoreService::captureBorrowedSessionFacts($conn,$subject->getPHID()),'bindings'=>array(),'deadline'=>null);});
        $record($before['source_binding']!==$after['source_binding']&&$after['participant_owns_transaction']===false&&$after['restoration_authorized']===false,
          'borrowed-session-next-store-event-new-session-binding-'.$ending);
      }catch(Throwable$error){$primary=$error;throw$error;}
      finally{
        $errors=$primary===null?array():array('primary'=>$primary);
        try{governance_native_stop_fixture_handles($handles);}catch(Throwable$error){$errors['workers']=$error;}
        try{governance_recovery_session_lease_workers_ended($observer,$handles);}catch(Throwable$error){$GLOBALS['governance_recovery_borrowed_cleanup_proved']=false;$errors['worker-SQL']=$error;}
        if(count($errors)>($primary===null?0:1)){throw new PhutilAggregateException('borrowed_physical_case_cleanup_failed',$errors);}
      }
    }
  });
  $labels=array_keys($executed);sort($labels,SORT_STRING);
  if($labels!==governance_recovery_borrowed_session_obligations()||($GLOBALS['governance_recovery_borrowed_fixture_cleanup']['cleanup_proved']??null)!==true){throw new RuntimeException('borrowed_complete_case_set_required');}
  return array('profile'=>'isolated-borrowed-real-store-session-owner-v1','executed_case_obligations'=>$labels,
    'real_store_owner_and_native_session_INSERT'=>true,'participant_owns_transaction'=>false,
    'production_observer_factory_available'=>false,'business_submit_review_integration_proved'=>false,
    'durable_review_compatible'=>false,'Task_File_RR_combined'=>false,'all_sources_complete'=>false,
    'restoration_authorized'=>false,'execution_blocked'=>true);
}
