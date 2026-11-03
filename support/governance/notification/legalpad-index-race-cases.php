<?php

/** Stronger fact-based matrix. All SQL is confined to the owned fixture. */
function governance_private_mail_legalpad_index_race_cases($fixture,callable $check,array $state){
  global $control;if(!($control instanceof mysqli)){throw new RuntimeException('legalpad_race_observer_required');}
  $fixture->assertOwned();require_once dirname(__DIR__).'/native-organization/workers.php';
  $signature=$state['signature'];$auth=$state['signer_auth'];$call=$state['call'];$setup=$fixture->getAdmin();
  $sql=static function($statement,array $values=array())use($setup){$mode=(new mysqli_driver())->report_mode;$s=null;
    try{mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$s=$setup->prepare($statement);if(!$s||!$s->execute($values)){throw new RuntimeException('legalpad_race_setup_failed');}
      return$s->field_count?$s->get_result()->fetch_all(MYSQLI_ASSOC):$s->affected_rows;
    }finally{if($s){$s->close();}mysqli_report($mode);}};
  $wait=static function(array $worker,$schema,$table)use($fixture,$control,$setup){
    governance_native_wait_worker_query($control,array($worker),'/'.preg_quote($table,'/').'.*(?:FOR UPDATE|LOCK IN SHARE MODE)/is');
    $deadline=microtime(true)+5;do{
      $q=$control->query('SELECT COUNT(*) n FROM performance_schema.data_lock_waits w '.
        'JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID '.
        'JOIN performance_schema.data_locks dl ON dl.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID AND dl.ENGINE=w.ENGINE '.
        "WHERE dl.OBJECT_SCHEMA='".$fixture->getNamespace().'_'.$schema."' AND dl.OBJECT_NAME='".$table."'".
        ' AND r.PROCESSLIST_ID='.(int)$worker['mysql_thread'].' AND b.PROCESSLIST_ID='.(int)$setup->thread_id);
      if(!$q){throw new RuntimeException('legalpad_race_lock_observer_failed');}$yes=(int)$q->fetch_assoc()['n']>0;$q->free();if($yes){return;}usleep(20000);
    }while(microtime(true)<$deadline);throw new RuntimeException('legalpad_race_actual_lock_wait_required');
  };
  $victim=static function(array $worker)use($control,$setup,$fixture){
    $q=$control->query('SHOW ENGINE INNODB STATUS');if(!$q){throw new RuntimeException('legalpad_race_victim_observer_failed');}$text=$q->fetch_assoc()['Status'];$q->free();
    $section=explode('LATEST DETECTED DEADLOCK',$text,2);if(count($section)!==2){throw new RuntimeException('legalpad_race_victim_proof_missing');}
    $section=explode('TRANSACTIONS',$section[1],2)[0];$threads=array();
    foreach(array('1','2')as$n){if(!preg_match('/\*\*\* \('.$n.'\) TRANSACTION:(.*?)(?=\*\*\*|$)/s',$section,$part)||
      !preg_match('/MySQL thread id ([0-9]+)/',$part[1],$tid)){throw new RuntimeException('legalpad_race_victim_thread_missing');}$threads[$n]=(int)$tid[1];}
    $expected=array((int)$worker['mysql_thread'],(int)$setup->thread_id);$actual=array_values($threads);sort($expected);sort($actual);
    if($expected!==$actual||!str_contains($section,$fixture->getNamespace().'_legalpad')||
      !preg_match('/WE ROLL BACK TRANSACTION \(([12])\)/',$section,$selected)){throw new RuntimeException('legalpad_race_victim_lineage_refused');}
    return$threads[$selected[1]]===$worker['mysql_thread']?'verifier':'source-writer';
  };
  $managed=array();$cleanup=static function()use(&$managed){governance_native_stop_fixture_handles($managed);};
  $code=$signature->getSecretKey();$locked=false;$changed=false;
  // This is a distinct timing profile, not a replacement for the index race.
  try{
    if(!$setup->begin_transaction()){throw new RuntimeException('legalpad_race_transaction_failed');}$locked=true;
    $rows=$sql('SELECT phid FROM '.$fixture->table('user','user').' WHERE BINARY phid=BINARY ? FOR UPDATE',array($auth['viewer']->getPHID()));
    if(count($rows)!==1){throw new RuntimeException('legalpad_race_identity_lock_required');}
    $worker=governance_native_start_worker($fixture,array('action'=>'legalpad.verify','code'=>$code,'token'=>$auth['token']));$managed[]=$worker;$wait($worker,'user','user');
    $next=Filesystem::readRandomCharacters(20);$sql('UPDATE '.$fixture->table('legalpad','legalpad_documentsignature').' SET secretKey=? WHERE id=?',array($next,$signature->getID()));$changed=true;
    if(!$setup->commit()){throw new RuntimeException('legalpad_race_commit_failed');}$locked=false;
    $result=governance_native_finish_worker($worker);$stored=(new LegalpadDocumentSignature())->load($signature->getID());$positive=$call($auth,'/legalpad/verify/'.$next.'/','GET');
    $check($result===array('status'=>'rejected','reason'=>'governance_legalpad_verification_refused')&&!$stored->isVerified()&&
      $stored->getSecretKey()===$next&&$positive instanceof AphrontDialogResponse,
      'private-mail-legalpad-before-first-signature-read-user-source-barrier-observes-committed-secret-and-refuses-old-code');
  }finally{if($locked){$setup->rollback();}$cleanup();if($changed){$sql('UPDATE '.$fixture->table('legalpad','legalpad_documentsignature').' SET secretKey=? WHERE id=?',array($code,$signature->getID()));}}
  $db=$fixture->getNamespace().'_legalpad';
  if(!preg_match('/\Agovernance_native_[a-f0-9]{16}_legalpad\z/D',$db)){throw new RuntimeException('legalpad_race_weight_namespace_refused');}
  $matrix=array();$weight='`'.$db.'`.`legalpad_fixture_deadlock_weight`';$weight_created=false;
  try{
    governance_native_setup_query($fixture,'CREATE TABLE '.$weight.' (id INT UNSIGNED NOT NULL PRIMARY KEY,weight_value INT UNSIGNED NOT NULL) ENGINE=InnoDB');$weight_created=true;
    for($i=1;$i<=512;$i++){$sql('INSERT INTO '.$weight.' (id,weight_value) VALUES (?,0)',array($i));}
    foreach(array('natural','forced-verifier-victim')as$kind){
      // Fixture-only independent row: never reset the original native producer's
      // one-use state to make another successful consume possible.
      $row=clone $signature;$row->setID(null)->setPHID(null)->setSecretKey(Filesystem::readRandomCharacters(20))->setVerified(LegalpadDocumentSignature::UNVERIFIED)->save();
      $old=$row->getSecretKey();$new=Filesystem::readRandomCharacters(20);$locked=false;$worker=null;$commit_ack=false;$writer_deadlock=false;$who='none';
      try{
        if(!$setup->begin_transaction()){throw new RuntimeException('legalpad_race_transaction_failed');}$locked=true;
        if($kind==='forced-verifier-victim'&&$sql('UPDATE '.$weight.' SET weight_value=weight_value+1')!==512){throw new RuntimeException('legalpad_race_weight_setup_failed');}
        $sql('SELECT id FROM '.$fixture->table('legalpad','legalpad_documentsignature').' WHERE id=? FOR UPDATE',array($row->getID()));
        $worker=governance_native_start_worker($fixture,array('action'=>'legalpad.observe','code'=>$old,'signature_id'=>(int)$row->getID(),'token'=>$auth['token']));$managed[]=$worker;
        $wait($worker,'legalpad','legalpad_documentsignature');
        if($kind==='forced-verifier-victim'){
          $deadline=microtime(true)+5;$heavier=false;
          do{$q=$control->query('SELECT trx_mysql_thread_id tid,trx_weight weight FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id IN ('.(int)$setup->thread_id.','.(int)$worker['mysql_thread'].')');
            if(!$q){throw new RuntimeException('legalpad_race_weight_observer_failed');}$weights=array();foreach($q->fetch_all(MYSQLI_ASSOC)as$r){$weights[(int)$r['tid']]=(int)$r['weight'];}$q->free();
            $heavier=isset($weights[$setup->thread_id],$weights[$worker['mysql_thread']])&&$weights[$setup->thread_id]>$weights[$worker['mysql_thread']];
            if($heavier){break;}usleep(20000);
          }while(microtime(true)<$deadline);
          if(!$heavier){throw new RuntimeException('legalpad_race_heavier_parent_not_proved');}
        }
        try{$sql('UPDATE '.$fixture->table('legalpad','legalpad_documentsignature').' SET secretKey=? WHERE id=?',array($new,$row->getID()));
          if(!$setup->commit()){throw new RuntimeException('legalpad_race_commit_failed');}$locked=false;$commit_ack=true;
        }catch(mysqli_sql_exception$error){if($error->getCode()!==1213){throw$error;}$writer_deadlock=true;$setup->rollback();$locked=false;}
        $observation=governance_native_peek_legalpad_observation($worker);
        if($writer_deadlock||$observation['outcome']==='deadlock'){$who=$victim($worker);}
        $stored=(new LegalpadDocumentSignature())->load($row->getID());
        $q=$control->query('SELECT COUNT(*) n FROM performance_schema.threads WHERE PROCESSLIST_ID='.(int)$worker['mysql_thread']);
        if(!$q){throw new RuntimeException('legalpad_race_live_observer_failed');}$alive=(int)$q->fetch_assoc()['n']===1;$q->free();
        $q=$control->query('SELECT COUNT(*) n FROM performance_schema.data_locks l JOIN performance_schema.threads t ON t.THREAD_ID=l.THREAD_ID WHERE t.PROCESSLIST_ID='.(int)$worker['mysql_thread']);
        if(!$q){throw new RuntimeException('legalpad_race_released_lock_observer_failed');}$released=(int)$q->fetch_assoc()['n']===0;$q->free();
        $facts=$alive&&$released&&$observation['transaction_open']===false&&$observation['mail_delta']===0;
        $committed_refusal=$commit_ack&&!$writer_deadlock&&!$stored->isVerified()&&$stored->getSecretKey()===$new&&
          in_array($observation['outcome'],array('source-refused','deadlock'),true)&&($who==='none'||$who==='verifier');
        $rollback_success=!$commit_ack&&$writer_deadlock&&$who==='source-writer'&&$stored->isVerified()&&$stored->getSecretKey()===$old&&$observation['outcome']==='verified';
        if($kind==='natural'){
          $check($facts&&($committed_refusal||$rollback_success),
            'private-mail-legalpad-signature-index-race-follows-committed-rotation-or-observed-source-writer-rollback');
          $matrix['index_race']=array('actual_lock_wait'=>true,'source_writer_commit_ack'=>$commit_ack,'source_writer_deadlock_rollback'=>$writer_deadlock,
            'observed_victim'=>$who,'service_outcome'=>$observation['outcome'],'old_code_consumed'=>$stored->isVerified(),
            'outcome_follows_committed_facts'=>true,'manual_retry_used'=>false);
        }else{
          $check($commit_ack&&!$writer_deadlock&&$who==='verifier'&&$observation['outcome']==='deadlock'&&
            $observation['exception_class']==='AphrontDeadlockQueryException',
            'private-mail-legalpad-weighted-index-race-proves-actual-verifier-deadlock-victim-not-permission-denial');
          $check($facts&&!$stored->isVerified()&&$stored->getSecretKey()===$new&&$observation['verified']===false,
            'private-mail-legalpad-verifier-victim-rolls-back-and-releases-locks-before-connection-close-with-no-mail');
          $matrix['forced_verifier_victim']=array('actual_engine_victim_proved'=>true,'heavier_source_transaction_proved'=>true,'exact_native_deadlock_exception'=>true,
            'unverified_after_rollback'=>true,'source_rotation_committed'=>true,'locks_released_before_connection_close'=>true,
            'no_followup_mail'=>true,'automatic_consume_retry_used'=>false,'controller_tested'=>false,'controller_http500_proved'=>false);
        }
        governance_native_release_legalpad_observation($worker);$final=governance_native_finish_worker($worker);
        if($final!==$observation){throw new RuntimeException('legalpad_race_final_observation_changed');}
      }finally{if($locked){$setup->rollback();}$cleanup();$row->delete();}
    }
  }finally{if($weight_created){governance_native_setup_query($fixture,'DROP TABLE '.$weight);}}
  return array('before_first_signature_read'=>array('native_user_barrier'=>true,'source_rotation_committed'=>true,'old_code_refused'=>true,'current_code_get_positive'=>true))+$matrix;
}
