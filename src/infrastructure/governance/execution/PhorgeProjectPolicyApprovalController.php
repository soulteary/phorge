<?php

/** Trusted web producer; form intent never supplies missing native authority. */
final class PhorgeProjectPolicyApprovalController extends PhabricatorProjectController {
  public function handleRequest(AphrontRequest $request) {
    if (!PhorgeProjectExecutionCatalog::enabled()) { return new Aphront404Response(); }
    if (!$request->isHTTPPost()) {
      if ($request->getStr('form','') !== 'policy') { return new Aphront400Response(); }
      try { return $this->newPolicyForm($request); }
      catch (PhorgeProjectPolicyRefusalException $error) { return PhorgeProjectPolicyFailure::newHTTPResponse($error); }
    }
    try {
      $request->validateCSRF();
      $context=PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
      if ($request->getStr('form','') === 'policy') {
        $content_type=AphrontRequest::getHTTPHeader('Content-Type');
        if (!is_string($content_type) || strtolower(trim(explode(';',$content_type,2)[0]))!=='application/x-www-form-urlencoded') {
          throw PhorgeProjectPolicyRefusalException::newForReason('request-input');
        }
        if ($request->getStr('payload','') !== '' || $request->getStr('action','') !== '') {
          throw PhorgeProjectPolicyRefusalException::newForReason('request-input');
        }
        if ($request->getStr('form_action','') === 'preview') { return $this->newPolicyForm($request); }
        if ($request->getStr('form_action','') !== 'submit' || $request->getArr('projects',array())) {
          throw PhorgeProjectPolicyRefusalException::newForReason('request-input');
        }
        $payload=self::newFormPayload($request->getStr('operation','edit'),
          $request->getStr('target_phid',''),$request->getStr('new_policy'),
          $request->getStr('reason'),$request->getInt('ttl_seconds'));
        $result=PhorgeProjectPolicyApprovalService::call($context,'submit',$payload);
        return $this->newDialog()->setTitle(pht('Project Policy Proposal Submitted'))
          ->appendParagraph(pht('The proposal does not change permissions. Independent approval and a matching native project edit are still required.'))
          ->appendChild((new AphrontFormTextControl())->setLabel(pht('Approval Ticket'))->setValue($result['approval_id'])->setDisabled(true))
          ->addCancelButton('/project/',pht('Done'));
      }
      if ($request->getStr('form','') !== '' || $request->getStr('form_action','') !== '') {
        throw PhorgeProjectPolicyRefusalException::newForReason('request-input');
      }
      $raw=$request->getStr('payload','');
      $payload=PhorgeProjectPolicyFailure::decodeRequest($raw);
      $result=PhorgeProjectPolicyApprovalService::call($context,$request->getStr('action',''),$payload);
      return (new AphrontJSONResponse())->setContent(array('result'=>$result));
    } catch (PhorgeProjectPolicyRefusalException $error) {
      return PhorgeProjectPolicyFailure::newHTTPResponse($error);
    }
  }
  /** Missing operation keeps the original edit-policy interpretation. */
  public static function newFormPayload($operation,$target,$policy,$reason,$ttl) {
    if (!in_array($operation,array('edit','view'),true)) {
      throw PhorgeProjectPolicyRefusalException::newForReason('request-input');
    }
    $payload=array('target_phid'=>$target,'reason'=>$reason,'ttl_seconds'=>$ttl);
    if ($operation==='edit') { $payload['new_policy']=$policy; }
    else { $payload['intent']=array('version'=>3,'operation'=>'project.view-policy','policy'=>$policy); }
    return $payload;
  }

  private function newPolicyForm(AphrontRequest $request) {
    $context=PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
    $operation=$request->getStr('operation','edit');
    self::newFormPayload($operation,'','','',120);
    // A blank form exposes no project metadata. Revalidate the actual native
    // credential in an owned read bookkeeping transaction, then roll it back.
    $conn=(new PhabricatorUser())->establishConnection('w');
    if ($conn->isInsideTransaction() || $conn->isReadLocking() || $conn->isWriteLocking()) {
      throw new RuntimeException('project_execution_existing_scope_refused');
    }
    $unguarded=AphrontWriteGuard::beginScopedUnguardedWrites();
    try {
      $conn->openTransaction();
      try {
        $snapshot=PhorgeGovernanceNativeIdentitySource::lockCurrent($context,$conn);
        $row=queryfx_one($conn,'SELECT * FROM %R WHERE BINARY phid=BINARY %s',new PhabricatorUser(),$snapshot->getUserPHID());
        if (!$row || $row['phid']!==$snapshot->getUserPHID()) { throw new RuntimeException('project_execution_identity_missing'); }
        $user=(new PhabricatorUser())->loadFromArray($row);
        if (PhabricatorEnv::getEnvConfig('security.require-multi-factor-auth') && !$user->getIsEnrolledInMultiFactor()) {
          throw new RuntimeException('project_execution_native_mfa_enrollment_required');
        }
        PhorgeGovernanceNativeIdentitySource::revalidate($snapshot,$conn);
      } finally { $conn->killTransaction(); }
    } finally { unset($unguarded); }
    $dialog=$this->newDialog()->setTitle(pht('Propose Project Policy Change'))
      ->addHiddenInput('form','policy')->setSubmitURI('/project/approval/?form=policy')
      ->addCancelButton('/project/');
    $targets=$request->getArr('projects',array());
    if (!$targets && $request->getStr('target_phid','')!=='') { $targets=array($request->getStr('target_phid','')); }
    if (!$targets) {
      return $dialog->addHiddenInput('form_action','preview')
        ->appendChild((new AphrontFormTokenizerControl())->setViewer($this->getViewer())
          ->setName('projects')->setLabel(pht('Project'))->setLimit(1)
          ->setDatasource(new PhabricatorProjectDatasource()))
        ->appendChild((new AphrontFormSelectControl())->setName('operation')->setLabel(pht('Policy to Change'))
          ->setValue($operation)->setOptions(array('edit'=>pht('Edit Policy'),'view'=>pht('View Policy'))))
        ->addSubmitButton(pht('Review Current Policy'));
    }
    if (count($targets)!==1 || !is_string(head($targets)) ||
        !preg_match('/\APHID-PROJ-[a-zA-Z0-9]{20}\z/',head($targets))) {
      throw PhorgeProjectPolicyRefusalException::newForReason('request-input');
    }
    $project=(new PhabricatorProjectQuery())->setViewer($this->getViewer())
      ->withPHIDs($targets)->requireCapabilities(array(PhabricatorPolicyCapability::CAN_VIEW,PhabricatorPolicyCapability::CAN_EDIT))
      ->needMembers(true)->needAncestorMembers(true)->executeOne();
    if (!$project) { throw PhorgeProjectPolicyRefusalException::newForReason('target-unavailable'); }
    $capability=$operation==='view'?PhabricatorPolicyCapability::CAN_VIEW:PhabricatorPolicyCapability::CAN_EDIT;
    $current=$project->getPolicy($capability);
    $intent=$operation==='view'?PhorgeProjectExecutionIntent::newViewPolicy($current):PhorgeProjectExecutionIntent::newPolicy($current);
    $unguarded=AphrontWriteGuard::beginScopedUnguardedWrites();
    try {
      $scope=PhorgeProjectNativeExecutionScope::open($context,$project,$current,'propose',null,$intent);
      try {
        $project=$scope->getObject(); $actor=$scope->getActor(); $current=$project->getPolicy($capability);
        // Materialize protected choices while the scope still owns its fresh
        // Space cache and actor locks. No post-close query reuses prior cache.
        // The locked actor is reconstructed from native authority rows and has
        // no user-cache attachments. Build only its native preference value;
        // do not replace this actor with a cached viewer or enable inline writes.
        $preferences_key=PhabricatorUserPreferencesCacheType::KEY_PREFERENCES;
        $preferences=(new PhabricatorUserPreferencesCacheType())->newValueForUsers(
          $preferences_key,array($actor));
        if (!array_key_exists($actor->getPHID(),$preferences) ||
            !is_string($preferences[$actor->getPHID()])) {
          throw new RuntimeException('project_execution_actor_preferences_missing');
        }
        $actor->attachRawCacheData(array($preferences_key=>$preferences[$actor->getPHID()]));
        $policies=(new PhabricatorPolicyQuery())->setViewer($actor)->setObject($project)->execute();
        $policy_control=(new AphrontFormPolicyControl())->setViewer($actor)->setName('new_policy')
          ->setPolicyObject($project)->setCapability($capability)->setPolicies($policies)->setValue($current)
          ->setShowSpaces(false);
        $policy_html=hsprintf('%s',$policy_control);
      }
      finally { $scope->close(); }
    } finally { unset($unguarded); }
    $dialog->setViewer($actor);
    return $dialog->addHiddenInput('form_action','submit')->addHiddenInput('operation',$operation)
      ->addHiddenInput('target_phid',$project->getPHID())->addHiddenInput('ttl_seconds',120)
      ->appendParagraph(pht('Selected policy: %s. Return to project selection to change the policy field.',
        $operation==='view'?pht('View Policy'):pht('Edit Policy')))
      ->appendChild((new AphrontFormMarkupControl())->setLabel(pht('Project'))->setValue(phutil_tag('span',array(),$project->getName())))
      ->appendChild($policy_html)
      ->appendChild((new AphrontFormTextAreaControl())->setLabel(pht('Reason'))->setName('reason'))
      ->addSubmitButton(pht('Submit Policy Proposal'));
  }

}
