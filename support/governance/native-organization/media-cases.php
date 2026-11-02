<?php

function governance_native_media_cases($fixture, callable $check, array &$state) {
  $media=$state['media']; $request=$state['admin']['request'];
  $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$state['admin']['token'];
  $path='/file/data/key/PHID-FILE-synthetic/name';
  $prepare=static function($secret=null) use ($media) { return $media->beginBrowserTransaction($secret); };
  $issue=static function($transaction) use ($media,$request,$path) {
    return $media->issue($request,$transaction,'phorge-files','private-media',$path);
  };
  $redeem=static function($transaction,$code,$secret,$return=null) use ($media,$path) {
    return $media->redeem($transaction,$code,$secret,'phorge-files','private-media',$return??$path);
  };
  $old_enabled=PhabricatorEnv::getEnvConfig('governance.media-exchange.enabled');
  $before=governance_native_media_fingerprint($fixture);
  try {
    PhabricatorEnv::overrideConfig('governance.media-exchange.enabled',false);
    governance_native_refused(fn()=>$prepare(),'media_exchange_disabled',$check,
      'native-media-disabled-preparation-refused');
    governance_native_refused(fn()=>$issue(str_repeat('0',64)),'media_exchange_disabled',$check,
      'native-media-disabled-issuance-refused');
    governance_native_refused(fn()=>$redeem(str_repeat('0',64),str_repeat('0',64),str_repeat('0',64)),
      'media_exchange_disabled',$check,'native-media-disabled-redemption-refused');
    governance_native_refused(fn()=>$media->authenticate($state['media_credential']->openEnvelope()),
      'media_exchange_disabled',$check,'native-media-disabled-authentication-refused');
    $check(governance_native_media_fingerprint($fixture)===$before,
      'native-media-disabled-backend-does-not-create-or-change-ledger-rows');
  } finally { PhabricatorEnv::overrideConfig('governance.media-exchange.enabled',$old_enabled); }
  $p=$prepare(); $i=$issue($p['transaction_id']);
  foreach (array('code','browser','return-path') as $mismatch) {
    $before=governance_native_media_fingerprint($fixture);
    governance_native_refused(fn()=>$redeem($p['transaction_id'],
      $mismatch==='code'?str_repeat('0',64):$i['exchange_code']->openEnvelope(),
      $mismatch==='browser'?str_repeat('0',64):$p['browser_secret']->openEnvelope(),
      $mismatch==='return-path'?'/file/data/different/PHID-FILE-synthetic/name':null),
      'media_exchange_redemption_refused',$check,'native-media-'.$mismatch.'-binding-refused');
    $check(governance_native_media_fingerprint($fixture)===$before,
      'native-media-'.$mismatch.'-refusal-no-consume-or-generation-write');
  }
  $before=governance_native_media_fingerprint($fixture);
  governance_native_refused(fn()=>$issue($p['transaction_id']),'media_exchange_issue_refused',
    $check,'native-media-code-cannot-be-reissued');
  $check(governance_native_media_fingerprint($fixture)===$before,
    'native-media-reissue-refusal-preserves-code');
  $r=$redeem($p['transaction_id'],$i['exchange_code']->openEnvelope(),$p['browser_secret']->openEnvelope());
  $check($media->authenticate($r['credential']->openEnvelope())->getUserPHID()===$state['admin']['viewer']->getPHID(),
    'native-media-binding-failures-do-not-prevent-legitimate-redemption');
  $before=governance_native_media_fingerprint($fixture);
  governance_native_refused(fn()=>$redeem($p['transaction_id'],$i['exchange_code']->openEnvelope(),
    $p['browser_secret']->openEnvelope()),'media_exchange_redemption_refused',$check,
    'native-media-code-successfully-consumed-exactly-once');
  $check(governance_native_media_fingerprint($fixture)===$before,
    'native-media-repeat-redemption-no-extra-credential');
  $secret=$p['browser_secret']->openEnvelope();
  $old=$prepare($secret);
  $check($media->authenticate($r['credential']->openEnvelope())->getUserPHID()===$state['admin']['viewer']->getPHID(),
    'native-media-preparation-alone-does-not-revoke-current-credential');
  $old_code=$issue($old['transaction_id']);
  governance_native_refused(fn()=>$media->authenticate($r['credential']->openEnvelope()),
    'media_credential_refused',$check,'native-media-new-authenticated-issue-immediately-revokes-old-credential');
  $new=$prepare($secret);
  $second_actor=governance_native_request($state['users']['member'],$state['sessions']);
  $new_code=$media->issue($second_actor['request'],$new['transaction_id'],
    'phorge-files','private-media',$path);
  $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$state['admin']['token'];
  governance_native_refused(fn()=>$redeem($old['transaction_id'],$old_code['exchange_code']->openEnvelope(),$secret),
    'media_exchange_redemption_refused',$check,'native-media-old-account-pending-code-cannot-preempt-new-account-issue');
  $latest=$redeem($new['transaction_id'],$new_code['exchange_code']->openEnvelope(),$secret);
  governance_native_refused(fn()=>$media->authenticate($r['credential']->openEnvelope()),
    'media_credential_refused',$check,'native-media-browser-new-generation-revokes-old-credential');
  governance_native_refused(fn()=>$redeem($old['transaction_id'],$old_code['exchange_code']->openEnvelope(),$secret),
    'media_exchange_redemption_refused',$check,'native-media-old-outstanding-transaction-cannot-replace-new-generation');
  $check($media->authenticate($latest['credential']->openEnvelope())->getUserPHID()===$second_actor['viewer']->getPHID(),
    'native-media-current-browser-generation-authenticates-new-account');
  $failed=$prepare($secret);
  $before=governance_native_media_fingerprint($fixture);
  $trigger='`'.$fixture->getNamespace().'_user`.`media_issue_fault`';
  governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger.' BEFORE UPDATE ON '.
    $fixture->table('user','governance_mediaexchange').
    " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned media issue fault'");
  $trigger_row=governance_native_setup_query($fixture,
    "SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".
    $fixture->getNamespace()."_user' AND TRIGGER_NAME='media_issue_fault' AND ACTION_TIMING='BEFORE' AND EVENT_MANIPULATION='UPDATE'")->fetch_assoc();
  $check((int)$trigger_row['n']===1,'native-media-issue-fault-trigger-really-installed');
  try {
    governance_native_refused(fn()=>$issue($failed['transaction_id']),'media_exchange_issue_refused',
      $check,'native-media-failed-authenticated-issue-real-sql-fault-refused');
  } finally { governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger); }
  $check(governance_native_media_fingerprint($fixture)===$before,
    'native-media-failed-issue-rolls-back-browser-generation-and-credential-revocation');
  $check($media->authenticate($latest['credential']->openEnvelope())->getUserPHID()===$second_actor['viewer']->getPHID(),
    'native-media-failed-issue-preserves-current-account-credential');
  foreach (array('governance_mediabrowser','governance_mediaexchange','governance_mediacredential') as $table) {
    governance_native_setup_query($fixture,'ALTER TABLE '.$fixture->table('user',$table).' ENGINE=MyISAM');
    try {
      $before=governance_native_media_fingerprint($fixture);
      governance_native_refused(fn()=>$prepare(),'media_exchange_transactional_schema_required',
        $check,'native-media-'.$table.'-myisam-early-refused');
      $check(governance_native_media_fingerprint($fixture)===$before,
        'native-media-'.$table.'-myisam-refusal-no-write');
    } finally { governance_native_setup_query($fixture,'ALTER TABLE '.$fixture->table('user',$table).' ENGINE=InnoDB'); }
  }
  // The same issued code is presented by two independent PHP/MySQL processes.
  $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$state['admin']['token'];
  $race=$prepare(); $race_code=$issue($race['transaction_id']);
  $browser_digest=hash('sha256',$race['browser_secret']->openEnvelope());
  $transaction_digest=hash('sha256',$race['transaction_id']);
  $credential_before=$fixture->countRows('user','governance_mediacredential');
  $config=$fixture->getWriterConfig(); $report=(new mysqli_driver())->report_mode;
  $observer=null;
  try {
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $observer=new mysqli($config['host'],$config['user'],$config['password'],'',$config['port']);
    $admin=$fixture->getAdmin(); $admin->begin_transaction();
    try {
      $locked=governance_native_setup_query($fixture,'SELECT generation FROM '.
        $fixture->table('user','governance_mediabrowser')." WHERE browserDigest='".$browser_digest."' FOR UPDATE")->fetch_assoc();
      $action=array('action'=>'media.redeem','transaction'=>$race['transaction_id'],
        'code'=>$race_code['exchange_code']->openEnvelope(),
        'browser_secret'=>$race['browser_secret']->openEnvelope(),
        'audience'=>'phorge-files','purpose'=>'private-media','path'=>$path);
      $one=governance_native_start_worker($fixture,$action);
      $two=governance_native_start_worker($fixture,$action);
      governance_native_wait_worker_query($observer,array($one,$two),
        '/\bgovernance_mediabrowser\b.*FOR UPDATE/is');
      $check(true,'native-media-two-real-redeemers-wait-on-browser-lock');
      if (!$admin->commit()) { throw new RuntimeException('native_fixture_setup_commit_refused'); }
      $first=governance_native_finish_worker($one); $second=governance_native_finish_worker($two);
      $states=array($first['status'],$second['status']); sort($states);
      $check($states===array('committed','rejected'),
        'native-media-concurrent-code-redemption-has-one-commit');
      $success=$first['status']==='committed'?$first:$second;
      $failure=$first['status']==='rejected'?$first:$second;
      $check(($success['credential_authenticated']??null)===true &&
        ($failure['reason']??null)==='media_exchange_redemption_refused',
        'native-media-concurrent-success-authenticates-and-replay-is-refused');
      $exchange=governance_native_setup_query($fixture,'SELECT usedAt,generation FROM '.
        $fixture->table('user','governance_mediaexchange')." WHERE transactionDigest='".$transaction_digest."'")->fetch_assoc();
      $browser=governance_native_setup_query($fixture,'SELECT generation FROM '.
        $fixture->table('user','governance_mediabrowser')." WHERE browserDigest='".$browser_digest."'")->fetch_assoc();
      $check($exchange['usedAt']!==null && (string)$exchange['generation']===(string)$locked['generation'] &&
        (string)$browser['generation']===(string)$locked['generation'] &&
        $fixture->countRows('user','governance_mediacredential')===$credential_before+1,
        'native-media-concurrent-redemption-consumes-one-code-in-issued-generation');
    } finally { $admin->rollback(); }
  } finally { if ($observer!==null) { $observer->close(); } mysqli_report($report); }
  $state['media_credential']=$latest['credential']; $state['media_browser']=$p['browser_secret'];
  return array('actual_digest_and_browser_binding'=>true,'same_browser_generation_invalidation'=>true,'latest_authenticated_issue_wins'=>true,
    'failed_issue_atomic_rollback'=>true,
    'actual_three_table_engine_drift_refused'=>true,'concurrent_redemption_proved'=>true,
    'http_origin_cookie_and_active_content_proved'=>false);
}
