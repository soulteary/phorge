<?php
/** Deterministic separate-process observation while a genuine outer txn is open. */
function governance_private_mail_commit_race_cases($fixture,callable $check){
  $fixture->assertOwned();$suffix=substr($fixture->getNamespace(),-10);$samples=array();
  $probe=static function($mail)use($fixture){
    $directory=sys_get_temp_dir().'/phorge-private-mail-wire-'.bin2hex(random_bytes(12));$owner=bin2hex(random_bytes(32));
    if(!mkdir($directory,0700)){throw new RuntimeException('private_mail_race_directory_failed');}
    $stat=lstat($directory);$process=null;$pipes=array();
    try{
      $write=static function($name,$value)use($directory){$path=$directory.'/'.$name;if(file_put_contents($path,$value)===false||!chmod($path,0600)){throw new RuntimeException('private_mail_race_private_write_failed');}};
      $write('owner',$owner);$row=$fixture->getAdmin()->query('SELECT owner_nonce FROM '.$fixture->table('governance','fixture_guard').' WHERE id=1')->fetch_assoc();
      $data=array('callback_origin'=>'http://127.0.0.1:1','file_token'=>PhabricatorEnv::getEnvConfig('gorge.file.token'),
        'file_uri'=>PhabricatorEnv::getEnvConfig('gorge.file.uri'),'fixture_nonce'=>$row['owner_nonce'],'mailer_token'=>bin2hex(random_bytes(32)),
        'mailer_uri'=>'http://127.0.0.1:1','owner'=>$owner,'service_token'=>bin2hex(random_bytes(32)),
        'source_sha256'=>hash_file('sha256',dirname(phutil_get_library_root('phabricator')).'/resources/sql/quickstart.sql'),
        'writer'=>$fixture->getWriterConfig());
      $write('router.json',json_encode($data,JSON_THROW_ON_ERROR));$write('probe.json',json_encode(array('owner'=>$owner,'mail_id'=>(int)$mail->getID()),JSON_THROW_ON_ERROR));
      $process=proc_open(array(PHP_BINARY,__DIR__.'/commit-race-worker.php',$directory),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
      if(!is_resource($process)){throw new RuntimeException('private_mail_race_spawn_failed');}fclose($pipes[0]);unset($pipes[0]);
      foreach($pipes as$pipe){stream_set_blocking($pipe,false);}$out='';$err='';$deadline=microtime(true)+15;$exit=null;
      do{$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);$status=proc_get_status($process);
        if(strlen($out)>4096||strlen($err)>4096){throw new RuntimeException('private_mail_race_output_budget');}
        if(!$status['running']){$exit=$status['exitcode'];break;}if(microtime(true)>$deadline){throw new RuntimeException('private_mail_race_worker_timeout');}usleep(20000);
      }while(true);
      $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);foreach($pipes as$pipe){fclose($pipe);}$pipes=array();proc_close($process);$process=null;
      $result=json_decode($out,true,8,JSON_THROW_ON_ERROR);
      if($exit!==0||$err!==''||!is_array($result)||($result['passed']??null)!==true){
        // Fixed safe stage, never the child's source message or private payload.
        throw new RuntimeException('private_mail_race_worker_failed');}
      return$result;
    }finally{
      if(is_resource($process)){proc_terminate($process);foreach($pipes as$pipe){if(is_resource($pipe)){fclose($pipe);}}proc_close($process);}
      $current=lstat($directory);if(!$current||$current['dev']!==$stat['dev']||$current['ino']!==$stat['ino']||is_link($directory)){throw new RuntimeException('private_mail_race_cleanup_owner_refused');}
      foreach(array('owner','router.json','probe.json')as$name){$path=$directory.'/'.$name;if(file_exists($path)){if(is_link($path)||!unlink($path)){throw new RuntimeException('private_mail_race_cleanup_failed');}}}
      if(!rmdir($directory)){throw new RuntimeException('private_mail_race_cleanup_failed');}
    }
  };
  foreach(array('commit','rollback','recipient-revoked','admin-revoked')as$ending){
    $admin=(new PhabricatorUser())->setUsername('pmraceadmin'.$ending.$suffix)->setRealName('Synthetic race administrator')->setIsApproved(1)->setIsEmailVerified(1)->setIsAdmin(1)->save();
    (new PhabricatorUserEmail())->setUserPHID($admin->getPHID())->setAddress($admin->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
    $subject=(new PhabricatorUser())->setUsername('pmracesubject'.$ending.$suffix)->setRealName('Synthetic race subject')->setIsApproved($ending==='admin-revoked'?0:1)->setIsEmailVerified(1)->save();
    (new PhabricatorUserEmail())->setUserPHID($subject->getPHID())->setAddress($subject->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
    $auth=governance_native_request($admin,new PhabricatorAuthSessionEngine());$table=new PhabricatorMetaMTAMail();
    $before=(int)queryfx_one($table->establishConnection('r'),'SELECT COALESCE(MAX(id),0) n FROM %R',$table)['n'];
    $conn=$subject->establishConnection('w');$conn->openTransaction();
    try{
      (new PhabricatorUserTransactionEditor())->setActor($auth['viewer'])->setContentSourceFromRequest($auth['request'])->setCancelURI('/people/')
        ->applyTransactions($subject,array((new PhabricatorUserTransaction())->setTransactionType($ending==='admin-revoked'?PhabricatorUserApproveTransaction::TRANSACTIONTYPE:PhabricatorUserUsernameTransaction::TRANSACTIONTYPE)->setNewValue($ending==='admin-revoked'?true:'pmracedone'.$ending.$suffix)));
      $mails=$table->loadAllWhere('id>%d ORDER BY id',$before);$selected=array();foreach($mails as$mail){if($mail->getPrivateMailPolicyValue('governance.mail-flow')===($ending==='admin-revoked'?'account.approved.admin':'account.username')){$selected[]=$mail;}}
      if(count($selected)!==1){throw new RuntimeException('private_mail_race_native_mail_cardinality');}$mail=head($selected);
      $prepared=$mail->prepareGorgeDelivery();$digest=hash('sha256',phutil_json_encode($prepared));
      $first=$probe($mail);$check($conn->isInsideTransaction()&&$first['allowed']===null&&$first['pending']===true&&
        $first['state']==='pending'&&$first['callback_pending']===true&&$first['callback_allowed']===false&&
        $first['quarantined']===false&&$first['snapshot_digest']===$digest&&$first['physical_delivery_attempted']===false,
        'private-mail-'.($ending==='admin-revoked'?'approval':'username').'-independent-'.$ending.'-precommit-defers-without-quarantine-snapshot-rewrite-or-send');
      if($ending==='commit'){$conn->saveTransaction();}else{$conn->killTransaction();}
      if($ending==='admin-revoked'){$admin->setIsAdmin(0)->save();}
      if($ending==='recipient-revoked'){
        $email=(new PhabricatorUserEmail())->loadOneWhere('userPHID=%s AND isPrimary=1',$subject->getPHID());$email->setIsPrimary(0)->save();
        (new PhabricatorUserEmail())->setUserPHID($subject->getPHID())->setAddress('replacement'.$subject->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
      }
      if($ending==='rollback'){
        $still=$probe($mail);$check($still['pending']===true&&$still['quarantined']===false&&$still['callback_allowed']===false,
          'private-mail-username-rolled-back-missing-source-remains-unprivileged-during-bounded-retry');
        queryfx($table->establishConnection('w'),'UPDATE %R SET dateCreated=UNIX_TIMESTAMP()-301 WHERE id=%d',$table,$mail->getID());
      }
      $second=$probe($mail);$check($second['source_event_visible']===($ending==='commit')&&
        $second['allowed']===($ending==='commit')&&$second['pending']===false&&$second['state']===($ending==='commit'?'prepared':'void')&&
        $second['callback_allowed']===($ending==='commit')&&$second['quarantined']===($ending!=='commit')&&$second['snapshot_digest']===$digest&&
        $second['physical_delivery_attempted']===false,
        'private-mail-'.($ending==='admin-revoked'?'approval':'username').'-independent-'.$ending.'-durable-retry-rechecks-source-and-current-destination');
      if($ending==='commit'){
        $stored=(new PhabricatorMetaMTAMail())->load($mail->getID());$original_binding=$stored->getPrivateMailPolicyValue('governance.mail-binding');
        $bad_binding=$original_binding;$bad_binding['source_hash']=str_repeat('0',64);
        (new ReflectionMethod(PhabricatorMetaMTAMail::class,'setParam'))->invoke($stored,'governance.mail-binding',$bad_binding);$stored->save();
        $mismatch=$probe($stored);$check($mismatch['pending']===false&&$mismatch['allowed']===false&&$mismatch['quarantined']===true&&$mismatch['snapshot_digest']===$digest,
          'private-mail-user-event-present-hash-mismatch-is-permanent-denial-not-pending');
        $stored=(new PhabricatorMetaMTAMail())->load($mail->getID());
        (new ReflectionMethod(PhabricatorMetaMTAMail::class,'setParam'))->invoke($stored,'governance.mail-binding',$original_binding);$stored->save();
        $historic=$probe($stored);$check($historic['source_event_matches']===true&&$historic['pending']===false&&$historic['allowed']===false&&
          $historic['quarantined']===true&&$historic['snapshot_digest']===$digest,
          'private-mail-previously-quarantined-message-never-auto-restores-after-source-repair');
      }
      $samples[$ending]=array('precommit_pending'=>true,'precommit_quarantined'=>false,'postcompletion_allowed'=>$second['allowed']);

    }catch(Throwable$error){if($conn->isInsideTransaction()){$conn->killTransaction();}throw$error;}
  }
  return array('independent_process_native_commit_race_reproduced'=>true,'samples'=>$samples,'production_fix_implemented'=>true,'physical_smtp_race_proved'=>false);
}
