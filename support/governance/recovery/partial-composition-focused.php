<?php

/** Root-owned native runner may dispatch this only inside its fresh full fixture. */
function governance_recovery_partial_composition_focused($fixture,callable $check) {
  $fixture->assertOwned();
  $revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
  if(!is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/D',$revision)){
    throw new RuntimeException('recovery_partial_revision_required');}
  require_once dirname(__DIR__).'/native-organization/native-cases.php';
  require_once dirname(__DIR__).'/native-organization/workers.php';
  require_once __DIR__.'/account-cases.php';
  require_once __DIR__.'/restore-review-cases.php';
  $checks=array();
  $record=static function($condition,$name)use($check,&$checks){
    if($condition!==true||!is_string($name)||isset($checks[$name])){
      throw new RuntimeException('recovery_partial_case_result_refused');}
    $checks[$name]=true;$check($condition,$name);
  };
  $result=governance_recovery_restore_review_cases($fixture,$record);
  governance_recovery_partial_composition_validate_result($result,array_keys($checks));
  return array('profile'=>'native-recovery-partial-composition-file-v2','source_revision'=>$revision,
    'assessment_schema'=>3,'actual_current_writer_adapters'=>array('application-config','policy','project-relationships','spaces'),
    'Task_File_owned_RR_combined'=>false,'complete'=>false,'all_sources_complete'=>false,
    'restoration_authorized'=>false,'production_activation'=>false);
}

/** Validate actual executed case results before emitting a measured summary. */
function governance_recovery_partial_composition_validate_result($result,array $checks) {
  $expected_result=array('review_ledger_candidate'=>true,'source_family_complete'=>false,
    'restoration_executed'=>false,'all_sources_complete'=>false,'production_activation'=>false);
  if(!is_array($result)){throw new RuntimeException('recovery_partial_case_result_refused');}
  ksort($result,SORT_STRING);ksort($expected_result,SORT_STRING);
  $expected_checks=array(
    'recovery-partial-real-config-source-change-invalidates-durable-preview',
    'recovery-partial-real-policy-budget-fails-closed-without-review-write',
    'recovery-partial-real-policy-row-addition-invalidates-durable-review',
    'recovery-partial-real-project-row-change-invalidates-durable-preview',
    'recovery-partial-real-space-row-change-invalidates-durable-preview',
    'recovery-partial-restored-config-remains-blocked',
    'recovery-partial-restored-policy-rows-remain-blocked',
    'recovery-restore-direct-locked-account-web-api-readers-preserve-caller-transaction',
    'recovery-restore-history-fixed-metadata-without-binding-or-reason',
    'recovery-restore-history-rechecks-actual-logged-out-session',
    'recovery-restore-independent-review-is-not-a-permit',
    'recovery-restore-live-independent-admin-preview-exposes-blockers-not-credentials-or-permit',
    'recovery-restore-missing-external-incarnation-refuses-without-domain-write',
    'recovery-restore-native-submission-persists-blocked-review',
    'recovery-restore-ordinary-current-session-cannot-submit',
    'recovery-restore-owned-setup-proves-real-audit-trigger-installed',
    'recovery-restore-real-review-audit-fault-rolls-ticket-operation-and-audit-back',
    'recovery-restore-real-review-cannot-bypass-incomplete-families',
    'recovery-restore-requester-cannot-self-review',
    'recovery-restore-retained-database-review-refused-after-external-incarnation-change',
    'recovery-restore-submit-ledger-replay-no-second-audit');
  sort($checks,SORT_STRING);sort($expected_checks,SORT_STRING);
  if($result!==$expected_result||$checks!==$expected_checks){
    throw new RuntimeException('recovery_partial_case_result_refused');}
}
