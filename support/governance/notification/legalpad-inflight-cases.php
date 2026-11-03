<?php

/** Real source mutation while an independent verifier waits on its actual row lock. */
function governance_private_mail_legalpad_inflight_cases($fixture,callable $check,array $state){
  global $control;
  if(!($control instanceof mysqli)){throw new RuntimeException('legalpad_inflight_observer_required');}
  require_once dirname(__DIR__).'/native-organization/workers.php';$fixture->assertOwned();
  $signature=$state['signature'];$doc=$state['document'];$auth=$state['signer_auth'];$call=$state['call'];
  $setup=$fixture->getAdmin();$code=$signature->getSecretKey();$version=$doc->getVersions();
  $query=static function($sql,array $values=array())use($setup){$mode=(new mysqli_driver())->report_mode;$statement=null;
    try{mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$statement=$setup->prepare($sql);
      if(!$statement||!$statement->execute($values)){throw new RuntimeException('legalpad_inflight_setup_failed');}
      return$statement->field_count?$statement->get_result()->fetch_all(MYSQLI_ASSOC):$statement->affected_rows;
    }finally{if($statement){$statement->close();}mysqli_report($mode);}};
  $outcomes=array();
  foreach(array('secret','version')as$kind){$locked=false;$worker=null;$changed=false;
    $table=$kind==='secret'?'legalpad_documentsignature':'legalpad_document';
    $qualified=$fixture->table('legalpad',$table);$id=$kind==='secret'?$signature->getID():$doc->getID();
    try{
      $query('START TRANSACTION');$locked=true;
      $row=$query('SELECT id FROM '.$qualified.' WHERE id=? FOR UPDATE',array($id));
      if(count($row)!==1){throw new RuntimeException('legalpad_inflight_lock_target_required');}
      $worker=governance_native_start_worker($fixture,array('action'=>'legalpad.verify','code'=>$code,'token'=>$auth['token']));
      governance_native_wait_worker_query($control,array($worker),'/'.preg_quote($table,'/').'.*FOR UPDATE/is');
      $deadline=microtime(true)+5;$observed=false;
      do{$result=$control->query('SELECT COUNT(*) n FROM performance_schema.data_lock_waits w '.
        'JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID '.
        'JOIN performance_schema.data_locks dl ON dl.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID AND dl.ENGINE=w.ENGINE '.
        "WHERE dl.OBJECT_SCHEMA='".$fixture->getNamespace()."_legalpad' AND dl.OBJECT_NAME='".$table."'".
        ' AND r.PROCESSLIST_ID='.(int)$worker['mysql_thread'].' AND b.PROCESSLIST_ID='.(int)$setup->thread_id);
        if(!$result){throw new RuntimeException('legalpad_inflight_lock_observation_failed');}$observed=(int)$result->fetch_assoc()['n']>0;$result->free();
        if($observed){break;}usleep(20000);
      }while(microtime(true)<$deadline);
      if(!$observed){throw new RuntimeException('legalpad_inflight_actual_wait_required');}
      $newcode=$kind==='secret'?Filesystem::readRandomCharacters(20):$code;
      if($kind==='secret'){$query('UPDATE '.$qualified.' SET secretKey=? WHERE id=?',array($newcode,$id));}
      else{$query('UPDATE '.$qualified.' SET versions=? WHERE id=?',array((int)$version+1,$id));}
      $changed=true;$query('COMMIT');$locked=false;
      $receipt=governance_native_finish_worker($worker);
      $stored=(new LegalpadDocumentSignature())->load($signature->getID());
      if($kind==='secret'){
        $positive=$call($auth,'/legalpad/verify/'.$newcode.'/','GET');
        $contrast=$stored->getSecretKey()===$newcode&&$positive instanceof AphrontDialogResponse;
      }else{
        $refused=$call($auth,'/legalpad/verify/'.$code.'/','GET');
        $contrast=(int)((new LegalpadDocument())->load($doc->getID()))->getVersions()===(int)$version+1&&$refused instanceof Aphront403Response;
      }
      $check($observed&&$receipt===array('status'=>'rejected','reason'=>'governance_legalpad_verification_refused')&&
        !$stored->isVerified()&&$stored->getSignatureData()===$signature->getSignatureData()&&$contrast,
        'private-mail-legalpad-real-inflight-'.$kind.'-change-after-independent-source-lock-wait-refuses-stale-consumption');
      $outcomes[$kind]=array('independent_process'=>true,'actual_lock_wait'=>true,'stale_consumption_refused'=>true);
    }finally{
      if($locked){$query('ROLLBACK');}
      $cleanup=governance_native_cleanup_workers();
      if($changed){if($kind==='secret'){$query('UPDATE '.$qualified.' SET secretKey=? WHERE id=?',array($code,$id));}
        else{$query('UPDATE '.$qualified.' SET versions=? WHERE id=?',array($version,$id));}}
      if($cleanup['cleanup_failures']!==0||$cleanup['private_directories_remaining']!==0||$cleanup['live_processes_after_cleanup']!==0){throw new RuntimeException('legalpad_inflight_cleanup_failed');}
    }
  }
  return array('actual_independent_source_mutation_cases'=>$outcomes,'last_view_to_commit_global_atomicity_proved'=>false,
    'document_space_acl_invented'=>false,'production_source_changed'=>false);
}
