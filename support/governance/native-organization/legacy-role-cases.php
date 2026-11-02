<?php

/** Real legacy role editors, epoch ABA and failure cleanup; no simulated grants. */
function governance_native_legacy_role_cases($fixture, callable $check, array $state) {
  $fixture->assertOwned();
  $conn=(new PhabricatorUser())->establishConnection('w');
  $admin=$fixture->getAdmin(); $namespace=$fixture->getNamespace();
  $sessions=$state['sessions']; $actor=$state['users']['admin'];
  $media=$state['media']; $path='/file/data/key/PHID-FILE-synthetic/name';
  $queue_deltas=array();
  $new_user=function($suffix,$is_admin=false) use ($namespace) {
    return (new PhabricatorUser())->setUsername('gl'.$suffix.substr($namespace,-10))
      ->setRealName('Synthetic Legacy '.$suffix)->setIsApproved(1)->setIsAdmin($is_admin?1:0)
      ->setIsEmailVerified(1)->save();
  };
  foreach (array('agent'=>'makeSystemAgentUser','mail'=>'makeMailingListUser') as $kind=>$method) {
    $user=$new_user($kind); $phid=$user->getPHID();
    $request=governance_native_request($user,$sessions);
    $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$request['token'];
    $browser=$media->beginBrowserTransaction();
    $issued=$media->issue($request['request'],$browser['transaction_id'],'phorge-files','private-media',$path);
    $redeemed=$media->redeem($browser['transaction_id'],$issued['exchange_code']->openEnvelope(),
      $browser['browser_secret']->openEnvelope(),'phorge-files','private-media',$path);
    $media->authenticate($redeemed['credential']->openEnvelope());
    $before=queryfx_one($conn,'SELECT epoch FROM organization_identityepoch WHERE userPHID=%s',$phid);
    $transaction_before=queryfx_one($conn,'SELECT MAX(id) n FROM %R WHERE BINARY objectPHID=BINARY %s',new PhabricatorUserTransaction(),$phid);
    foreach (array(true,false) as $value) {
      $current=(new PhabricatorUser())->loadOneWhere('phid=%s',$phid);
      $editor=(new PhabricatorUserEditor())->setActor($actor);
      $editor->$method($current,$value);
    }
    $after=queryfx_one($conn,'SELECT epoch FROM organization_identityepoch WHERE userPHID=%s',$phid);
    $transaction_after=queryfx_one($conn,'SELECT MAX(id) n FROM %R WHERE BINARY objectPHID=BINARY %s',new PhabricatorUserTransaction(),$phid);
    $check((int)$after['epoch']===(int)$before['epoch']+2 && $transaction_before['n']===$transaction_after['n'],
      'native-legacy-'.$kind.'-aba-increments-independent-epoch-without-native-user-transaction');
    governance_native_refused(function() use ($media,$redeemed) {
      $media->authenticate($redeemed['credential']->openEnvelope());
    },'media_credential_refused',$check,'native-legacy-'.$kind.'-aba-does-not-revive-old-media-credential');
    $fresh=governance_native_request((new PhabricatorUser())->loadOneWhere('phid=%s',$phid),$sessions);
    $check(is_array(PhorgeOrganizationService::newForActor($fresh['context'])->readUnits()),
      'native-legacy-'.$kind.'-fresh-session-after-role-roundtrip-usable');

    $user_before=queryfx_one($conn,'SELECT isSystemAgent,isMailingList FROM %R WHERE BINARY phid=BINARY %s',new PhabricatorUser(),$phid);
    $epoch_before=queryfx_one($conn,'SELECT epoch FROM organization_identityepoch WHERE userPHID=%s',$phid);
    $queue_before=$fixture->countRows('worker','worker_activetask');
    $trigger_name='native_legacy_'.$kind.'_epoch_fault';
    $trigger='`'.$namespace.'_user`.`'.$trigger_name.'`';
    $installed=false;
    try {
      $admin->query('CREATE TRIGGER '.$trigger.' BEFORE UPDATE ON '.$fixture->table('user','organization_identityepoch').
        " FOR EACH ROW BEGIN IF BINARY NEW.userPHID=BINARY '".$admin->real_escape_string($phid).
        "' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned legacy epoch fault'; END IF; END");
      $installed=true;
      $statement=$admin->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=? AND TRIGGER_NAME=?');
      $schema=$namespace.'_user'; $statement->execute(array($schema,$trigger_name));
      $exists=(int)$statement->get_result()->fetch_row()[0]; $statement->close();
      $check($exists===1,'native-legacy-'.$kind.'-fault-trigger-actually-installed');
      // Preserve a caller's outer write-lock level even when the new epoch hook
      // fails. The role editor must release exactly its own nested lock.
      $conn->beginWriteLocking();
      try {
        $failed=false;
        try {
          $current=(new PhabricatorUser())->loadOneWhere('phid=%s',$phid);
          (new PhabricatorUserEditor())->setActor($actor)->$method($current,true);
        } catch (AphrontQueryException $error) {
          if ($error->getCode()!==1644 || strpos($error->getMessage(),'owned legacy epoch fault')===false) { throw $error; }
          $failed=true;
        }
        $check($failed,'native-legacy-'.$kind.'-actual-epoch-sql-fault-observed');
        $check(!$conn->isInsideTransaction() && $conn->isWriteLocking(),
          'native-legacy-'.$kind.'-fault-rolls-back-and-preserves-only-outer-lock');
      } finally { $conn->endWriteLocking(); }
    } finally {
      if ($installed) { $admin->query('DROP TRIGGER '.$trigger); }
    }
    $user_after=queryfx_one($conn,'SELECT isSystemAgent,isMailingList FROM %R WHERE BINARY phid=BINARY %s',new PhabricatorUser(),$phid);
    $epoch_after=queryfx_one($conn,'SELECT epoch FROM organization_identityepoch WHERE userPHID=%s',$phid);
    $check($user_before===$user_after && $epoch_before===$epoch_after,
      'native-legacy-'.$kind.'-fault-leaves-native-role-and-epoch-unchanged');
    $check(!$conn->isInsideTransaction() && !$conn->isWriteLocking() && !$conn->isReadLocking(),
      'native-legacy-'.$kind.'-fault-no-owned-lock-or-transaction-leak');
    $queue_deltas[$kind]=$fixture->countRows('worker','worker_activetask')-$queue_before;
  }

  // A committed operation is not a perpetual authorization ticket.
  $replay_user=$new_user('replay',true);
  $request=governance_native_request($replay_user,$sessions);
  $service=PhorgeOrganizationService::newForActor($request['context']);
  $input=array('operation_id'=>'native-legacy-replay-op','unit_id'=>'native-legacy-replay-team',
    'kind'=>'team','name'=>'Synthetic Replay Team','parent_id'=>null);
  $service->createUnit($input);
  $mfa_actor=governance_native_request($actor,$sessions);
  $factors=(new PhabricatorAuthFactorConfigQuery())->setViewer($mfa_actor['viewer'])
    ->withUserPHIDs(array($mfa_actor['viewer']->getPHID()))
    ->withFactorProviderStatuses(array(PhabricatorAuthFactorProviderStatus::STATUS_ACTIVE,
      PhabricatorAuthFactorProviderStatus::STATUS_DEPRECATED))->execute();
  $check(!$factors && !$mfa_actor['viewer']->getSession()->isHighSecuritySession(),
    'native-replay-revocation-actor-has-actual-no-factor-normal-session');
  $transaction=(new PhabricatorUserTransaction())
    ->setTransactionType(PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE)->setNewValue(false);
  (new PhabricatorUserTransactionEditor())->setActor($mfa_actor['viewer'])
    ->setContentSourceFromRequest($mfa_actor['request'])
    ->setCancelURI('/people/manage/'.$replay_user->getID().'/')
    ->applyTransactions((new PhabricatorUser())->loadOneWhere('phid=%s',$replay_user->getPHID()),array($transaction));
  $check(!$mfa_actor['viewer']->getSession()->isHighSecuritySession(),
    'native-replay-revocation-uses-native-no-factor-mfa-path-without-forced-high-security');
  governance_native_refused(function() use ($service,$input) { $service->createUnit($input); },
    'organization_system_admin_required',$check,'native-committed-operation-replay-refused-after-real-admin-revocation');
  $row=queryfx_one($conn,'SELECT COUNT(*) n FROM organization_operation WHERE operationID=%s',$input['operation_id']);
  $check((int)$row['n']===1 && !$conn->isInsideTransaction(),
    'native-revoked-replay-keeps-one-ledger-record-and-cleans-transaction');
  return array('legacy_role_epoch_aba_verified'=>true,'legacy_role_sql_fault_verified'=>true,
    'legacy_role_outer_lock_preserved'=>true,'native_revoked_operation_replay_refused'=>true,
    'failed_role_search_queue_deltas'=>$queue_deltas,
    'rollback_claim'=>'native user role and identityepoch only; existing indexing enqueue effects measured separately');
}
