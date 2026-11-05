<?php
/** Isolated fixture diagnostics only: never include exception messages or args. */
function governance_notice_wire_diagnostic($phase, Throwable $error) {
  $phases=array('bootstrap','scope','request','library','proof','environment','configuration','dispatch');
  if(!in_array($phase,$phases,true)){throw new InvalidArgumentException('notice_diagnostic_phase_refused');}
  $class=get_class($error);$file=basename($error->getFile());
  return array('diagnostic'=>'notice_wire_router','phase'=>$phase,
    'error_class'=>preg_match('/\A[A-Za-z_][A-Za-z0-9_\\\\]{0,127}\z/D',$class)?$class:'Throwable',
    'source_file'=>preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D',$file)?$file:'unavailable',
    'source_line'=>max(1,(int)$error->getLine()));
}
