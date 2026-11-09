<?php

/** Only the reviewed empty-fixture PHP migration transcript is admissible. */
function governance_directory_restore_progress_lines() {
  return array(
    '20260911.differential.01.appemail.php'=>'No Differential application email addresses to release.',
    '20260911.paste.01.appemail.php'=>'No Paste application email addresses to release.',
    '20260918.differential.01.heraldrules.php'=>'No Herald rules test removed Differential revision fields.',
    '20260918.differential.02.diffpolicy.php'=>'No retained diffs inherited a restrictive revision policy.',
    '20260918.owners.01.heraldrules.php'=>'No Herald rules test removed Owners package fields.',
    '20260918.owners.02.auditrequests.php'=>'No commits carry Owners package audit requests.');
}
function governance_directory_restore_progress_validate($raw,$root) {
  $expected=governance_directory_restore_progress_lines();
  if(!is_string($raw)||strlen($raw)>4096||substr($raw,-1)!=="\n"){
    throw new RuntimeException('directory_restore_progress_refused');
  }
  $lines=explode("\n",substr($raw,0,-1));$wanted=array_values($expected);
  sort($lines,SORT_STRING);sort($wanted,SORT_STRING);
  if($lines!==$wanted){throw new RuntimeException('directory_restore_progress_refused');}
  $sources=array();
  foreach($expected as$name=>$line){$sources['resources/sql/autopatches/'.$name]=hash_file('sha256',$root.'/resources/sql/autopatches/'.$name);}
  return array('profile'=>'owned-empty-native-restore-progress-v1',
    'bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw),'line_count'=>count($lines),
    'exact_six_empty_lines_once'=>true,'separate_from_native_business_capture'=>true,
    'unknown_output_accepted'=>false,'source_sha256'=>$sources);
}
function governance_directory_capture_restore_progress(callable $create,$root) {
  $state=(object)array('depth'=>null,'finalized'=>false,'closed'=>false,
    'raw'=>'','bytes'=>0,'overflow'=>false,'fault'=>false);
  $started=ob_start(static function($chunk,$phase)use($state) {
    try {
      if(($phase & PHP_OUTPUT_HANDLER_FINAL)!==0){$state->finalized=true;}
      $state->bytes+=strlen($chunk);
      if($state->bytes<=4096){$state->raw.=$chunk;}else{$state->overflow=true;}
    }catch(Throwable $error){$state->fault=true;}
    // This buffer is exclusively the restore phase. Admission requires the
    // exact six-line transcript below; unknown output can never become PASS.
    return '';
  },1);
  if(!$started){throw new RuntimeException('directory_restore_capture_unavailable');}
  $state->depth=ob_get_level();$primary=null;
  try {$create();}catch(Throwable $error){$primary=$error;}
  finally {
    if(!$state->finalized&&ob_get_level()===$state->depth){
      try {ob_end_clean();$state->closed=$state->finalized;}catch(Throwable $error){$state->fault=true;}
    }
    // Never remove a same-depth replacement or an unknown deeper buffer.
  }
  if($primary!==null){throw $primary;}
  if(!$state->closed||$state->overflow||$state->fault){throw new RuntimeException('directory_restore_capture_refused');}
  return governance_directory_restore_progress_validate($state->raw,$root);
}
