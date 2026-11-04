<?php
require_once __DIR__.'/notice-wire-config.php';
foreach(array('raw-disable-enable','source-rollback','allow','between-attempts')as$case){
  if(governance_notice_wire_case($case)!==$case){throw new RuntimeException('fixed_case_failed');}}
foreach(array('raw-anything','source-rollback/other',null,true)as$case){
  $refused=false;try{governance_notice_wire_case($case);}catch(RuntimeException$error){$refused=true;}
  if(!$refused){throw new RuntimeException('unknown_case_accepted');}}
echo "2 fixed-case controls passed\n";
