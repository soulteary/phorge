<?php

/** Actual native Project and named-policy sources, each through a new request. */
function governance_private_mail_legalpad_policy_cases($fixture,callable $check,array $state){
  $fixture->assertOwned();$doc=$state['document'];$signature=$state['signature'];$auth=$state['signer_auth'];
  $author=$state['author_auth'];$call=$state['call'];$original=$doc->getViewPolicy();$project_scope=null;
  $with_author=static function(callable $action)use($author){$cookies=$_COOKIE;$method=$_SERVER['REQUEST_METHOD']??null;
    try{$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$author['token'];$_SERVER['REQUEST_METHOD']='POST';$author['request']->validateCSRF();return$action();}
    finally{$_COOKIE=$cookies;if($method===null){unset($_SERVER['REQUEST_METHOD']);}else{$_SERVER['REQUEST_METHOD']=$method;}}};
  $path='/legalpad/verify/'.$signature->getSecretKey().'/';
  $unchanged=static function()use($signature){return !((new LegalpadDocumentSignature())->load($signature->getID()))->isVerified();};
  $mail_source=static function()use($signature,$auth){return PhorgeLegalpadVerification::mailSource(
    (new LegalpadDocumentSignature())->load($signature->getID()),$auth['viewer']);};
  try{
    // Exercise the retained native project membership implementation. This
    // fixture does not enable the candidate project execution model or grant
    // caller-supplied authority to the document verifier.
    $project_scope=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.project-approval.enabled',false);
    $project=PhabricatorProject::initializeNewProject($author['viewer']);
    $with_author(static function()use($author,$project,$fixture){(new PhabricatorProjectTransactionEditor())->setActor($author['viewer'])->setContentSourceFromRequest($author['request'])->setIsSilent(true)
      ->applyTransactions($project,array((new PhabricatorProjectTransaction())->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)
        ->setNewValue('Synthetic Legalpad policy members '.substr($fixture->getNamespace(),-10))));});
    $membership=static function($add)use($project,$auth,$author,$with_author){
      return$with_author(static function()use($project,$auth,$author,$add){
      $project=(new PhabricatorProject())->load($project->getID());
      (new PhabricatorProjectTransactionEditor())->setActor($author['viewer'])->setContentSourceFromRequest($author['request'])->setIsSilent(true)
        ->applyTransactions($project,array((new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
          ->setMetadataValue('edge:type',PhabricatorProjectProjectHasMemberEdgeType::EDGECONST)
          ->setNewValue(array($add?'+':'-'=>array_fuse(array($auth['viewer']->getPHID()))))));
      });
    };
    $membership(true);
    $policy=(new PhabricatorPolicy())->setDefaultAction(PhabricatorPolicy::ACTION_DENY)->setRules(array(array(
      'action'=>PhabricatorPolicy::ACTION_ALLOW,'rule'=>PhabricatorProjectsPolicyRule::class,'value'=>array($project->getPHID()))))->save();
    $doc->setViewPolicy($policy->getPHID())->save();
    $allowed=$call($auth,$path,'GET');$source=$mail_source();
    $materialized=PhabricatorEdgeQuery::loadDestinationPHIDs($project->getPHID(),PhabricatorProjectMaterializedMemberEdgeType::EDGECONST);
    $check($allowed instanceof AphrontDialogResponse&&is_string($source)&&preg_match('/\A[a-f0-9]{64}\z/D',$source)&&
      in_array($auth['viewer']->getPHID(),$materialized,true)&&$unchanged(),
      'private-mail-legalpad-actual-project-membership-policy-allows-current-signer-new-request');
    $membership(false);$denied=$call($auth,$path,'GET');$source_refused=false;
    try{$mail_source();}catch(PhorgeLegalpadVerificationException$error){$source_refused=true;}
    $check($denied instanceof Aphront403Response&&$source_refused&&$unchanged(),
      'private-mail-legalpad-native-project-membership-revocation-denies-new-request-and-mail-source');
    $membership(true);$restored=$call($auth,$path,'GET');
    $check($restored instanceof AphrontDialogResponse&&$unchanged(),
      'private-mail-legalpad-project-membership-restoration-is-real-native-positive-control');
    $named=(new PhorgeNamedPolicy())->setName('Synthetic Legalpad named policy')->setEffectivePolicy($policy->getPHID())
      ->setViewPolicy(PhabricatorPolicies::POLICY_USER)->setEditPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
    $doc->setViewPolicy($named->getPHID())->save();$named_allow=$call($auth,$path,'GET');
    $named->setEffectivePolicy(PhabricatorPolicies::POLICY_NOONE)->save();$named_deny=$call($auth,$path,'GET');$named_refused=false;
    try{$mail_source();}catch(PhorgeLegalpadVerificationException$error){$named_refused=true;}
    $named->setEffectivePolicy($policy->getPHID())->save();$named_restore=$call($auth,$path,'GET');
    $check($named_allow instanceof AphrontDialogResponse&&$named_deny instanceof Aphront403Response&&$named_refused&&
      $named_restore instanceof AphrontDialogResponse&&$unchanged(),
      'private-mail-legalpad-actual-named-effective-policy-change-rechecks-new-request-with-native-positive-controls');
    return array('actual_project_membership_editor_and_materialized_source'=>true,'actual_named_effective_policy'=>true,
      'new_request_revocation_checked'=>true,'document_space_acl_invented'=>false,'all_policy_sources_atomically_locked'=>false);
  }finally{$doc->setViewPolicy($original)->save();unset($project_scope);}
}
