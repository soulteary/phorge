<?php

/** Public failure metadata only. Never accepts raw exception messages or inputs. */
function governance_recovery_partial_failure_projection($phase,$passed,$failure) {
  if($phase!=='recovery-partial-composition'||$passed!==false){return null;}
  $stages=array(
    'preflight',
    'full-native-source-restore',
    'focused-native-recovery-partial-composition',
    'owned-real-gorge-file-service',
    'unexpected-native-runtime-output',
    'full-current-source-quickstart-and-patch-ledger-restored',
    'completion-reader-real-select-only-grants-and-lineage-proved',
    'completion-writer-forbidden-privilege-denied',
    'completion-writer-cannot-modify-ownership-marker',
    'completion-reader-forbidden-privilege-denied',
    'completion-reader-cannot-modify-ownership-marker',
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
  $types=array('RuntimeException','LogicException','TypeError','Error','mysqli_sql_exception',
    'PhutilAggregateException','PhabricatorApplicationTransactionValidationException',
    'PhabricatorApplicationTransactionNoEffectException','AphrontQueryException',
    'AphrontObjectMissingQueryException','InvalidArgumentException','RuntimeWarning');
  $out=array('schema'=>'native-partial-failure-diagnostic-v1','passed'=>false,
    'stage'=>'unknown-stage','error_type'=>'other-error-type','source_file'=>null,'source_line'=>null);
  if($failure===null){$out['stage']='cleanup-unproved';$out['error_type']='CleanupFailure';return $out;}
  if(!is_array($failure)){return $out;}
  if(is_string($failure['stage']??null)&&in_array($failure['stage'],$stages,true)){$out['stage']=$failure['stage'];}
  if(is_string($failure['error_type']??null)&&in_array($failure['error_type'],$types,true)){$out['error_type']=$failure['error_type'];}
  $file=$failure['source_file']??null;
  if(is_string($file)&&preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,127}\.php\z/D',$file)){$out['source_file']=$file;}
  $line=$failure['source_line']??null;
  if(is_int($line)&&$line>0&&$line<=10000000){$out['source_line']=$line;}
  $errno=$failure['sql_errno']??null;
  if(is_int($errno)&&$errno>0&&$errno<=65535){$out['sql_errno']=$errno;}
  return $out;
}
