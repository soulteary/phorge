<?php

/** Test-only observer. Preserve every chunk; never record chunk/value/args. */
function governance_directory_output_origin_start($root) {
  $state=(object)array('outer_depth'=>ob_get_level(),'observer_depth'=>null,
    'origin'=>null,'ended'=>false,'owned_finalized'=>false);
  if($state->outer_depth<1){return $state;}
  try {
    $started=@ob_start(static function($chunk,$phase)use($state,$root) {
      try {
        // FINAL also arrives for ob_end_clean() with an empty chunk. Record
        // our own removal before any origin inspection or diagnostic fault.
        if(($phase & PHP_OUTPUT_HANDLER_FINAL)!==0){$state->owned_finalized=true;}
        if(strlen($chunk)>0 && $state->origin===null){
          // The first frame is the actual emitting PHP instruction. Do not
          // substitute an outer public caller for a private/unknown origin.
          $trace=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,8);
          $frame=$trace[0]??array();$source='unknown-source';$line=0;
          $file=$frame['file']??null;$number=$frame['line']??null;
          $prefix=rtrim($root,'/').'/';
          if(is_string($file)&&is_int($number)&&$number>=1&&$number<=1000000&&
            strncmp($file,$prefix,strlen($prefix))===0){
            $relative=substr($file,strlen($prefix));$parts=explode('/',$relative);
            if(strlen($relative)<=384&&preg_match('/\A(?:src|support)\/[A-Za-z0-9_.\/-]+\.php\z/D',$relative)&&
              !array_intersect($parts,array('','.','..'))){$source=$relative;$line=$number;}
          }
          $state->origin=array('source'=>$source,'line'=>$line);
        }
      }catch(Throwable $diagnostic_error){ /* Never alter the emitted bytes. */ }
      return $chunk;
    },1);
    if($started){$state->observer_depth=ob_get_level();}
  }catch(Throwable $diagnostic_error){ /* Original private capture remains. */ }
  return $state;
}
function governance_directory_output_origin_finish($state) {
  try {
    if(is_object($state)&&$state->observer_depth!==null&&!$state->owned_finalized&&
      ob_get_level()===$state->observer_depth){
      // Flush only our observer into the original private outer buffer.
      ob_end_flush();$state->ended=$state->owned_finalized&&ob_get_level()===$state->outer_depth;
    }
  }catch(Throwable $diagnostic_error){ /* Diagnostic failure is not acceptance. */ }
}
function governance_directory_output_origin_emit($state) {
  try {
    $origin=is_object($state)?$state->origin:null;
    if(!is_array($origin)){$origin=array('source'=>'unknown-source','line'=>0);}
    // The public artifact projector additionally requires a currently tracked
    // regular src/support PHP file and a real line within that exact source.
    @fwrite(STDERR,'native-directory-transports-failure-v1 '.json_encode(array(
      'schema'=>'native-directory-failure-marker-v1','kind'=>'primary',
      'class'=>'CapturedNativeOutput','source'=>$origin['source'],'line'=>$origin['line'],
      'stage'=>'captured-native-output-write'),JSON_UNESCAPED_SLASHES)."\n");
  }catch(Throwable $diagnostic_error){ /* Keep the original outcome unchanged. */ }
}
