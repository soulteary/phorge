<?php
/** Failure-only fixed projection. Never reads validation messages or values. */
function governance_spaces_editor_failure($phase,$error) {
  $phases=array('create-user-visible','create-restricted-noone','create-restricted-admin','archive','description-rollback');
  if(!in_array($phase,$phases,true)||get_class($error)!==PhabricatorApplicationTransactionValidationException::class){throw new RuntimeException('spaces_editor_projection_unknown');}
  $errors=$error->getErrors();
  if(!is_array($errors)||count($errors)>16){throw new RuntimeException('spaces_editor_projection_budget');}
  $rows=array();
  $types=array('spaces:name','spaces:description','spaces:archive','spaces:default','core:view-policy','core:edit-policy','core:space');
  foreach($errors as $item){
    if(!is_object($item)||get_class($item)!==PhabricatorApplicationTransactionValidationError::class){throw new RuntimeException('spaces_editor_projection_shape');}
    $type=$item->getType();$missing=$item->getIsMissingFieldError();$transaction=$item->getTransaction();
    if($missing!==null&&!is_bool($missing)){throw new RuntimeException('spaces_editor_projection_missing_unknown');}
    $transaction_type=null;
    if($transaction!==null){
      if(get_class($transaction)!==PhabricatorSpacesNamespaceTransaction::class){throw new RuntimeException('spaces_editor_projection_transaction_unknown');}
      $value=$transaction->getTransactionType();$transaction_type=in_array($value,$types,true)?$value:'unknown';
    }
    $rows[]=array('type'=>in_array($type,$types,true)?$type:'unknown','missing_field'=>$missing===null?'unset':($missing?'true':'false'),'transaction_present'=>$transaction!==null,'transaction_type'=>$transaction_type);
  }
  return array('phase'=>$phase,'validation_error_count'=>count($rows),'validation_errors'=>$rows,'diagnostic_only'=>true);
}
function governance_spaces_editor_attempt($phase,callable $body){
  try{return $body();}catch(Throwable $error){
    if(get_class($error)===PhabricatorApplicationTransactionValidationException::class){
      try{$GLOBALS['governance_spaces_editor_failure']=governance_spaces_editor_failure($phase,$error);}
      catch(Throwable $projection_error){$GLOBALS['governance_spaces_editor_failure']=array('diagnostic_unavailable'=>true);}
    }
    throw $error;
  }
}
