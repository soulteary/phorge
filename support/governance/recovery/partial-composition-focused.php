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
  governance_recovery_restore_review_cases($fixture,$check);
  return array('profile'=>'native-recovery-partial-composition-v1','source_revision'=>$revision,
    'assessment_schema'=>3,'actual_current_writer_adapters'=>array('application-config','policy','project-relationships','spaces'),
    'Task_File_owned_RR_combined'=>false,'complete'=>false,'all_sources_complete'=>false,
    'restoration_authorized'=>false,'production_activation'=>false);
}
