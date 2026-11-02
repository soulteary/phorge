<?php

/** Prepared bindings and bounded persistent admission; no public HTTP route. */
function governance_native_media_target_cases($fixture, callable $check, array $state) {
  $media=$state['media'];$request=$state['admin']['request'];
  $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$state['admin']['token'];
  $path='/file/download/key/PHID-FILE-ssssssssssssssssssss/'.rawurlencode('部门报告.xlsx');
  $prepared=governance_native_prepare_media($media,null,$path);$tx=$prepared['transaction_id'];
  $nonce=governance_native_media_nonce($tx);$before=governance_native_media_fingerprint($fixture);
  governance_native_refused(fn()=>$media->issue($request,$tx,'phorge-files','private-media',
    str_replace('/download/','/data/',$path),$nonce),'media_exchange_issue_refused',$check,
    'native-media-prepared-target-cannot-be-changed-at-issue');
  governance_native_refused(fn()=>$media->issue($request,$tx,'phorge-files','private-media',$path,str_repeat('0',64)),
    'media_exchange_issue_refused',$check,'native-media-prepared-client-nonce-required-at-issue');
  $check(governance_native_media_fingerprint($fixture)===$before,'native-media-target-and-nonce-refusal-no-generation-or-code-write');
  $issued=$media->issue($request,$tx,'phorge-files','private-media',$path,$nonce);
  governance_native_refused(fn()=>$media->redeem($tx,$issued['exchange_code']->openEnvelope(),
    $prepared['browser_secret']->openEnvelope(),'phorge-files','private-media',$path,str_repeat('0',64)),
    'media_exchange_redemption_refused',$check,'native-media-client-nonce-required-at-redemption');
  $redeemed=$media->redeem($tx,$issued['exchange_code']->openEnvelope(),$prepared['browser_secret']->openEnvelope(),
    'phorge-files','private-media',$path,$nonce);
  $check($redeemed['return_path']===$path && $media->authenticate($redeemed['credential']->openEnvelope())->getUserPHID()===$state['admin']['viewer']->getPHID(),
    'native-media-prepared-unicode-download-returns-exact-canonical-target');
  governance_native_refused(fn()=>$media->beginBrowserTransaction(), 'media_prepared_target_required',$check,
    'native-media-unbound-preparation-api-fails-closed');
  $quota=governance_native_prepare_media($media);$secret=$quota['browser_secret']->openEnvelope();
  for($i=1;$i<6;$i++){$quota=governance_native_prepare_media($media,$secret);}
  $before=governance_native_media_fingerprint($fixture);
  governance_native_refused(fn()=>governance_native_prepare_media($media,$secret),'media_exchange_preparation_refused',$check,
    'native-media-seventh-browser-preparation-refused-by-persistent-quota');
  $check(governance_native_media_fingerprint($fixture)===$before,
    'native-media-rejected-preparation-rolls-back-all-quota-and-ledger-writes');
  $issuer=PhorgeGovernanceMediaIssuer::newFromConfiguration();
  $digest=$issuer->digest('browser',$secret);
  $rows=governance_native_setup_query($fixture,'SELECT COUNT(*) n FROM '.$fixture->table('user','governance_mediaexchange').
    " WHERE browserDigest='".$digest."' AND codeDigest IS NULL")->fetch_assoc();
  $check((int)$rows['n']===1,'native-media-browser-keeps-only-one-unissued-prepared-target');
  // The trusted peer key is derived from native REMOTE_ADDR, never an XFF input.
  $quota_table=$fixture->table('user','governance_mediaquota');
  foreach(array(array('client:127.0.0.1',120,'client'),array('global:all',600,'global')) as $case){
    list($value,$limit,$label)=$case;$bucket=$issuer->digest('rate-client',$value);
    $before_row=governance_native_setup_query($fixture,'SELECT * FROM '.$quota_table." WHERE bucketDigest='".$bucket."'")->fetch_assoc();
    governance_native_setup_query($fixture,'UPDATE '.$quota_table.' SET attempts='.$limit.',windowStart=UNIX_TIMESTAMP(),expiresAt=UNIX_TIMESTAMP()+120'.
      " WHERE bucketDigest='".$bucket."'");
    try{
      $before=governance_native_media_fingerprint($fixture);
      governance_native_refused(fn()=>governance_native_prepare_media($media),'media_exchange_preparation_refused',$check,
        'native-media-'.$label.'-persistent-admission-quota-refuses-new-browser');
      $check(governance_native_media_fingerprint($fixture)===$before,'native-media-'.$label.'-quota-denial-no-partial-ledger');
    }finally{
      governance_native_setup_query($fixture,'UPDATE '.$quota_table.' SET attempts='.(int)$before_row['attempts'].
        ',windowStart='.(int)$before_row['windowStart'].',expiresAt='.(int)$before_row['expiresAt']." WHERE bucketDigest='".$bucket."'");
    }
  }
  // Seed only expired quota rows, then prove one invocation cannot exceed its bound.
  foreach(array('a','b','c') as $char){governance_native_setup_query($fixture,'INSERT INTO '.$quota_table.
    " (bucketDigest,windowStart,attempts,expiresAt) VALUES ('".str_repeat($char,64)."',1,1,1)");}
  $native_before=hash('sha256',json_encode(governance_native_setup_query($fixture,'SELECT phid,accountSecret,isDisabled FROM '.
    $fixture->table('user','user').' ORDER BY phid')->fetch_all(MYSQLI_ASSOC)));
  $result=$media->cleanupExpired(1);
  $check($result['removed']['governance_mediaquota']===1 && max($result['removed'])<=1,
    'native-media-expired-cleanup-obeys-per-table-row-budget');
  $remaining=governance_native_setup_query($fixture,'SELECT COUNT(*) n FROM '.$quota_table.' WHERE expiresAt=1')->fetch_assoc();
  $check((int)$remaining['n']===2,'native-media-bounded-cleanup-leaves-rest-for-next-invocation');
  $media->cleanupExpired(500);
  $check($media->authenticate($redeemed['credential']->openEnvelope())->getUserPHID()===$state['admin']['viewer']->getPHID() &&
    hash('sha256',json_encode(governance_native_setup_query($fixture,'SELECT phid,accountSecret,isDisabled FROM '.
    $fixture->table('user','user').' ORDER BY phid')->fetch_all(MYSQLI_ASSOC)))===$native_before,
    'native-media-cleanup-retains-current-credential-and-native-accounts');
  $admission=governance_native_media_admission_cases($fixture,$check,$state);
  return array('admission_tests'=>$admission,'prepared_target_and_nonce_verified'=>true,'persistent_admission_quotas_verified'=>true,
    'bounded_expired_cleanup_verified'=>true,'http_routes_enabled'=>false,'native_authentication_rows_deleted'=>false);
}

/** Real independent processes, actual SQL capacities and rollback faults. */
function governance_native_media_admission_cases($fixture, callable $check, array $state) {
  $media=$state['media'];$issuer=PhorgeGovernanceMediaIssuer::newFromConfiguration();
  $table=$fixture->table('user','governance_mediaquota');$admin=$fixture->getAdmin();
  $path='/file/data/key/PHID-FILE-ssssssssssssssssssss/name';
  $slot=governance_native_prepare_media($media);$secret=$slot['browser_secret']->openEnvelope();
  $browser=$issuer->digest('browser',$secret);$bucket=$issuer->digest('rate-client','browser:'.$browser);
  governance_native_setup_query($fixture,'UPDATE '.$table.' SET attempts=5,windowStart=UNIX_TIMESTAMP(),expiresAt=UNIX_TIMESTAMP()+120'.
    " WHERE bucketDigest='".$bucket."'");
  $mutex=hash('sha256','phorge-media-capacity-mutex:v1');
  $config=$fixture->getWriterConfig();$mode=(new mysqli_driver())->report_mode;$observer=null;
  try{
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $observer=new mysqli($config['host'],$config['user'],$config['password'],'',$config['port']);
    $run_race=static function($browser_secret,$label) use ($fixture,$admin,$observer,$table,$mutex,$path,$check){
      if(!$admin->begin_transaction()){throw new RuntimeException('media_race_setup_failed');}
      try{
        governance_native_setup_query($fixture,'SELECT bucketDigest FROM '.$table." WHERE bucketDigest='".$mutex."' FOR UPDATE");
        $action=array('action'=>'media.prepare','browser_secret'=>$browser_secret,'client_nonce'=>bin2hex(random_bytes(32)),'path'=>$path);
        $one=governance_native_start_worker($fixture,$action);$action['client_nonce']=bin2hex(random_bytes(32));
        $two=governance_native_start_worker($fixture,$action);
        governance_native_wait_worker_query($observer,array($one,$two),'/governance_mediaquota.*'.preg_quote($mutex,'/').'/is');
        $check(true,'native-media-'.$label.'-two-real-preparers-wait-on-capacity-mutex');
        if(!$admin->commit()){throw new RuntimeException('media_race_setup_failed');}
        $first=governance_native_finish_worker($one);$second=governance_native_finish_worker($two);
        $statuses=array($first['status'],$second['status']);sort($statuses);
        $rejected=$first['status']==='rejected'?$first:$second;
        $check($statuses===array('committed','rejected') && $rejected['reason']==='media_exchange_preparation_refused',
          'native-media-'.$label.'-only-one-real-preparation-commits');
      }catch(Throwable $error){$admin->rollback();throw $error;}
    };
    $run_race($secret,'last-quota-slot');
    $row=governance_native_setup_query($fixture,'SELECT attempts FROM '.$table." WHERE bucketDigest='".$bucket."'")->fetch_assoc();
    $check((int)$row['attempts']===6,'native-media-concurrent-preparation-does-not-exceed-six-browser-admissions');
    // Expire the window using the actual DB clock; do not inject a runtime clock.
    governance_native_setup_query($fixture,'UPDATE '.$table.' SET attempts=6,windowStart=UNIX_TIMESTAMP()-60,expiresAt=UNIX_TIMESTAMP()+60'.
      " WHERE bucketDigest='".$bucket."'");
    governance_native_prepare_media($media,$secret);
    $row=governance_native_setup_query($fixture,'SELECT attempts,windowStart,UNIX_TIMESTAMP() n FROM '.$table." WHERE bucketDigest='".$bucket."'")->fetch_assoc();
    $check((int)$row['attempts']===1 && (int)$row['n']-(int)$row['windowStart']<=5,
      'native-media-real-database-window-transition-resets-admission-counter');
    governance_native_setup_query($fixture,'UPDATE '.$table.' SET windowStart=UNIX_TIMESTAMP()+60' ." WHERE bucketDigest='".$bucket."'");
    try{
      $before=governance_native_media_fingerprint($fixture);
      governance_native_refused(fn()=>governance_native_prepare_media($media,$secret),'media_exchange_preparation_refused',$check,
        'native-media-future-quota-window-refuses-clock-regression');
      $check(governance_native_media_fingerprint($fixture)===$before,'native-media-clock-regression-refusal-no-partial-admission');
    }finally{governance_native_setup_query($fixture,'UPDATE '.$table.' SET windowStart=UNIX_TIMESTAMP()'." WHERE bucketDigest='".$bucket."'");}
    // Bring the *total* real ledger occupancy to 99997. Each new browser needs
    // its new quota bucket plus browser + exchange, so only one contender fits.
    $cap=governance_native_prepare_media($media);$cap_nonce=governance_native_media_nonce($cap['transaction_id']);
    $cap_code=$media->issue($state['admin']['request'],$cap['transaction_id'],'phorge-files','private-media',$path,$cap_nonce);
    $total=0;foreach(array('governance_mediaexchange','governance_mediabrowser','governance_mediacredential','governance_mediaquota') as $name){$total+=$fixture->countRows('user',$name);}
    $fill=99997-$total;if($fill<1){throw new RuntimeException('media_capacity_fixture_budget_refused');}
    $digits='(SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
    $number='(a.n+10*b.n+100*c.n+1000*d.n+10000*e.n)';
    governance_native_setup_query($fixture,'INSERT INTO '.$table.' (bucketDigest,windowStart,attempts,expiresAt) '.
      "SELECT CONCAT('z',LPAD(CAST(".$number." AS CHAR),63,'0')),1,1,1 FROM ".$digits.' a CROSS JOIN '.$digits.
      ' b CROSS JOIN '.$digits.' c CROSS JOIN '.$digits.' d CROSS JOIN '.$digits.' e LIMIT '.$fill);
    try{
      $run_race(null,'hard-capacity');
      $after=0;foreach(array('governance_mediaexchange','governance_mediabrowser','governance_mediacredential','governance_mediaquota') as $name){$after+=$fixture->countRows('user',$name);}
      $check($after===100000,'native-media-waiting-preparer-fresh-count-honors-exact-total-capacity');
      // Redeem also adds a credential and must not evade the same total bound.
      $before=governance_native_media_fingerprint($fixture);
      governance_native_refused(fn()=>governance_native_prepare_media($media),'media_exchange_preparation_refused',$check,
        'native-media-expired-ledger-rows-still-count-against-total-capacity');
      $check(governance_native_media_fingerprint($fixture)===$before,'native-media-hard-capacity-denial-rolls-back-new-quota-bucket');
      governance_native_refused(fn()=>$media->redeem($cap['transaction_id'],$cap_code['exchange_code']->openEnvelope(),
        $cap['browser_secret']->openEnvelope(),'phorge-files','private-media',$path,$cap_nonce),
        'media_exchange_redemption_refused',$check,'native-media-redemption-cannot-exceed-total-ledger-capacity');
      $check(governance_native_media_fingerprint($fixture)===$before,'native-media-capacity-refused-redemption-does-not-consume-code-or-revoke-credential');
    }finally{governance_native_setup_query($fixture,"DELETE FROM ".$table." WHERE bucketDigest LIKE 'z%'");}
    $cap_credential=$media->redeem($cap['transaction_id'],$cap_code['exchange_code']->openEnvelope(),
      $cap['browser_secret']->openEnvelope(),'phorge-files','private-media',$path,$cap_nonce);
    $check($media->authenticate($cap_credential['credential']->openEnvelope())->getUserPHID()===$state['admin']['viewer']->getPHID(),
      'native-media-capacity-denial-leaves-code-redeemable-after-space-is-freed');
    $fresh=governance_native_prepare_media($media);
    $check(is_string($fresh['transaction_id']),'native-media-capacity-released-by-owned-expired-row-removal');
    // A later-table DELETE fault must undo an earlier-table expiration deletion.
    $exchange=$fixture->table('user','governance_mediaexchange');
    $expired=str_repeat('d',64);
    governance_native_setup_query($fixture,'INSERT INTO '.$exchange.' (transactionDigest,browserDigest,generation,expiresAt) '.
      "VALUES ('".$expired."','".str_repeat('e',64)."',1,1)");
    governance_native_setup_query($fixture,'INSERT INTO '.$table." (bucketDigest,windowStart,attempts,expiresAt) VALUES ('".str_repeat('f',64)."',1,1,1)");
    $trigger='`'.$fixture->getNamespace().'_user`.`media_cleanup_fault`';
    governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger.' BEFORE DELETE ON '.$table.
      " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned media cleanup fault'");
    try{
      $before=governance_native_media_fingerprint($fixture);
      governance_native_refused(fn()=>$media->cleanupExpired(500),'media_cleanup_refused',$check,
        'native-media-later-table-cleanup-real-sql-fault-refused');
      $check(governance_native_media_fingerprint($fixture)===$before,'native-media-cleanup-fault-rolls-back-earlier-expiration-deletes');
    }finally{governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger);}
    $media->cleanupExpired(500);
    $row=governance_native_setup_query($fixture,'SELECT COUNT(*) n FROM '.$exchange." WHERE transactionDigest='".$expired."'")->fetch_assoc();
    $check((int)$row['n']===0,'native-media-cleanup-can-complete-after-owned-fault-is-removed');
    // Media operations use one scoped RC transaction; the next normal native
    // transaction must still use the original RR consistent-read semantics.
    $conn=(new PhabricatorUser())->establishConnection('w');
    $default=queryfx_one($conn,'SELECT @@SESSION.transaction_isolation n');
    $conn->openTransaction();
    try{
      $first=queryfx_one($conn,'SELECT attempts FROM governance_mediaquota WHERE bucketDigest=%s',$bucket);
      governance_native_setup_query($fixture,'UPDATE '.$table.' SET attempts=attempts+1'." WHERE bucketDigest='".$bucket."'");
      $second=queryfx_one($conn,'SELECT attempts FROM governance_mediaquota WHERE bucketDigest=%s',$bucket);
      $check($default['n']==='REPEATABLE-READ' && $first['attempts']===$second['attempts'],
        'native-media-scoped-rc-does-not-change-next-native-repeatable-read');
      $conn->killTransaction();
    }catch(Throwable $error){$conn->killTransaction();throw $error;}
  }finally{if($observer!==null){$observer->close();}mysqli_report($mode);}
  return array('real_prepare_races_verified'=>true,'hard_total_capacity_verified'=>true,
    'database_window_transition_verified'=>true,'cleanup_sql_rollback_verified'=>true,'next_native_rr_preserved'=>true);
}
