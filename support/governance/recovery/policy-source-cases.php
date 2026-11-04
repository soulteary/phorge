<?php

/** Called only inside the owned restoration fixture environment; UNRUN. */
function governance_recovery_policy_source_cases($fixture,callable $check) {
  $fixture->assertOwned();
  $conn=(new PhabricatorUser())->establishConnection('w');
  if ($conn->isInsideTransaction()) {throw new RuntimeException('recovery_policy_fixture_outer_transaction');}
  // Native DAO setup precedes the People transaction. No fake policy decision
  // or caller-supplied completeness bit is used by the source adapter.
  $policy=(new PhabricatorPolicy())->setRules(array())
    ->setDefaultAction(PhabricatorPolicy::ACTION_DENY)->save();
  $namespace=$fixture->getNamespace();
  $conn->openTransaction();
  try {
    $facts=PhorgeRecoveryRestorePolicySource::capture($conn);
    $check($conn->isInsideTransaction()&&$facts['current_rows_locked']===true&&
      $facts['empty_range_absence_proved']===false&&$facts['complete']===false&&
      $facts['restoration_authorized']===false&&
      count($facts['selected_source_rows']['policy'])>=1,
      'recovery-policy-real-native-dao-row-captured-without-completion');
    $before=PhorgeRecoveryRestoreRequest::digest($facts);
    queryfx($conn,'UPDATE %T.policy SET defaultAction=%s WHERE id=%d',
      $namespace.'_policy',PhabricatorPolicy::ACTION_ALLOW,$policy->getID());
    $changed=PhorgeRecoveryRestorePolicySource::capture($conn);
    $check($before!==PhorgeRecoveryRestoreRequest::digest($changed)&&
      $changed['complete']===false&&$conn->isInsideTransaction(),
      'recovery-policy-current-locked-mutation-changes-source-digest');
    queryfx($conn,'UPDATE %T.policy SET rules=%s WHERE id=%d',
      $namespace.'_policy',str_repeat('x',PhorgeRecoveryRestorePolicySource::MAX_ROW_BYTES+1),$policy->getID());
    $refused=false;
    try {PhorgeRecoveryRestorePolicySource::capture($conn);}
    catch (RuntimeException $error) {
      if (get_class($error)!==RuntimeException::class || $error->getMessage()!=='recovery_policy_byte_budget') {
        throw $error;
      }
      $refused=true;
    }
    $check($refused&&$conn->isInsideTransaction(),
      'recovery-policy-real-longtext-budget-refused-with-caller-transaction-preserved');
  } finally {
    if ($conn->isInsideTransaction()) {$conn->killTransaction();}
  }
  $row=queryfx_one($conn,'SELECT defaultAction,rules FROM %T.policy WHERE id=%d',
    $namespace.'_policy',$policy->getID());
  $check(!$conn->isInsideTransaction()&&$row['defaultAction']===PhabricatorPolicy::ACTION_DENY&&
    $row['rules']===phutil_json_encode(array()),
    'recovery-policy-caller-rollback-restores-real-native-source-bytes');
}
