<?php
/** Executable native SQL cases, never a simulated actor or an authorization API. */
function dneed($v) {if(!$v)throw new RuntimeException('description_qualification_refused');}
function dproject(array$p) {
 $o=(new PhabricatorProject())->loadOneWhere('phid=%s',$p['target_phid']);dneed($o!==null);
 $members=PhabricatorEdgeQuery::loadDestinationPHIDs($o->getPHID(),PhabricatorProjectMaterializedMemberEdgeType::EDGECONST);
 return$o->attachMemberPHIDs($members)->attachParentProject(null);
}
function dactor(array$p,$role) {
 $raw=qprivate($p['actors_path']);dneed(hash('sha256',$raw)===$p['actors_sha256']);$actors=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
 dneed(isset($actors[$role])&&array_keys($actors[$role])===array('user_phid','token'));
 $a=$actors[$role];$u=(new PhabricatorUser())->loadOneWhere('phid=%s',$a['user_phid']);
 dneed($u!==null&&$u->getIsApproved()&&!$u->getIsDisabled()&&!$u->getIsSystemAgent()&&!$u->getIsMailingList());
 dneed((bool)$u->getIsAdmin()===($role!=='recipient'));
 return array($u,PhorgeGovernanceNativeActorContext::newFromAuthenticatedAPIToken($u,new PhutilOpaqueEnvelope($a['token'])));
}
function dcapabilities($user,$project) {
 $view=PhabricatorPolicyFilter::hasCapability($user,$project,PhabricatorPolicyCapability::CAN_VIEW);
 $edit=PhabricatorPolicyFilter::hasCapability($user,$project,PhabricatorPolicyCapability::CAN_EDIT);
 dneed($view===true&&$edit===false&&!$user->getIsAdmin()&&!$user->isOmnipotent());
 return array('native_VIEW'=>true,'native_EDIT'=>false,'ordinary_account'=>true);
}
function dfield($o) {
 $f=PhabricatorCustomField::getObjectField($o,PhabricatorCustomField::ROLE_APPLICATIONTRANSACTIONS,PhorgeProjectDescriptionExecutor::FIELD);
 dneed($f!==null&&get_class($f->newStorageObject())===PhabricatorProjectCustomFieldStorage::class);return$f;
}
function dstate(array$p,$test_read=null) {
 if($test_read!==null)dneed(defined('N05_DESCRIPTION_QUALIFICATION_LIBRARY_ONLY')&&N05_DESCRIPTION_QUALIFICATION_LIBRARY_ONLY===true&&is_callable($test_read));
 $c=$test_read===null?qconnection($p):null;if($c!==null)dneed(!$c->isInsideTransaction());
 // The optional reader is confined to library-only snapshot controls. It is not
 // accepted by executor/cases/entry, and never supplies an authorization source.
 $read=$test_read??static function($kind,$arg=null)use($c,$p){
  if($kind==='project')return queryfx_one($c,'SELECT * FROM %R WHERE phid=%s',new PhabricatorProject(),$p['target_phid']);
  if($kind==='fields')return queryfx_all($c,'SELECT * FROM %R WHERE objectPHID=%s ORDER BY fieldIndex',new PhabricatorProjectCustomFieldStorage(),$p['target_phid']);
  if($kind==='edges')return queryfx_all($c,'SELECT * FROM edge WHERE src=%s ORDER BY type,dst',$p['target_phid']);
  if($kind==='transactions')return queryfx_all($c,'SELECT * FROM %R WHERE objectPHID=%s ORDER BY id',new PhabricatorProjectTransaction(),$p['target_phid']);
  if($kind==='audit')return queryfx_all($c,'SELECT * FROM governance_contentaudit WHERE targetPHID=%s ORDER BY id',$p['target_phid']);
  if($kind==='tasks'){$task=new PhabricatorWorkerActiveTask();return queryfx_all($task->establishConnection('r'),'SELECT * FROM %R WHERE objectPHID=%s ORDER BY id',$task,$p['target_phid']);}
  if($kind==='task-data'){$row=(new PhabricatorWorkerTaskData())->loadOneWhere('id=%d',$arg);dneed($row!==null);return$row->getData();}
  if($kind==='identities'){$raw=qprivate($p['actors_path']);$roles=json_decode($raw,true,8,JSON_THROW_ON_ERROR);$ids=array_column($roles,'user_phid');sort($ids,SORT_STRING);return queryfx_all((new PhabricatorUser())->establishConnection('r'),'SELECT * FROM %R WHERE phid IN (%Ls) ORDER BY phid',new PhabricatorUser(),$ids);}
  throw new RuntimeException('description_snapshot_source_refused');
 };
 $project=$read('project');dneed(is_array($project)&&$project['phid']===$p['target_phid']);$o=(new PhabricatorProject())->loadFromArray($project);$f=dfield($o);
 $fields=$read('fields');$value=null;$others=array();
 foreach($fields as$r)if($r['fieldIndex']===$f->getFieldIndex())$value=$r['fieldValue'];else$others[]=$r;
 $f->setValueFromStorage($value);$f->didSetValueFromStorage();
 $edges=$read('edges');$transactions=$read('transactions');$audit=$read('audit');$tasks=$read('tasks');$data=array();
 foreach($tasks as$t)$data[(string)$t['id']]=$read('task-data',$t['dataID']);
 $identities=$read('identities');dneed(count($identities)===3);
 // Keep ALL original customfield row bytes/keys/IDs, not only semantic decode.
 // Private in-memory snapshots use strict PHP array equality, no lossy JSON.
 return array('identities'=>$identities,'project'=>$project,'description'=>$f->getOldValueForApplicationTransactions(),'storage_rows'=>$fields,'other_fields'=>$others,'edges'=>$edges,'transactions'=>$transactions,'audit'=>$audit,'tasks'=>$tasks,'task_data'=>$data);
}
function dpayload(array$p,$grant,$description) {
 return array('target_phid'=>$p['target_phid'],'grant_id'=>$grant,'description'=>$description,
  'expected_description_digest'=>PhorgeProjectExecutionValue::digest(dstate($p)['description']));
}
function dgrant(array$p,$kind,$seconds=86400) {
 [, $requester]=dactor($p,'requester');[, $approver]=dactor($p,'approver');
 $r=array('action'=>'grant','grant_id'=>null,'reason'=>'Isolated native description execution qualification',
  'subject_id'=>$p['subjects'][$kind],'subject_kind'=>$kind,'target_phid'=>$p['target_phid'],'valid_for_seconds'=>$seconds);
 $ticket=PhorgeProjectContentGrantService::call($requester,'submit',$r);
 $applied=PhorgeProjectContentGrantService::call($approver,'approve',array('approval_id'=>$ticket['approval_id']));
 dneed($applied['grant_persisted']===true&&$applied['grant_revoked']===false);
 return$applied['grant_id'];
}
function drevoke(array$p,$kind,$grant) {
 [, $requester]=dactor($p,'requester');[, $approver]=dactor($p,'approver');
 $r=array('action'=>'revoke','grant_id'=>$grant,'reason'=>'Isolated native description execution qualification',
  'subject_id'=>$p['subjects'][$kind],'subject_kind'=>$kind,'target_phid'=>$p['target_phid'],'valid_for_seconds'=>null);
 $ticket=PhorgeProjectContentGrantService::call($requester,'submit',$r);
 $out=PhorgeProjectContentGrantService::call($approver,'approve',array('approval_id'=>$ticket['approval_id']));dneed($out['grant_revoked']===true);
}
function drefusal_matches(Throwable$e,$reason) {
 return $reason==='audit-fault'?($e instanceof AphrontQueryException&&(int)$e->getCode()===1644):(get_class($e)===RuntimeException::class&&$e->getMessage()===$reason);
}
function drefused(array$p,$call,$reason) {
 $before=dstate($p);$caught=false;try{$call();}catch(Throwable$e){$caught=drefusal_matches($e,$reason);}
 dneed($caught&&dstate($p)===$before);return array('refused'=>true,'all_target_bytes_unchanged'=>true);
}
function dpositive(array$p,$context,$grant,$description) {
 dcapabilities((new PhabricatorUser())->loadOneWhere('phid=%s',$context->getUserPHID()),dproject($p));
 $before=dstate($p);$out=PhorgeProjectDescriptionExecutor::execute($context,dpayload($p,$grant,$description));$after=dstate($p);
 dneed($out['native_EDIT_granted']===false&&$out['native_transaction_count']===1);
 dcapabilities((new PhabricatorUser())->loadOneWhere('phid=%s',$context->getUserPHID()),dproject($p));
 return dpositive_delta($before,$after,$p['target_phid'],$context->getUserPHID(),$grant,$description);
}
/** Detached observation validator only; vectors are never SQL execution proof. */
function dpositive_delta(array$before,array$after,$target,$actor,$grant,$description) {
 dneed($after['description']===$description&&$after['identities']===$before['identities']);
 $old=$before['project'];$new=$after['project'];$new['dateModified']=$old['dateModified'];dneed($new===$old&&$after['other_fields']===$before['other_fields']&&$after['edges']===$before['edges']);
 dneed(count($after['transactions'])===count($before['transactions'])+1);
 $x=last($after['transactions']);dneed($x['transactionType']===PhorgeProjectDescriptionExecutor::TYPE&&$x['authorPHID']===$actor);
 dneed(array_slice($after['transactions'],0,count($before['transactions']))===$before['transactions']);
 dneed(count($after['audit'])===count($before['audit'])+1);$a=last($after['audit']);dneed($a['grantID']===$grant&&$a['action']==='description-updated'&&$a['actorPHID']===$actor);
 dneed(array_slice($after['audit'],0,count($before['audit']))===$before['audit']);
 foreach($before['tasks']as$t){$found=array_values(array_filter($after['tasks'],static fn($n)=>$n['id']===$t['id']));dneed($found===array($t)&&$before['task_data'][(string)$t['id']]===$after['task_data'][(string)$t['id']]);}
 $newtasks=array_values(array_filter($after['tasks'],static fn($t)=>!in_array($t['id'],array_column($before['tasks'],'id'),true)));
 dneed(count($newtasks)===1&&$newtasks[0]['taskClass']==='PhabricatorApplicationTransactionPublishWorker');
 $data=$after['task_data'][(string)$newtasks[0]['id']];dneed($data['objectPHID']===$target&&$data['actorPHID']===$actor&&$data['xactionPHIDs']===array($x['phid']));
 dneed($data['state']['mailShouldSend']===true&&$data['state']['feedShouldPublish']===true&&in_array($actor,$data['state']['mailToPHIDs'],true));
 return array('native_applyTransactions_committed'=>true,'description_and_use_audit_persisted'=>true,'native_EDIT_granted'=>false,
  'security_and_other_fields_unchanged'=>true,'native_publish_task_persisted'=>true,'native_mail_and_feed_intent_persisted'=>true,'publish_worker_executed'=>false,'mail_feed_search_delivery_verified'=>false);
}
function datomic_rollback(array$p,$context,$grant) {
 $c=qconnection($p);$name='n05_description_qualification_fail_audit';
 dneed(queryfx_one($c,'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=%s',$name)===null);
 // Exact isolated namespace only; test-owned failpoint refuses the actual use-audit insert.
 queryfx($c,'CREATE TRIGGER %T BEFORE INSERT ON governance_contentaudit FOR EACH ROW BEGIN IF NEW.action = %s THEN SIGNAL SQLSTATE %s SET MESSAGE_TEXT = %s; END IF; END',$name,'description-updated','45000','isolated-description-use-audit-failpoint');
 try{$out=drefused($p,static fn()=>PhorgeProjectDescriptionExecutor::execute($context,dpayload($p,$grant,'Must roll back with audit')),'audit-fault');}
 finally{queryfx($c,'DROP TRIGGER %T',$name);}
 return$out+array('real_SQL_trigger_rejected_use_audit'=>true,'description_transaction_and_queue_rolled_back'=>true);
}
function dcases(array$p) {
 [$ordinary,$context]=dactor($p,'recipient');$cap=dcapabilities($ordinary,dproject($p));$results=array();
 $results['no-grant']=drefused($p,static fn()=>PhorgeProjectDescriptionExecutor::execute($context,dpayload($p,str_repeat('0',64),'No grant')),'description_current_grant_required');
 $usergrant=dgrant($p,'user');$results['user-positive']=dpositive($p,$context,$usergrant,'User grant synthetic description');
 $results['audit-fault-rollback']=datomic_rollback($p,$context,$usergrant);
 $results['direct-modular-no-scope']=drefused($p,static function()use($p,$ordinary){$o=dproject($p);$x=(new PhabricatorProjectTransaction())->setTransactionType(PhorgeProjectDescriptionExecutor::TYPE)->setMetadataValue('customfield:key',PhorgeProjectDescriptionExecutor::FIELD)->setNewValue('Unsealed attempt');$o->getApplicationTransactionEditor()->setActor($ordinary)->setContentSource(PhabricatorContentSource::newForSource(PhabricatorConduitContentSource::SOURCECONST))->applyTransactions($o,array($x));},'description_sealed_dispatch_required');
 drevoke($p,'user',$usergrant);$results['revoked']=drefused($p,static fn()=>PhorgeProjectDescriptionExecutor::execute($context,dpayload($p,$usergrant,'Revoked attempt')),'description_current_grant_required');
 $teamgrant=dgrant($p,'business-team');$results['team-positive']=dpositive($p,$context,$teamgrant,'Direct business team synthetic description');
 $short=dgrant($p,'user',10);$c=qconnection($p);$row=queryfx_one($c,'SELECT validUntil FROM governance_contentgrant WHERE grantID=%s',$short);$deadline=microtime(true)+12;dneed((int)queryfx_one($c,'SELECT UNIX_TIMESTAMP() n')['n']<(int)$row['validUntil']);
 while((int)queryfx_one($c,'SELECT UNIX_TIMESTAMP() n')['n']<(int)$row['validUntil']){dneed(microtime(true)<$deadline);usleep(20000);}
 $results['expired']=drefused($p,static fn()=>PhorgeProjectDescriptionExecutor::execute($context,dpayload($p,$short,'Expired attempt')),'description_current_grant_required');
 // Revoke the exact actual credential, retaining its already-created context to test recapture.
 $raw=qprivate($p['actors_path']);$actors=json_decode($raw,true,8,JSON_THROW_ON_ERROR);$token=(new PhabricatorConduitToken())->loadOneWhere('token=%s',$actors['recipient']['token']);dneed($token!==null&&$token->getObjectPHID()===$ordinary->getPHID());$token->delete();
 $results['credential-revoked']=drefused($p,static fn()=>PhorgeProjectDescriptionExecutor::execute($context,dpayload($p,$teamgrant,'Deleted token attempt')),'governance_native_credential_ineligible');
 // New authentic token permits the distinct account-disable case; never manufacture a context.
 $token=PhabricatorConduitToken::initializeNewToken($ordinary->getPHID(),PhabricatorConduitToken::TYPE_COMMANDLINE);$token->save();$next=PhorgeGovernanceNativeActorContext::newFromAuthenticatedAPIToken($ordinary,new PhutilOpaqueEnvelope($token->getToken()));
 [$admin]=dactor($p,'requester');$x=(new PhabricatorUserTransaction())->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue(true);
 $ordinary->getApplicationTransactionEditor()->setActor($admin)->setContentSource(PhabricatorContentSource::newForSource(PhabricatorConsoleContentSource::SOURCECONST))->applyTransactions($ordinary,array($x));
 try{$results['account-disabled']=drefused($p,static fn()=>PhorgeProjectDescriptionExecutor::execute($next,dpayload($p,$teamgrant,'Disabled attempt')),'governance_native_credential_ineligible');}
 finally{$token->delete();}
 dneed(count($results)===9);dneed((new PhabricatorUser())->loadOneWhere('phid=%s',$ordinary->getPHID())->getIsDisabled());
 return array('schema'=>'N05-native-description-SQL-cases-v1','cases'=>$results,'initial_capabilities'=>$cap,'execution_authorized'=>false,
  'HTTP_verified'=>false,'publish_worker_executed'=>false,'mail_feed_search_delivery_verified'=>false,'online_activation'=>false);
}
