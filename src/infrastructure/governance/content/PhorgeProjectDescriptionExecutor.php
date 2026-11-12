<?php

/** One sealed server-side dispatch. No Web/Conduit registration or EDIT grant. */
final class PhorgeProjectDescriptionExecutor extends Phobject {
  const TYPE='governance:project-description';
  const FIELD='std:project:internal:description';
  private static $calls;
  public static function execute(PhorgeGovernanceNativeActorContext $context,array $payload) {
    PhorgeProjectContentGrantService::assertEnabled();
    if($context->getCredentialType()!=='api-token'){throw new RuntimeException('description_native_web_MFA_entry_pending');}
    $request=PhorgeProjectDescriptionRequest::newFromPayload($payload);
    $project=(new PhabricatorProject())->loadOneWhere('phid=%s',$request->getTargetPHID());
    if(!$project){throw new RuntimeException('description_target_unavailable');}
    $viewer=(new PhabricatorUser())->loadOneWhere('phid=%s',$context->getUserPHID());
    if(!$viewer){throw new RuntimeException('description_actor_unavailable');}
    $editor=$project->getApplicationTransactionEditor()->setActor($viewer)->setProjectApprovalActorContext($context)
      ->setContentSource(PhabricatorContentSource::newForSource(PhabricatorConduitContentSource::SOURCECONST));
    $x=(new PhabricatorProjectTransaction())->setTransactionType(self::TYPE)
      ->setMetadataValue('customfield:key',self::FIELD)->setNewValue($request->getDescription());
    if(self::$calls===null){self::$calls=new WeakMap();}
    self::$calls[$editor]=array('object'=>$project,'transaction'=>$x,'request'=>$request,'context'=>$context,'scope'=>null);
    try {
      $result=$editor->applyTransactions($project,array($x));
      return array('target_phid'=>$project->getPHID(),'operation'=>'project.description',
        'native_transaction_count'=>count($result),'native_EDIT_granted'=>false);
    } finally {unset(self::$calls[$editor]);}
  }
  public static function contains(array $xactions) {
    foreach($xactions as$x){if($x instanceof PhabricatorApplicationTransaction && $x->getTransactionType()===self::TYPE){return true;}}
    return false;
  }
  private static function call($editor,$object) {
    PhorgeProjectContentGrantService::assertEnabled();$v=self::$calls[$editor]??null;
    if(!$v||get_class($editor)!==PhabricatorProjectTransactionEditor::class||$v['object']!==$object||
       $editor->getRequest()!==null||$editor->getIsPreview()||$editor->getIsSilent()||
       $editor->getIsHeraldEditor()||$editor->getIsInverseEdgeEditor()) {
      throw new RuntimeException('description_sealed_dispatch_required');
    }
    return$v;
  }
  public static function beginEditor($editor,$object,array $xactions) {
    $v=self::call($editor,$object);
    if($v['scope']!==null||$xactions!==array($v['transaction'])){throw new RuntimeException('description_initial_closure_refused');}
    $scope=PhorgeProjectNativeExecutionScope::open($v['context'],$object,$object->getEditPolicy(),
      'content-description',null,null,null,$v['request']);
    try {
      $scope->bindDescriptionEditor($editor,$v['transaction']);$editor->setActor($scope->getActor());
      $v['scope']=$scope;self::$calls[$editor]=$v;return$scope;
    }catch(Throwable$e){$scope->close();throw$e;}
  }
  public static function requireScope($editor,$object,$xaction) {
    $v=self::call($editor,$object);$scope=$v['scope'];
    if($scope===null||$v['transaction']!==$xaction){throw new RuntimeException('description_current_scope_required');}
    $scope->assertDescriptionTransaction($xaction);return$scope;
  }
  /** Native field resolution, including native storage decoding and validation. */
  public static function field($editor,$object,$xaction) {
    return self::requireScope($editor,$object,$xaction)->getDescriptionField($xaction);
  }
}
