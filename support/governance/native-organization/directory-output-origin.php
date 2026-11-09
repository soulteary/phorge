<?php

/** Test-only observer. Preserve every chunk; never record chunk/value/args. */
function governance_directory_output_public_frame($frame,$root) {
  $file=$frame['file']??null;$number=$frame['line']??null;$prefix=rtrim($root,'/').'/';
  if(!is_string($file)){return array('scope'=>'no-file','source'=>'unknown-source','line'=>0);}
  if(strncmp($file,$prefix,strlen($prefix))!==0){return array('scope'=>'outside-repository','source'=>'unknown-source','line'=>0);}
  $relative=substr($file,strlen($prefix));$parts=explode('/',$relative);
  if(is_int($number)&&$number>=1&&$number<=1000000&&strlen($relative)<=384&&
    preg_match('/\A(?:src|support)\/[A-Za-z0-9_.\/-]+\.php\z/D',$relative)&&
    !array_intersect($parts,array('','.','..'))){return array('scope'=>'public','source'=>$relative,'line'=>$number);}
  return array('scope'=>'repository-outsideallowed','source'=>'unknown-source','line'=>0);
}
function governance_directory_output_chunk_kind($chunk) {
  $prefix=substr($chunk,0,128);
  if(strlen($chunk)<=128&&preg_match('/\A[ \t\r\n\v\f]*\z/D',$chunk)){return 'whitespace';}
  if(strncmp($prefix,"\x89PNG\r\n\x1a\n",8)===0){return 'PNGsignature';}
  foreach(array('Deprecated','Notice','Warning')as$kind){
    if(preg_match('/\A[ \t\r\n]*(?:PHP )?'.$kind.':/D',$prefix)){return $kind;}
  }
  return 'unclassified';
}
function governance_directory_output_origin_start($root) {
  $state=(object)array('outer_depth'=>ob_get_level(),'observer_depth'=>null,
    'origin'=>null,'ended'=>false,'owned_finalized'=>false,
    'first_frame_scope'=>'no-file','first_chunk_kind'=>'unclassified','public_hints'=>array());
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
          $first=governance_directory_output_public_frame($trace[0]??array(),$root);
          $state->origin=array('source'=>$first['source'],'line'=>$first['line']);
          $state->first_frame_scope=$first['scope'];
          $state->first_chunk_kind=governance_directory_output_chunk_kind($chunk);
          foreach($trace as$position=>$frame){
            $public=governance_directory_output_public_frame($frame,$root);
            if($public['scope']==='public'){$state->public_hints[]=array('frame'=>$position,'source'=>$public['source'],'line'=>$public['line']);}
          }
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
    @fwrite(STDERR,'native-directory-output-hints-v1 '.json_encode(array(
      'schema'=>'native-directory-output-hints-v1','evidence_kind'=>'public-stack-hints-not-emitting-origin',
      'first_frame_scope'=>is_object($state)?$state->first_frame_scope:'no-file',
      'first_chunk_kind'=>is_object($state)?$state->first_chunk_kind:'unclassified',
      'hints'=>is_object($state)?$state->public_hints:array()),JSON_UNESCAPED_SLASHES)."\n");
  }catch(Throwable $diagnostic_error){ /* Keep the original outcome unchanged. */ }
}
