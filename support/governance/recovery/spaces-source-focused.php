<?php
/** Fixture scope only; never borrows or destroys a caller transaction. */
function governance_spaces_fixture_scope($writer,callable$body){
  if($writer->isInsideTransaction()||$writer->isReadLocking()||$writer->isWriteLocking()){throw new RuntimeException('recovery_spaces_fixture_outer_state_refused');}
  $primary=null;$owned=$writer;
  try{$owned->openTransaction();return$body($owned);}catch(Throwable$error){$primary=$error;throw$error;}
  finally{try{if($owned->isInsideTransaction()){$owned->killTransaction();}}catch(Throwable$error){if($primary){throw new PhutilAggregateException('recovery_spaces_fixture_transaction_cleanup_failed',array('primary'=>$primary,'cleanup'=>$error));}throw$error;}}
}
/** Independent owned setup observation, fixed columns only; never emitted publicly. */
function governance_spaces_fixture_observe($fixture,$phid){
  $statement=$fixture->getAdmin()->prepare('SELECT n.description,n.isArchived,n.viewPolicy,(SELECT COUNT(*) FROM '.$fixture->table('spaces','spaces_namespacetransaction').' x WHERE x.objectPHID=n.phid) xactCount FROM '.$fixture->table('spaces','spaces_namespace').' n WHERE n.phid=?');
  try{$statement->bind_param('s',$phid);$statement->execute();$row=$statement->get_result()->fetch_assoc();if(!$row||!is_string($row['description'])||strlen($row['description'])>16384){throw new RuntimeException('recovery_spaces_fixture_observation_unknown');}return$row;}finally{$statement->close();}
}
function governance_recovery_spaces_source_focused($fixture,callable$check){
  $fixture->assertOwned();require_once dirname(__DIR__).'/native-organization/native-cases.php';require_once dirname(__DIR__).'/native-organization/workers.php';require_once __DIR__.'/project-source-focused.php';
  require_once dirname(__DIR__,3).'/src/infrastructure/governance/recovery/PhorgeRecoverySpacesSourceFacts.php';
  $revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');if(!is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/D',$revision)){throw new RuntimeException('recovery_spaces_fixture_revision_unknown');}
  $stack=(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->getValue();
  if(!is_object($stack)||get_class($stack)!==PhabricatorConfigStackSource::class||count($stack->getStack())!==2||get_class($stack->getStack()[0])!==PhabricatorConfigDictionarySource::class){throw new RuntimeException('recovery_spaces_fixture_config_unknown');}
  $source=$stack->getStack()[0];$values=(new ReflectionProperty(PhabricatorConfigDictionarySource::class,'dictionary'))->getValue($source);$cache=(new ReflectionProperty(PhabricatorEnv::class,'cache'))->getValue();$request=(new ReflectionProperty(PhabricatorCaches::class,'requestCache'))->getValue();$primary=null;
  if(PhabricatorEnv::getEnvConfig('governance.account-recovery.enabled')!==false){throw new RuntimeException('recovery_spaces_fixture_initial_guard_unknown');}
  try{
    // All real initial admission/session creation precedes the recovery guard.
    $salt=substr($fixture->getNamespace(),-12);
    $actor=(new PhabricatorUser())->setUsername('spacesactor'.$salt)->setRealName('Synthetic Spaces Actor')->setIsAdmin(1)->setIsApproved(1)->save();
    $reader=(new PhabricatorUser())->setUsername('spacesreader'.$salt)->setRealName('Synthetic Spaces Viewer')->setIsAdmin(0)->setIsApproved(1)->save();
    $auth=governance_native_request($actor,new PhabricatorAuthSessionEngine());$readAuth=governance_native_request($reader,new PhabricatorAuthSessionEngine());$viewer=$readAuth['viewer'];
    $check($viewer->hasSession()&&$viewer->getPHID()===$reader->getPHID()&&!$viewer->getIsAdmin()&&$viewer->getIsApproved(),
      'recovery-spaces-real-nonadmin-native-login-before-guard');
    $edit=function($space,$transactions)use($auth){$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$auth['token'];$auth['request']->validateCSRF();return(new PhabricatorSpacesNamespaceEditor())->setActor($auth['viewer'])->setContentSourceFromRequest($auth['request'])->setContinueOnNoEffect(true)->applyTransactions($space,$transactions);};
    $create=function($policy,$name)use($actor,$edit){$space=PhabricatorSpacesNamespace::initializeNewNamespace($actor);$edit($space,array(
      (new PhabricatorSpacesNamespaceTransaction())->setTransactionType(PhabricatorSpacesNamespaceNameTransaction::TRANSACTIONTYPE)->setNewValue($name),
      (new PhabricatorSpacesNamespaceTransaction())->setTransactionType(PhabricatorTransactions::TYPE_VIEW_POLICY)->setNewValue($policy),
      (new PhabricatorSpacesNamespaceTransaction())->setTransactionType(PhabricatorTransactions::TYPE_EDIT_POLICY)->setNewValue(PhabricatorPolicies::POLICY_ADMIN)));return$space;};
    $space=$create(PhabricatorPolicies::POLICY_USER,'Synthetic selected Space');$restricted=$create(PhabricatorPolicies::POLICY_NOONE,'Synthetic restricted Space');
    // Explicit new request cache, not a claim that native destroySpacesCache clears viewer caches.
    $freshCache=function(){(new ReflectionProperty(PhabricatorCaches::class,'requestCache'))->setValue(null,null);};$freshCache();$all=PhabricatorSpacesNamespaceQuery::getAllSpaces();
    $check(isset($all[$space->getPHID()])&&PhabricatorPolicyFilter::hasCapability($viewer,$all[$space->getPHID()],PhabricatorPolicyCapability::CAN_VIEW),
      'recovery-spaces-native-editor-and-nonadmin-view-positive');
    $check(isset($all[$restricted->getPHID()])&&!PhabricatorPolicyFilter::hasCapability($viewer,$all[$restricted->getPHID()],PhabricatorPolicyCapability::CAN_VIEW),
      'recovery-spaces-real-restricted-policy-nonadmin-denied');
    $source->setKeys(array('governance.account-recovery.enabled'=>true,'governance.account-recovery.incarnation'=>bin2hex(random_bytes(16))));(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    $writer=(new PhabricatorUser())->establishConnection('w');
    $result=governance_spaces_fixture_scope($writer,static fn($conn)=>PhorgeRecoverySpacesSourceFacts::capture($conn)->finish());
    $check($result['actual_SQL_reads_executed']===true&&$result['complete']===false&&$result['request_cache_policy_correctness_proved']===false,
      'recovery-spaces-qualified-current-row-and-real-query-cache-facts');
    $edit($space,array((new PhabricatorSpacesNamespaceTransaction())->setTransactionType(PhabricatorSpacesNamespaceArchiveTransaction::TRANSACTIONTYPE)->setNewValue(true)));$freshCache();$all=PhabricatorSpacesNamespaceQuery::getAllSpaces();$active=PhabricatorSpacesNamespaceQuery::getViewerActiveSpaces($viewer);
    $check(isset($all[$space->getPHID()])&&(bool)$all[$space->getPHID()]->getIsArchived()&&PhabricatorPolicyFilter::hasCapability($viewer,$all[$space->getPHID()],PhabricatorPolicyCapability::CAN_VIEW)&&!isset($active[$space->getPHID()]),
      'recovery-spaces-archived-all-space-policy-versus-active-ui-list');
    $before=governance_spaces_fixture_observe($fixture,$space->getPHID());$appwriter=(new PhabricatorSpacesNamespace())->establishConnection('w');
    governance_spaces_fixture_scope($appwriter,function()use($edit,$space){$edit($space,array((new PhabricatorSpacesNamespaceTransaction())->setTransactionType(PhabricatorSpacesNamespaceDescriptionTransaction::TRANSACTIONTYPE)->setNewValue('Synthetic rolled-back description')));});
    $check(governance_spaces_fixture_observe($fixture,$space->getPHID())===$before&&!$appwriter->isInsideTransaction(),
      'recovery-spaces-real-native-editor-description-and-history-rollback');
    $freshCache();PhabricatorSpacesNamespaceQuery::getAllSpaces();
    $changed=governance_spaces_fixture_scope($writer,function($conn)use($fixture,$space){
      $facts=PhorgeRecoverySpacesSourceFacts::capture($conn);
      // Deliberate owned raw producer, same SQL transaction; not a native Editor claim.
      queryfx($conn,'UPDATE %T.spaces_namespace SET description=%s WHERE phid=%s',$fixture->getNamespace().'_spaces','Synthetic changed readset',$space->getPHID());
      $refused=false;try{$facts->finish();}catch(RuntimeException$error){if(!in_array($error->getMessage(),array('recovery_spaces_cache_rows_changed','recovery_spaces_facts_changed'),true)){throw$error;}$refused=true;}
      $terminal=false;try{$facts->finish();}catch(RuntimeException$error){if($error->getMessage()!=='recovery_spaces_finished'){throw$error;}$terminal=true;}return$refused&&$terminal;
    });
    $check($changed&&governance_spaces_fixture_observe($fixture,$space->getPHID())===$before,
      'recovery-spaces-owned-source-change-refused-terminal-and-rollback');
    $missing=governance_spaces_fixture_scope($writer,function($conn)use($fixture){queryfx($conn,'UPDATE %T.spaces_namespace SET isDefaultNamespace=NULL WHERE isDefaultNamespace=1',$fixture->getNamespace().'_spaces');$refused=false;try{PhorgeRecoverySpacesSourceFacts::capture($conn);}catch(RuntimeException$error){if($error->getMessage()!=='recovery_spaces_default_unknown'){throw$error;}$refused=true;}return$refused;});
    $check($missing,'recovery-spaces-owned-missing-default-read-refused-and-rollback');
    $freshCache();$final=governance_spaces_fixture_scope($writer,static fn($conn)=>PhorgeRecoverySpacesSourceFacts::capture($conn)->finish());
    $check($final['actual_SQL_reads_executed']===true&&!$writer->isInsideTransaction()&&PhabricatorSpacesNamespaceQuery::getDefaultSpace()!==null,
      'recovery-spaces-restored-current-facts-after-all-rollback-controls');
    return array('profile'=>'native-spaces-source-facts-v1','source_revision'=>$revision,'native_Editor_and_nonadmin_session_VIEW'=>true,'native_archive_and_active_list_distinct'=>true,'native_editor_rollback_independently_observed'=>true,'owned_raw_source_change_and_missing_default_refused'=>true,'query_cache_is_policy_attestation'=>false,'history_complete'=>false,'absence_or_producer_lease_proved'=>false,'complete'=>false,'all_sources_complete'=>false,'restoration_authorized'=>false);
  }catch(Throwable$error){$primary=$error;throw$error;}
  finally{
    $errors=array();if($primary){$errors['primary']=$primary;}
    try{governance_recovery_project_fixture_cleanup(null,$source,$values,$cache,$primary);}catch(Throwable$error){$errors['env-config']=$error;}
    try{(new ReflectionProperty(PhabricatorCaches::class,'requestCache'))->setValue(null,$request);}catch(Throwable$error){$errors['request-cache']=$error;}
    if(count($errors)>($primary?1:0)){throw new PhutilAggregateException('recovery_spaces_fixture_cleanup_failed',$errors);}
  }
}
