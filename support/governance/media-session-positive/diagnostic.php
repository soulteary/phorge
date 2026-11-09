<?php
/** One fixed-shape public hint, never exception text, SQL, arguments or trace. */
function media_positive_diagnostic($error,$root,$phase) {
  if($error===null){return null;}
  if(!($error instanceof Throwable)||!in_array($phase,array('primary','cleanup-native-writer','cleanup-control-close'),true)){
    throw new InvalidArgumentException('media_positive_diagnostic_input');
  }
  $class=get_class($error);
  if(!preg_match('/\A[A-Za-z_][A-Za-z0-9_\\\\]{0,191}\z/D',$class)){$class='Throwable';}
  $source='external';$line=0;$canonical=realpath($root);$file=$error->getFile();
  if(is_string($canonical)&&$canonical===$root&&is_string($file)&&realpath($file)===$file&&str_starts_with($file,$root.'/')){
    $relative=substr($file,strlen($root)+1);
    if(strlen($relative)<=240&&preg_match('@\A(?:src|support|resources)/[A-Za-z0-9_./-]+\.php\z@D',$relative)&&
        !preg_match('@(?:\A|/)\.{1,2}(?:/|\z)@D',$relative)&&
        is_int($error->getLine())&&$error->getLine()>0&&$error->getLine()<10000000){
      $source=$relative;$line=$error->getLine();
    }
  }
  return array('phase'=>$phase,'exception_class'=>$class,'source'=>$source,'line'=>$line);
}
