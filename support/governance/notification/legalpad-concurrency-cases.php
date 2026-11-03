<?php

/** Two exec-created PHP processes; genuine web credentials and actual native service. */
function governance_private_mail_legalpad_concurrency_cases($fixture,callable $check,array $state){
  global $control;
  if(!($control instanceof mysqli)){throw new RuntimeException('legalpad_concurrency_observer_required');}
  $fixture->assertOwned();require_once dirname(__DIR__).'/native-organization/workers.php';
  $signature=$state['signature'];$auth=$state['auth'];$setup=$fixture->getAdmin();$locked=false;$trigger=false;$table=false;
  $db=$fixture->getNamespace().'_legalpad';
  if(!preg_match('/\Agovernance_native_[a-f0-9]{16}_legalpad\z/D',$db)){throw new RuntimeException('legalpad_concurrency_scope_refused');}
  $counter='`'.$db.'`.`legalpad_fixture_consumption`';$hook='`'.$db.'`.`legalpad_fixture_consumption_hook`';
  $before=(new LegalpadDocumentSignature())->load($signature->getID());
  if($before->isVerified()){throw new RuntimeException('legalpad_concurrency_pending_signature_required');}
  $sideeffects=static function(){ $counts=array();foreach(array(new PhabricatorMetaMTAMail(),new PhabricatorWorkerActiveTask())as$dao){
    $row=queryfx_one($dao->establishConnection('r'),'SELECT COUNT(*) n FROM %R',$dao);$counts[get_class($dao)]=(int)$row['n'];}return$counts;};
  $counts=$sideeffects();
  try{
    governance_native_setup_query($fixture,'CREATE TABLE '.$counter.' (id INT UNSIGNED NOT NULL PRIMARY KEY,consumes INT UNSIGNED NOT NULL) ENGINE=InnoDB');$table=true;
    governance_native_setup_query($fixture,'INSERT INTO '.$counter.' (id,consumes) VALUES (1,0)');
    governance_native_setup_query($fixture,'CREATE TRIGGER '.$hook.' AFTER UPDATE ON '.$fixture->table('legalpad','legalpad_documentsignature').
      ' FOR EACH ROW BEGIN IF OLD.id='.(int)$signature->getID().' AND OLD.verified=1 AND NEW.verified=0 THEN UPDATE '.$counter.' SET consumes=consumes+1 WHERE id=1; END IF; END');$trigger=true;
    $installed=governance_native_setup_query($fixture,"SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".$db."' AND TRIGGER_NAME='legalpad_fixture_consumption_hook'")->fetch_assoc();
    if((int)$installed['n']!==1){throw new RuntimeException('legalpad_concurrency_trigger_not_installed');}
    governance_native_setup_query($fixture,'START TRANSACTION');$locked=true;
    $statement=$setup->prepare('SELECT phid FROM '.$fixture->table('user','user').' WHERE BINARY phid=BINARY ? FOR UPDATE');
    if(!$statement||!$statement->execute(array($auth['viewer']->getPHID()))||$statement->get_result()->num_rows!==1){throw new RuntimeException('legalpad_concurrency_lock_failed');}$statement->close();
    $action=array('action'=>'legalpad.verify','code'=>$signature->getSecretKey(),'token'=>$auth['token']);
    $first=governance_native_start_worker($fixture,$action);$second=governance_native_start_worker($fixture,$action);
    if($first['mysql_thread']===$second['mysql_thread']){throw new RuntimeException('legalpad_concurrency_distinct_connections_required');}
    governance_native_wait_worker_query($control,array($first,$second),'/FROM .*user.*LOCK IN SHARE MODE/is');
    $deadline=microtime(true)+5;$waiting=0;
    do{$result=$control->query('SELECT COUNT(DISTINCT r.PROCESSLIST_ID) n FROM performance_schema.data_lock_waits w '.
      'JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID '.
      'WHERE r.PROCESSLIST_ID IN ('.(int)$first['mysql_thread'].','.(int)$second['mysql_thread'].') AND b.PROCESSLIST_ID='.(int)$setup->thread_id);
      if(!$result){throw new RuntimeException('legalpad_concurrency_wait_observer_failed');}$waiting=(int)$result->fetch_assoc()['n'];$result->free();
      if($waiting===2){break;}usleep(20000);
    }while(microtime(true)<$deadline);
    $check($waiting===2&&!((new LegalpadDocumentSignature())->load($signature->getID()))->isVerified(),
      'private-mail-legalpad-two-independent-native-web-processes-actually-block-before-consumption');
    governance_native_setup_query($fixture,'COMMIT');$locked=false;
    $a=governance_native_finish_worker($first);$b=governance_native_finish_worker($second);
    $actual=governance_native_setup_query($fixture,'SELECT consumes FROM '.$counter.' WHERE id=1')->fetch_assoc();
    $stored=(new LegalpadDocumentSignature())->load($signature->getID());$outcomes=array($a['already_verified']??null,$b['already_verified']??null);sort($outcomes);
    $check($a['status']==='committed'&&$b['status']==='committed'&&$a['verified']===true&&$b['verified']===true&&
      $outcomes===array(false,true)&&(int)$actual['consumes']===1&&$stored->isVerified()&&$stored->getSecretKey()===$before->getSecretKey()&&
      $stored->getSignatureData()===$before->getSignatureData()&&$sideeffects()===$counts,
      'private-mail-legalpad-concurrent-native-cas-consumes-once-second-observes-verified-without-extra-mail-or-worker');
    return array('independent_php_processes'=>2,'actual_same_source_lock_waiters'=>2,'actual_consumption_updates'=>1,
      'sequential_repeat_substituted_for_concurrency'=>false,'physical_smtp_proved'=>false);
  }finally{
    if($locked){governance_native_setup_query($fixture,'ROLLBACK');}
    // Registry owns process/inode/nonce cleanup on every exit; main fixture
    // strict cleanup receipt also checks no remaining process or directory.
    $cleanup=governance_native_cleanup_workers();
    if($trigger){governance_native_setup_query($fixture,'DROP TRIGGER '.$hook);}
    if($table){governance_native_setup_query($fixture,'DROP TABLE '.$counter);}
    if($cleanup['cleanup_failures']!==0||$cleanup['private_directories_remaining']!==0||$cleanup['live_processes_after_cleanup']!==0){throw new RuntimeException('legalpad_concurrency_worker_cleanup_failed');}
  }
}
