<?php

/** Actual table-level media restore. Native authentication state is not restored or revoked. */
function governance_native_media_legacy_upgrade_cases($fixture, callable $check) {
  $tables=array('governance_mediabrowser','governance_mediaexchange','governance_mediacredential');
  foreach ($tables as $table) {
    if ($fixture->countRows('user',$table)!==0) { throw new RuntimeException('media_upgrade_fixture_empty_scope_required'); }
    governance_native_setup_query($fixture,'ALTER TABLE '.$fixture->table('user',$table).' DROP COLUMN issuerIncarnation');
  }
  // Reconstruct the previous released candidate schema, seed an actual old
  // SHA-only row, then execute the exact new source migration on real SQL.
  $secret=bin2hex(random_bytes(32)); $digest=hash('sha256',$secret);
  governance_native_setup_query($fixture,'INSERT INTO '.$fixture->table('user','governance_mediabrowser').
    " (browserDigest,generation,expiresAt) VALUES ('".$digest."',1,".(time()+300).')');
  $path=dirname(__DIR__,3).'/resources/sql/autopatches/20261103.people.mediaissuer.sql';
  $sql=preg_replace('/^--.*$/m','',file_get_contents($path));
  $sql=str_replace('{$NAMESPACE}',$fixture->getNamespace(),$sql);
  foreach (array_filter(array_map('trim',explode(';',$sql))) as $statement) {
    governance_native_setup_query($fixture,$statement);
  }
  $row=governance_native_setup_query($fixture,'SELECT issuerIncarnation FROM '.
    $fixture->table('user','governance_mediabrowser')." WHERE browserDigest='".$digest."'")->fetch_assoc();
  $check($row!==null && $row['issuerIncarnation']===null,
    'native-media-source-migration-keeps-real-v1-row-unassigned-to-current-issuer');
  $before=governance_native_media_fingerprint($fixture);
  governance_native_refused(fn()=>governance_native_prepare_media(new PhorgeGovernanceMediaExchangeService(),$secret),
    'media_exchange_preparation_refused',$check,'native-media-source-migrated-sha-only-browser-is-not-upgraded');
  $check(governance_native_media_fingerprint($fixture)===$before,
    'native-media-source-migration-legacy-refusal-performs-no-promotion');
  return array('scope'=>'disposable-v1-media-schema-reconstruction-and-real-source-v2-alter',
    'migration_sha256'=>hash_file('sha256',$path),'old_rows_reassigned_current_issuer'=>false);
}

function governance_native_media_snapshot($fixture) {
  $snapshot=array();
  foreach (array('governance_mediabrowser','governance_mediaexchange','governance_mediacredential') as $table) {
    $rows=governance_native_setup_query($fixture,'SELECT * FROM '.$fixture->table('user',$table))->fetch_all(MYSQLI_ASSOC);
    if (count($rows)>128) { throw new RuntimeException('media_restore_fixture_budget_refused'); }
    usort($rows,static fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));
    $snapshot[$table]=$rows;
  }
  return $snapshot;
}

function governance_native_restore_media_tables($fixture,array $snapshot) {
  $allowed=array('governance_mediabrowser','governance_mediaexchange','governance_mediacredential');
  if (array_keys($snapshot)!==$allowed) { throw new RuntimeException('media_restore_fixture_scope_refused'); }
  $mode=(new mysqli_driver())->report_mode; $admin=$fixture->getAdmin();
  try {
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $fixture->assertOwned(); $admin->begin_transaction();
    try {
      foreach (array_reverse($allowed) as $table) { $admin->query('DELETE FROM '.$fixture->table('user',$table)); }
      foreach ($snapshot as $table=>$rows) {
        if (count($rows)>128) { throw new RuntimeException('media_restore_fixture_budget_refused'); }
        foreach ($rows as $row) {
          $columns=array_keys($row);
          foreach ($columns as $column) {
            if (!preg_match('/\A[a-zA-Z][a-zA-Z0-9]*\z/',$column)) {
              throw new RuntimeException('media_restore_fixture_column_refused');
            }
          }
          $statement=$admin->prepare('INSERT INTO '.$fixture->table('user',$table).
            ' (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
          try { $statement->execute(array_values($row)); } finally { $statement->close(); }
        }
      }
      $admin->commit();
    } catch (Throwable $error) { $admin->rollback(); throw $error; }
  } finally { mysqli_report($mode); }
}

function governance_native_media_issuer_cases($fixture, callable $check, array &$state) {
  $fixture->assertOwned(); $media=$state['media'];
  $request=$state['admin']['request']; $viewer=$state['admin']['viewer'];
  $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$state['admin']['token'];
  $path='/file/data/key/PHID-FILE-ssssssssssssssssssss/name';
  $issue=static function($prepared) use ($media,$request,$path) {
    return $media->issue($request,$prepared['transaction_id'],'phorge-files','private-media',$path, governance_native_media_nonce($prepared['transaction_id']));
  };
  $redeem=static function($prepared,$issued) use ($media,$path) {
    return $media->redeem($prepared['transaction_id'],$issued['exchange_code']->openEnvelope(),
      $prepared['browser_secret']->openEnvelope(),'phorge-files','private-media',$path, governance_native_media_nonce($prepared['transaction_id']));
  };
  $issuer=PhorgeGovernanceMediaIssuer::newFromConfiguration();
  $legacy_secret=bin2hex(random_bytes(32));
  $mode=(new mysqli_driver())->report_mode;
  try {
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $statement=$fixture->getAdmin()->prepare('INSERT INTO '.$fixture->table('user','governance_mediabrowser').
      ' (browserDigest,generation,expiresAt,issuerIncarnation) VALUES (?,1,?,NULL)');
    try { $statement->execute(array($issuer->digest('browser',$legacy_secret),time()+300)); }
    finally { $statement->close(); }
  } finally { mysqli_report($mode); }
  $legacy_before=governance_native_media_fingerprint($fixture);
  governance_native_refused(fn()=>governance_native_prepare_media($media,$legacy_secret),
    'media_exchange_preparation_refused',$check,'native-media-null-legacy-incarnation-is-never-upgraded');
  $check(governance_native_media_fingerprint($fixture)===$legacy_before,
    'native-media-legacy-incarnation-refusal-does-not-promote-row');
  $db_entry=(new PhabricatorConfigEntry())->setNamespace('default')
    ->setConfigKey('governance.media-exchange.issuer-key')
    ->setValue(PhabricatorEnv::getEnvConfig('governance.media-exchange.issuer-key'))->setIsDeleted(0)->save();
  $stack=PhabricatorEnv::getConfigSourceStack();
  $db_source=new PhabricatorConfigDatabaseSource('default');
  $stack->pushSource($db_source);
  try {
    governance_native_refused(fn()=>PhorgeGovernanceMediaIssuer::newFromConfiguration(),
      'media_issuer_database_configuration_refused',$check,'native-media-issuer-never-accepts-secret-restored-from-config-database');
  } finally {
    if ($stack->popSource()!==$db_source) { throw new RuntimeException('media_restore_fixture_config_stack_changed'); }
    $db_entry->delete();
  }
  $pending=governance_native_prepare_media($media); $pending_code=$issue($pending);
  $snapshot=governance_native_media_snapshot($fixture);
  $snapshot_hash=hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR));
  $token=PhabricatorConduitToken::initializeNewToken($viewer->getPHID(),PhabricatorConduitToken::TYPE_STANDARD)->save();
  $conn=(new PhabricatorUser())->establishConnection('w');
  $native_state=static function() use ($conn,$viewer,$token) {
    return array(
      'session'=>queryfx_one($conn,'SELECT phid,userPHID,type,sessionKey,sessionStart,sessionExpires,isPartial FROM %R WHERE phid=%s',
        new PhabricatorAuthSession(),$viewer->getSession()->getPHID()),
      'api'=>queryfx_one($conn,'SELECT id,objectPHID,tokenType,token,expires FROM %R WHERE id=%d',new PhabricatorConduitToken(),$token->getID()),
      'account'=>queryfx_one($conn,'SELECT phid,isApproved,isDisabled,isSystemAgent,isMailingList,accountSecret,conduitCertificate FROM %R WHERE phid=%s',
        new PhabricatorUser(),$viewer->getPHID()));
  };
  $native_before=hash('sha256',json_encode($native_state(),JSON_THROW_ON_ERROR));
  $old_key=PhabricatorEnv::getEnvConfig('governance.media-exchange.issuer-key');
  $old_inc=PhabricatorEnv::getEnvConfig('governance.media-exchange.incarnation');
  $new_key=bin2hex(random_bytes(32)); $new_inc=bin2hex(random_bytes(16));
  PhabricatorEnv::overrideConfig('governance.media-exchange.issuer-key',$new_key);
  PhabricatorEnv::overrideConfig('governance.media-exchange.incarnation',$new_inc);
  // Write distinct current-incarnation rows, then actually restore the older
  // logical snapshot through SQL DELETE/INSERT under the owned setup role.
  $temporary=governance_native_prepare_media($media); $temporary_code=$issue($temporary); $redeem($temporary,$temporary_code);
  governance_native_restore_media_tables($fixture,$snapshot);
  $restored=governance_native_media_snapshot($fixture);
  $check(hash('sha256',json_encode($restored,JSON_THROW_ON_ERROR))===$snapshot_hash,
    'native-media-old-table-snapshot-restored-exactly-through-real-sql');
  $before=governance_native_media_fingerprint($fixture);
  governance_native_refused(fn()=>$media->authenticate($state['media_credential']->openEnvelope()),
    'media_credential_refused',$check,'native-media-restored-old-credential-refused-by-external-incarnation');
  governance_native_refused(fn()=>$redeem($pending,$pending_code),
    'media_exchange_redemption_refused',$check,'native-media-restored-old-unconsumed-code-refused');
  governance_native_refused(fn()=>governance_native_prepare_media($media,$state['media_browser']->openEnvelope()),
    'media_exchange_preparation_refused',$check,'native-media-restored-old-browser-slot-cannot-be-reused');
  $check(governance_native_media_fingerprint($fixture)===$before,
    'native-media-restored-old-state-is-not-promoted-or-upgraded');
  $loaded=(new PhabricatorAuthSessionEngine())->loadUserForSession(PhabricatorAuthSession::TYPE_WEB,$state['admin']['token']);
  $check($loaded instanceof PhabricatorUser && $loaded->getPHID()===$viewer->getPHID(),
    'native-media-only-rotation-keeps-underlying-native-session-authentication');
  $api_context=PhorgeGovernanceNativeActorContext::newFromAuthenticatedAPIToken($loaded,new PhutilOpaqueEnvelope($token->getToken()));
  $conn->openTransaction();
  try {
    $api=PhorgeGovernanceNativeIdentitySource::lockCurrent($api_context,$conn);
    $check($api->getCredentialType()==='api-token' && $api->getUserPHID()===$viewer->getPHID(),
      'native-media-only-rotation-keeps-underlying-native-api-authentication');
    $conn->saveTransaction();
  } catch (Throwable $error) { $conn->killTransaction(); throw $error; }
  $check(hash('sha256',json_encode($native_state(),JSON_THROW_ON_ERROR))===$native_before,
    'native-media-restore-does-not-revoke-replace-or-change-native-credential-metadata');
  PhabricatorEnv::overrideConfig('governance.media-exchange.issuer-key',null);
  try {
    governance_native_refused(fn()=>governance_native_prepare_media($media),
      'media_issuer_configuration_required',$check,'native-media-missing-external-key-does-not-fall-back-to-database-state');
  } finally { PhabricatorEnv::overrideConfig('governance.media-exchange.issuer-key',$new_key); }
  $fresh=governance_native_prepare_media($media); $fresh_code=$issue($fresh); $fresh_credential=$redeem($fresh,$fresh_code);
  $check($media->authenticate($fresh_credential['credential']->openEnvelope())->getUserPHID()===$viewer->getPHID(),
    'native-media-after-restore-requires-new-browser-transaction-and-current-issuer');
  // Independently exercise incarnation-only rotation: retain the exact key.
  $inc_pending=governance_native_prepare_media($media);$inc_code=$issue($inc_pending);
  PhabricatorEnv::overrideConfig('governance.media-exchange.incarnation',bin2hex(random_bytes(16)));
  $required=static function($ok,$name){if(!$ok){throw new RuntimeException('media_incarnation_axis_fixture_failed');}};
  governance_native_refused(fn()=>$media->authenticate($fresh_credential['credential']->openEnvelope()),
    'media_credential_refused',$required,'incarnation-only-old-credential');
  governance_native_refused(fn()=>$redeem($inc_pending,$inc_code),
    'media_exchange_redemption_refused',$required,'incarnation-only-old-code');
  governance_native_refused(fn()=>governance_native_prepare_media($media,$fresh['browser_secret']->openEnvelope()),
    'media_exchange_preparation_refused',$required,'incarnation-only-old-browser');
  $fresh=governance_native_prepare_media($media);$fresh_code=$issue($fresh);$fresh_credential=$redeem($fresh,$fresh_code);
  $inc_loaded=(new PhabricatorAuthSessionEngine())->loadUserForSession(PhabricatorAuthSession::TYPE_WEB,$state['admin']['token']);
  $conn->openTransaction();
  try{$inc_api=PhorgeGovernanceNativeIdentitySource::lockCurrent($api_context,$conn);$conn->saveTransaction();}
  catch(Throwable $error){$conn->killTransaction();throw $error;}
  $check(PhabricatorEnv::getEnvConfig('governance.media-exchange.issuer-key')===$new_key &&
    $media->authenticate($fresh_credential['credential']->openEnvelope())->getUserPHID()===$viewer->getPHID() &&
    $inc_loaded instanceof PhabricatorUser && $inc_loaded->getPHID()===$viewer->getPHID() &&
    $inc_api->getCredentialType()==='api-token' && $inc_api->getUserPHID()===$viewer->getPHID() &&
    hash('sha256',json_encode($native_state(),JSON_THROW_ON_ERROR))===$native_before,
    'native-media-incarnation-only-rotation-rejects-all-old-media-retains-native-and-allows-fresh');
  $state['media_credential']=$fresh_credential['credential']; $state['media_browser']=$fresh['browser_secret'];
  // The external configuration intentionally stays at the new incarnation.
  // No native session/API credential was revoked, reissued or rotated.
  unset($old_key,$old_inc,$new_key);
  return array('restore_scope'=>'three-media-tables-logical-snapshot-and-real-sql-restore',
    'restored_snapshot_sha256'=>$snapshot_hash,'media_only_external_rotation'=>true,'incarnation_only_rotation_verified'=>true,
    'old_native_credentials_retained_and_verified'=>true,'native_credential_metadata_changed'=>false,
    'old_media_rows_promoted'=>false,'full_disaster_recovery_certified'=>false);
}
