<?php

/** Draft native first-issuer/ABA/version/schema regressions; no fake credential. */
function governance_native_noncredential_source_v2_cases($fixture,callable $check,array $state){
  $fixture->assertOwned();$service=$state['service'];$conn=(new PhabricatorUser())->establishConnection('w');$cookie=$_COOKIE;$server=$_SERVER;
  $make=function($suffix,$admin=false)use($fixture){$u=(new PhabricatorUser())->setUsername('nv2'.$suffix.substr($fixture->getNamespace(),-8))
    ->setRealName('Synthetic Identity V2 '.$suffix)->setIsApproved(1)->setIsEmailVerified(1)->setIsAdmin($admin?1:0)->save();
    (new PhabricatorUserEmail())->setUserPHID($u->getPHID())->setAddress($u->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();return$u;};
  $refuse=function(callable$call,$reason){try{$call();}catch(RuntimeException$error){if($error->getMessage()!==$reason){throw$error;}return true;}return false;};
  try{
    $reviewer=governance_native_request($make('review',true),$state['sessions']);$review=PhorgeOrganizationService::newForActor($reviewer['context']);$owner=$make('owner');
    foreach(array('source-v2-from','source-v2-to')as$id){$service->createUnit(array('operation_id'=>'create-'.$id,'unit_id'=>$id,'kind'=>'department','name'=>$id,'parent_id'=>null));}
    $proposal=function($suffix)use($make,$service,$owner){$subject=$make($suffix);$service->assignPrimaryDepartment(array('operation_id'=>'v2-primary-'.$suffix,'user_phid'=>$subject->getPHID(),'unit_id'=>'source-v2-from','expected_version'=>0));
      $intent=array('operation_id'=>'v2-execute-'.$suffix,'transfer_id'=>'v2-ticket-'.$suffix,'subject_phid'=>$subject->getPHID(),'responsible_phid'=>$owner->getPHID(),
        'from_department'=>'source-v2-from','to_department'=>'source-v2-to','primary_version'=>1,'from_version'=>1,'to_version'=>1,
        'reason'=>'Versioned native source proof','acknowledge_no_project_permission_change'=>true);
      $service->proposeTransfer($intent,300);return array($subject,$intent);};
    list($subject,$intent)=$proposal('first');
    $row=queryfx_one($conn,'SELECT bindingJSON FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$intent['transfer_id']);$data=phutil_json_decode($row['bindingJSON']);
    $check($data['schema']==='native-organization-transfer-v2'&&$data['sources']['subject']['source_version']===2&&
      $data['sources']['subject']['legacyRoleEpoch']==='1'&&
      !queryfx_one($conn,'SELECT epoch FROM %R WHERE BINARY userPHID=BINARY %s',new PhorgeGovernanceIdentityEpoch(),$subject->getPHID()),
      'native-noncredential-v2-proposal-binds-locked-generation-one-without-initializing-subject-credential');
    $ordinary=governance_native_request($subject,$state['sessions']);
    $caps=PhorgeOrganizationService::newForActor($ordinary['context'])->readActorCapabilities();
    $d=$service->readTransfer($intent['transfer_id']);$review->approveTransfer($intent['transfer_id'],$d['request_digest']);$result=$service->executeTransfer($intent['transfer_id'],true);
    $check($caps['manage_units']===false&&$result['status']==='committed'&&
      (string)queryfx_one($conn,'SELECT epoch FROM %R WHERE BINARY userPHID=BINARY %s',new PhorgeGovernanceIdentityEpoch(),$subject->getPHID())['epoch']==='1',
      'native-noncredential-v2-first-real-subject-directory-access-preserves-independent-approval-and-execution');
    $h=queryfx_one($conn,'SELECT * FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationHandover(),$intent['transfer_id']);
    $extend=array('operation_id'=>'v2-owner-extend','transfer_id'=>'v2-owner-ticket','handover_id'=>$h['handoverID'],'expected_version'=>1,'handover_days'=>1,
      'reason'=>'Owner first directory visit','acknowledge_no_project_permission_change'=>true);
    $service->proposeHandover('extend',$extend,300);$owner_context=governance_native_request($owner,$state['sessions']);PhorgeOrganizationService::newForActor($owner_context['context'])->readActorCapabilities();
    $d=$service->readHandoverApproval($extend['transfer_id']);$review->approveHandover($extend['transfer_id'],$d['request_digest']);$service->executeHandover($extend['transfer_id'],true);
    $binding=queryfx_one($conn,'SELECT bindingJSON FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$extend['transfer_id']);
    $check(phutil_json_decode($binding['bindingJSON'])['schema']==='native-organization-handover-v2'&&
      (int)queryfx_one($conn,'SELECT version FROM %R WHERE BINARY handoverID=BINARY %s',new PhorgeOrganizationHandover(),$h['handoverID'])['version']===2,
      'native-noncredential-v2-handover-owner-first-real-directory-access-does-not-stale-unchanged-facts');
    $restore_handover_v1=function($id)use($fixture,$conn){$row=queryfx_one($conn,'SELECT bindingJSON FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$id);$data=phutil_json_decode($row['bindingJSON']);
      $conn->openTransaction();try{foreach(array('subject','oldResponsible','newResponsible')as$key){if(isset($data['sources'][$key])){
        $data['sources'][$key]=PhorgeGovernanceNativeIdentitySource::lockIdentityPHID($data['sources'][$key]['userPHID'],$conn);}}$conn->saveTransaction();}
      catch(Throwable$error){$conn->killTransaction();throw$error;}
      $data['schema']='native-organization-handover-v1';$json=PhorgeGovernanceOrganizationApprovalAdapter::canonicalJSON($data);
      governance_native_concurrency_sql($fixture,'UPDATE '.$fixture->table('user','organization_transfer').' SET bindingJSON=?,requestDigest=? WHERE transferID=?',array($json,hash('sha256',$json),$id));};
    $old_h=$extend;$old_h['operation_id']='v1-handover-stable-op';$old_h['transfer_id']='v1-handover-stable-ticket';$old_h['expected_version']=2;
    $service->proposeHandover('extend',$old_h,300);$restore_handover_v1($old_h['transfer_id']);$d=$service->readHandoverApproval($old_h['transfer_id']);
    $review->approveHandover($old_h['transfer_id'],$d['request_digest']);$service->executeHandover($old_h['transfer_id'],true);
    $check(queryfx_one($conn,'SELECT state FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$old_h['transfer_id'])['state']==='executed',
      'native-noncredential-v1-handover-restored-unchanged-facts-retain-original-execution-rules');
    list($unissued,$unissued_intent)=$proposal('oldhsubject');$d=$service->readTransfer($unissued_intent['transfer_id']);$review->approveTransfer($unissued_intent['transfer_id'],$d['request_digest']);$service->executeTransfer($unissued_intent['transfer_id'],true);
    $old_handover=queryfx_one($conn,'SELECT handoverID FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationHandover(),$unissued_intent['transfer_id']);
    $old_h=$extend;$old_h['operation_id']='v1-handover-first-op';$old_h['transfer_id']='v1-handover-first-ticket';$old_h['handover_id']=$old_handover['handoverID'];$old_h['expected_version']=1;
    $service->proposeHandover('extend',$old_h,300);$restore_handover_v1($old_h['transfer_id']);
    $old_context=governance_native_request($unissued,$state['sessions']);PhorgeOrganizationService::newForActor($old_context['context'])->readActorCapabilities();$d=$service->readHandoverApproval($old_h['transfer_id']);
    $check($refuse(function()use($review,$old_h,$d){$review->approveHandover($old_h['transfer_id'],$d['request_digest']);},'organization_handover_source_stale'),
      'native-noncredential-v1-handover-first-issuer-change-remains-stale-without-reinterpretation');
    list($aba,$aba_intent)=$proposal('disable');
    foreach(array(true,false)as$disabled){$target=(new PhabricatorUser())->loadOneWhere('phid=%s',$aba->getPHID());
      (new PhabricatorUserTransactionEditor())->setActor($state['users']['admin'])->setContentSource(PhabricatorContentSource::newForSource('console',array()))
        ->applyTransactions($target,array((new PhabricatorUserTransaction())->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue($disabled)));}
    $d=$service->readTransfer($aba_intent['transfer_id']);
    $check($refuse(function()use($review,$aba_intent,$d){$review->approveTransfer($aba_intent['transfer_id'],$d['request_digest']);},'organization_transfer_source_stale')&&
      queryfx_one($conn,'SELECT state FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$aba_intent['transfer_id'])['state']==='submitted',
      'native-noncredential-v2-real-disable-enable-user-transaction-aba-stales-pending-ticket');
    foreach(array('agent'=>'makeSystemAgentUser','mail'=>'makeMailingListUser')as$kind=>$method){
      list($user,$role_intent)=$proposal($kind);$before=queryfx_one($conn,'SELECT MAX(id) n FROM %R WHERE BINARY objectPHID=BINARY %s',new PhabricatorUserTransaction(),$user->getPHID());
      foreach(array(true,false)as$value){$target=(new PhabricatorUser())->loadOneWhere('phid=%s',$user->getPHID());(new PhabricatorUserEditor())->setActor($state['users']['admin'])->$method($target,$value);}
      $after=queryfx_one($conn,'SELECT MAX(id) n FROM %R WHERE BINARY objectPHID=BINARY %s',new PhabricatorUserTransaction(),$user->getPHID());$d=$service->readTransfer($role_intent['transfer_id']);
      $check($before===$after&&$refuse(function()use($review,$role_intent,$d){$review->approveTransfer($role_intent['transfer_id'],$d['request_digest']);},'organization_transfer_source_stale'),
        'native-noncredential-v2-legacy-'.$kind.'-aba-without-user-transaction-stales-pending-ticket');
    }
    // Explicit owned restore fixture, not a public v1 proposal or a migration.
    $restore_v1=function($intent)use($fixture,$conn){$row=queryfx_one($conn,'SELECT bindingJSON FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$intent['transfer_id']);$data=phutil_json_decode($row['bindingJSON']);
      $conn->openTransaction();try{foreach(array('subject','responsible')as$key){$data['sources'][$key]=PhorgeGovernanceNativeIdentitySource::lockIdentityPHID($data['sources'][$key]['userPHID'],$conn);}$conn->saveTransaction();}
      catch(Throwable$error){$conn->killTransaction();throw$error;}
      $data['schema']='native-organization-transfer-v1';$json=PhorgeGovernanceOrganizationApprovalAdapter::canonicalJSON($data);
      governance_native_concurrency_sql($fixture,'UPDATE '.$fixture->table('user','organization_transfer').' SET bindingJSON=?,requestDigest=? WHERE transferID=?',array($json,hash('sha256',$json),$intent['transfer_id']));};
    list($old,$old_intent)=$proposal('oldv1');$restore_v1($old_intent);$old_context=governance_native_request($old,$state['sessions']);PhorgeOrganizationService::newForActor($old_context['context'])->readActorCapabilities();$d=$service->readTransfer($old_intent['transfer_id']);
    $check($refuse(function()use($review,$old_intent,$d){$review->approveTransfer($old_intent['transfer_id'],$d['request_digest']);},'organization_transfer_source_stale')&&
      phutil_json_decode(queryfx_one($conn,'SELECT bindingJSON FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$old_intent['transfer_id'])['bindingJSON'])['schema']==='native-organization-transfer-v1',
      'native-noncredential-v1-restored-ticket-keeps-original-first-issuer-stale-boundary-without-reinterpretation');
    list($stable,$stable_intent)=$proposal('stablev1');$stable_context=governance_native_request($stable,$state['sessions']);PhorgeOrganizationService::newForActor($stable_context['context'])->readActorCapabilities();$restore_v1($stable_intent);$d=$service->readTransfer($stable_intent['transfer_id']);
    $review->approveTransfer($stable_intent['transfer_id'],$d['request_digest']);$service->executeTransfer($stable_intent['transfer_id'],true);
    $check(queryfx_one($conn,'SELECT state FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$stable_intent['transfer_id'])['state']==='executed',
      'native-noncredential-v1-restored-unchanged-facts-remain-executable-under-legacy-rules');
    list($mismatch,$mismatch_intent)=$proposal('mismatch');$row=queryfx_one($conn,'SELECT bindingJSON FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$mismatch_intent['transfer_id']);$data=phutil_json_decode($row['bindingJSON']);$data['schema']='native-organization-transfer-v1';$json=PhorgeGovernanceOrganizationApprovalAdapter::canonicalJSON($data);
    governance_native_concurrency_sql($fixture,'UPDATE '.$fixture->table('user','organization_transfer').' SET bindingJSON=?,requestDigest=? WHERE transferID=?',array($json,hash('sha256',$json),$mismatch_intent['transfer_id']));$d=$service->readTransfer($mismatch_intent['transfer_id']);
    $check($refuse(function()use($review,$mismatch_intent,$d){$review->approveTransfer($mismatch_intent['transfer_id'],$d['request_digest']);},'organization_transfer_source_stale'),
      'native-noncredential-v2-facts-cannot-be-reinterpreted-by-restamping-stored-v1-schema');
    $namespace=$fixture->getNamespace();if(!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)){throw new RuntimeException('noncredential_fixture_namespace_refused');}
    $table=$fixture->table('user','organization_identityepoch');$hold='`'.$namespace.'_user`.`organization_identityepoch_v2_hold`';$renamed=false;
    try{governance_native_setup_query($fixture,'RENAME TABLE '.$table.' TO '.$hold);$renamed=true;$failed=false;$conn->openTransaction();
      try{PhorgeGovernanceNativeNoncredentialIdentitySource::lockIdentityPHID($subject->getPHID(),$conn);}
      catch(AphrontSchemaQueryException$error){if(get_class($error)!==AphrontSchemaQueryException::class||!str_starts_with($error->getMessage(),'#1146:')||strpos($error->getMessage(),'organization_identityepoch')===false){throw$error;}$failed=true;}
      finally{if($conn->isInsideTransaction()){$conn->killTransaction();}}
      $check($failed,'native-noncredential-v2-missing-epoch-table-never-falls-back-to-absent-row-one');
    }finally{if($renamed){governance_native_setup_query($fixture,'RENAME TABLE '.$hold.' TO '.$table);}}
    $changed=false;try{governance_native_setup_query($fixture,'ALTER TABLE '.$table.' ENGINE=MyISAM');$changed=true;$failed=false;$conn->openTransaction();
      try{PhorgeGovernanceNativeNoncredentialIdentitySource::lockIdentityPHID($subject->getPHID(),$conn);}
      catch(RuntimeException$error){if($error->getMessage()!=='governance_native_transactional_source_required'){throw$error;}$failed=true;}
      finally{if($conn->isInsideTransaction()){$conn->killTransaction();}}
      $check($failed,'native-noncredential-v2-noninnodb-epoch-source-refused-before-returning-facts');
    }finally{if($changed){governance_native_setup_query($fixture,'ALTER TABLE '.$table.' ENGINE=InnoDB');}}
    return array('noncredential_first_issuer_stable'=>true,'native_and_legacy_aba_refused'=>true,'legacy_v1_not_reinterpreted'=>true,
      'missing_and_nontransactional_schema_refused'=>true,'credential_source_downgraded'=>false,'production_activation'=>false);
  }finally{$_COOKIE=$cookie;$_SERVER=$server;}
}
