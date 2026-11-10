<?php
/** Early isolated native creation; never edit an existing protected Project. */
const N05_DESCRIPTION_FIXTURE_PROFILE_SHA256=null;
try {
 if($argc!==5 || N05_DESCRIPTION_FIXTURE_PROFILE_SHA256===null)throw new RuntimeException('description_fixture_not_admitted');
 [$script,$root,$profile,$out,$pin]=$argv;
 if(realpath($root)!==$root || realpath($profile)!==$profile || $pin!==N05_DESCRIPTION_FIXTURE_PROFILE_SHA256 || hash_file('sha256',$profile)!==$pin)throw new RuntimeException('description_fixture_not_admitted');
 define('N05_PROVISION_LIBRARY_ONLY',true);require __DIR__.'/native.php';
 $p=json_decode(nprivate($profile),true,16,JSON_THROW_ON_ERROR);
 nneed($p['base_root']===$root && $p['base_commit']==='4c2245300dc1d78d042b95190dd537eb6b1f9861' && $p['isolated_features_authorized']===true);
 nneed(realpath($out)===$out && preg_match('/\A[a-f0-9]{32}\z/D',$p['owner']) && $p['namespace']==='n05_grants_'.$p['owner']);
 $argv=array($script);$argc=1;require $root.'/scripts/init/init-script.php';nprofile($p);
 nneed(PHP_VERSION==='8.5.12' && ini_get('opcache.jit')==='disable' && ini_get('opcache.jit_buffer_size')==='0' && ini_get('opcache.enable_cli')==='0');
 nneed(queryfx_one(nconnection($p,new PhabricatorProject()),'SELECT VERSION() v')['v']==='8.4.13');
 PhabricatorWorker::setRunAllTasksInProcess(false);$unguarded=AphrontWriteGuard::beginScopedUnguardedWrites();
 try {
  $admin=nuser($p,'requester');nuser($p,'approver');$project=PhabricatorProject::initializeNewProject($admin);
  nneed(!$project->getID());$name='Description execution fixture '.$p['owner'];nneed((new PhabricatorProject())->loadOneWhere('name=%s',$name)===null);
  $a=(new PhabricatorProjectTransaction())->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)->setNewValue($name);
  $e=(new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_EDIT_POLICY)->setNewValue(PhabricatorPolicies::POLICY_ADMIN);
  $v=(new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_VIEW_POLICY)->setNewValue(PhabricatorPolicies::POLICY_USER);
  $project->getApplicationTransactionEditor()->setActor($admin)->setContentSource(PhabricatorContentSource::newForSource(PhabricatorConsoleContentSource::SOURCECONST))->setContinueOnNoEffect(true)->applyTransactions($project,array($a,$e,$v));
  nneed($project->getID() && $project->getPHID());$recipient=nuser($p,'recipient');
  $members=PhabricatorEdgeQuery::loadDestinationPHIDs($project->getPHID(),PhabricatorProjectMaterializedMemberEdgeType::EDGECONST);$project->attachMemberPHIDs($members)->attachParentProject(null);
  nneed(PhabricatorPolicyFilter::hasCapability($recipient,$project,PhabricatorPolicyCapability::CAN_VIEW)===true && PhabricatorPolicyFilter::hasCapability($recipient,$project,PhabricatorPolicyCapability::CAN_EDIT)===false);
 }finally{unset($unguarded);}
 $result=array('schema'=>'N05-description-early-native-fixture-v1','target_phid'=>$project->getPHID(),'owner'=>$p['owner'],'namespace'=>$p['namespace'],'source_revision'=>$p['base_commit'],'native_creation'=>true,'recipient_VIEW'=>true,'recipient_EDIT'=>false,'target_flags_enabled'=>false,'execution_authorized'=>false);
 nwrite($out.'/description-fixture.private.json',json_encode($result,JSON_THROW_ON_ERROR)."\n");echo json_encode(array('native_fixture_created'=>true,'execution_authorized'=>false),JSON_THROW_ON_ERROR)."\n";
}catch(Throwable$e){fwrite(STDERR,"description_fixture_refused_retain_no_retry\n");exit(1);}
