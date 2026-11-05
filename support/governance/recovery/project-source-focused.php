<?php

/** Fixed fixture cleanup: each restoration runs even when transaction cleanup fails. */
function governance_recovery_project_fixture_cleanup($writer,$source,array $values,array $cache,?Throwable $primary=null){
  $errors=$primary===null?array():array('primary'=>$primary);$failed=false;
  try{if($writer&&$writer->isInsideTransaction()){$writer->killTransaction();}}
  catch(Throwable$error){$errors['transaction-cleanup']=$error;$failed=true;}
  try{
    if(!is_object($source)||get_class($source)!==PhabricatorConfigDictionarySource::class){throw new RuntimeException('recovery_project_fixture_dictionary_identity_unknown');}
    (new ReflectionProperty(PhabricatorConfigDictionarySource::class,'dictionary'))->setValue($source,$values);
  }
  catch(Throwable$error){$errors['dictionary-restoration']=$error;$failed=true;}
  try{(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,$cache);}
  catch(Throwable$error){$errors['cache-restoration']=$error;$failed=true;}
  if($failed){throw new PhutilAggregateException('recovery_project_fixture_cleanup_failed',$errors);}
}

/** Candidate real native producers / consumer comparisons. Not SQL-executed yet. */
function governance_recovery_project_source_focused($fixture,callable $check){
  $fixture->assertOwned();require_once dirname(__DIR__).'/native-organization/native-cases.php';
  require_once dirname(__DIR__).'/native-organization/workers.php';
  require_once dirname(__DIR__,3).'/src/infrastructure/governance/recovery/PhorgeRecoveryProjectSourceFacts.php';
  $revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
  if(!is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/D',$revision)){throw new RuntimeException('recovery_project_revision_unknown');}
  $stack=(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->getValue();
  if(get_class($stack)!==PhabricatorConfigStackSource::class||count($stack->getStack())!==2||
    get_class($stack->getStack()[0])!==PhabricatorConfigDictionarySource::class){throw new RuntimeException('recovery_project_config_unknown');}
  $source=$stack->getStack()[0];$values=(new ReflectionProperty(PhabricatorConfigDictionarySource::class,'dictionary'))->getValue($source);
  if(PhabricatorEnv::getEnvConfig('governance.account-recovery.enabled')!==false){throw new RuntimeException('recovery_project_bootstrap_guard_unknown');}
  $cache=(new ReflectionProperty(PhabricatorEnv::class,'cache'))->getValue();$writer=null;$owned_writer=null;$primary=null;
  try{
    // All initial admission and real Session/CSRF preparation precede the guard.
    $salt=substr($fixture->getNamespace(),-12);
    $actor=(new PhabricatorUser())->setUsername('projactor'.$salt)->setRealName('Synthetic Project Actor')->setIsAdmin(1)->setIsApproved(1)->save();
    $subject=(new PhabricatorUser())->setUsername('projsubject'.$salt)->setRealName('Synthetic Project Subject')->setIsApproved(1)->save();
    $auth=governance_native_request($actor,new PhabricatorAuthSessionEngine());
    $new=function($name,$parent=null,$milestone=false)use($actor,$auth){
      $object=PhabricatorProject::initializeNewProject($actor,$parent);$transactions=array(
        (new PhabricatorProjectTransaction())->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)->setNewValue($name));
      if($parent){$transactions[]=(new PhabricatorProjectTransaction())->setTransactionType($milestone?PhabricatorProjectMilestoneTransaction::TRANSACTIONTYPE:PhabricatorProjectParentTransaction::TRANSACTIONTYPE)->setNewValue($parent->getPHID());}
      (new PhabricatorProjectTransactionEditor())->setActor($actor)->setContentSourceFromRequest($auth['request'])->setContinueOnNoEffect(true)->applyTransactions($object,$transactions);
      return $object;
    };
    $root=$new('Synthetic Root '.$salt);
    // A retained direct edge on the parent is copied into the first subproject
    // by the native editor; raw EdgeEditor itself writes both actual inverses.
    (new PhabricatorEdgeEditor())->addEdge($root->getPHID(),PhabricatorProjectProjectHasMemberEdgeType::EDGECONST,$subject->getPHID())->save();
    $root=(new PhabricatorProjectQuery())->setViewer($actor)->withPHIDs(array($root->getPHID()))->executeOne();
    (new PhabricatorProjectsMembershipIndexEngineExtension())->rematerialize($root);
    $leaf=$new('Synthetic Leaf '.$salt,$root);
    $root=(new PhabricatorProjectQuery())->setViewer($actor)->withPHIDs(array($root->getPHID()))->executeOne();
    $milestone=$new('Synthetic Milestone '.$salt,$root,true);
    // Remove only the retained parent direct pair via the real raw native producer.
    // The leaf remains direct; root60 stays aggregated while milestone own60
    // derives from now-absent parent13. ProjectQuery still reads parent60.
    (new PhabricatorEdgeEditor())->removeEdge($root->getPHID(),PhabricatorProjectProjectHasMemberEdgeType::EDGECONST,$subject->getPHID())->save();
    foreach(array($root,$milestone)as$project){
      $fresh=(new PhabricatorProjectQuery())->setViewer($actor)->withPHIDs(array($project->getPHID()))->executeOne();
      (new PhabricatorProjectsMembershipIndexEngineExtension())->rematerialize($fresh);
    }
    (new PhabricatorProjectTransactionEditor())->setActor($actor)->setContentSourceFromRequest($auth['request'])->setContinueOnNoEffect(true)->applyTransactions($leaf,array(
      (new PhabricatorProjectTransaction())->setTransactionType(PhabricatorProjectStatusTransaction::TRANSACTIONTYPE)->setNewValue(PhabricatorProjectStatus::STATUS_ARCHIVED)));
    $roots=array($root->getPHID(),$leaf->getPHID(),$milestone->getPHID());
    $queried=(new PhabricatorProjectQuery())->setViewer($subject)->withPHIDs($roots)->needMembers(true)->execute();
    $map=mpull($queried,null,'getPHID');
    if(count($map)!==3||array_diff($roots,array_keys($map))){throw new RuntimeException('recovery_project_native_query_incomplete');}
    $rule=new PhabricatorProjectMembersPolicyRule();$rule->willApplyRules($subject,array(),array($map[$root->getPHID()]));
    $own_milestone=PhabricatorEdgeQuery::loadDestinationPHIDs($milestone->getPHID(),PhabricatorProjectMaterializedMemberEdgeType::EDGECONST);
    $check(!$rule->applyRule($subject,null,$map[$root->getPHID()])&&$map[$leaf->getPHID()]->isArchived()&&
      !in_array($subject->getPHID(),$own_milestone,true)&&count($map)===3&&in_array($subject->getPHID(),$map[$root->getPHID()]->getMemberPHIDs(),true)&&
      in_array($subject->getPHID(),$map[$milestone->getPHID()]->getMemberPHIDs(),true),
      'recovery-project-real-editor-hierarchy-query-and-native-materialization');
    $source->setKeys(array('governance.account-recovery.enabled'=>true,'governance.account-recovery.incarnation'=>bin2hex(random_bytes(16))));
    (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    $writer=(new PhabricatorUser())->establishConnection('w');
    if($writer->isInsideTransaction()||$writer->isReadLocking()||$writer->isWriteLocking()){
      throw new RuntimeException('recovery_project_fixture_outer_transaction_refused');}
    // Assign only after refusing caller/native outer state. Cleanup is centralized,
    // including a BEGIN failure; it is not a physical-outcome or session-close proof.
    $owned_writer=$writer;$writer->openTransaction();
    $facts=PhorgeRecoveryProjectSourceFacts::capture($writer,$subject->getPHID());$result=$facts->finish();
    $check($result['actual_SQL_queries_executed']===true&&$result['complete']===false&&$result['SQL_absence_or_producer_lease']===false,
      'recovery-project-qualified-current-source-and-private-redacted-facts');
    // This is one real happy-path candidate, not all producer/race coverage.
    return array('profile'=>'native-project-source-facts-v1','source_revision'=>$revision,
      'real_native_ProjectEditor_EdgeEditor_IndexEngine'=>true,'native_ProjectQuery_comparison'=>true,
      'selected_history_is_current_membership'=>false,'SQL_absence_or_producer_lease'=>false,
      'complete'=>false,'all_sources_complete'=>false,'restoration_authorized'=>false);
  }catch(Throwable$error){$primary=$error;throw$error;}
  finally{
    governance_recovery_project_fixture_cleanup($owned_writer,$source,$values,$cache,$primary);
  }
}
