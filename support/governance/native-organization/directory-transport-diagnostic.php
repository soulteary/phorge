<?php

/** Failure diagnostics only. Never emit message, arguments, identity or DSN. */
function governance_directory_transport_emit_failure(Throwable $error,$root,$stage,$kind='primary') {
  try {
    $allow=json_decode(file_get_contents(__DIR__.'/directory-transport-diagnostic-v1.json'),true,8,JSON_THROW_ON_ERROR);
    if(($allow['schema']??null)!=='native-directory-failure-allowlist-v1' || !in_array($kind,array('primary','cleanup'),true)){
      return;
    }
    $class=get_class($error);
    if(!in_array($class,$allow['classes'],true)){$class='OtherThrowable';}
    if(!is_string($stage)||!in_array($stage,$allow['stages'],true)){$stage='unknown-stage';}
    $file=$error->getFile();$prefix=rtrim($root,'/').'/';$relative='unknown-source';$line=0;
    if(strncmp($file,$prefix,strlen($prefix))===0){
      $candidate=substr($file,strlen($prefix));$parts=explode('/',$candidate);
      if(strlen($candidate)<=384 && preg_match('/\A(?:src|support)\/[A-Za-z0-9_.\/-]+\.php\z/D',$candidate) &&
        !array_intersect($parts,array('','.','..'))){
        $relative=$candidate;$line=$error->getLine();
      }
    }
    if(!is_int($line)||$line<0||$line>1000000){$relative='unknown-source';$line=0;}
    fwrite(STDERR,'native-directory-transports-failure-v1 '.json_encode(array(
      'schema'=>'native-directory-failure-marker-v1','kind'=>$kind,'class'=>$class,
      'source'=>$relative,'line'=>$line,'stage'=>$stage),JSON_UNESCAPED_SLASHES)."\n");
  }catch(Throwable $diagnostic_error){
    // A failed optional diagnostic must not replace or clear the primary failure.
  }
}

/** Fixed terminal classification; no warning text or claimed origin. */
function governance_directory_transport_emit_runtime_warning() {
  try {
  fwrite(STDERR,'native-directory-transports-failure-v1 '.json_encode(array(
    'schema'=>'native-directory-failure-marker-v1','kind'=>'primary',
    'class'=>'RuntimeWarning','source'=>'unknown-source','line'=>0,
    'stage'=>'unexpected-native-runtime-output'),JSON_UNESCAPED_SLASHES)."\n");
  }catch(Throwable $diagnostic_error){ /* Preserve the original failed outcome. */ }
}
