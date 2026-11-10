"""Real native PHP classes and fail-closed dispatch; no SQL/actor fixture proof."""
import json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
def php(code):
 pre='require '+json.dumps(str(ROOT/'support/runtime/src/__phutil_library_init__.php'))+';phutil_load_library('+json.dumps(str(ROOT/'src'))+');'
 pre+="$stack=(new PhabricatorConfigStackSource())->pushSource(new PhabricatorConfigDefaultSource());(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,[]);"
 q=subprocess.run(['php','-r',pre+code],cwd=ROOT,capture_output=True,text=True,timeout=20)
 if q.returncode:raise AssertionError(q.stderr)
 return json.loads(q.stdout)
class DescriptionExecution(unittest.TestCase):
 def test_actual_strict_request_refuses_capability_actor_other_objects_and_overflow(self):
  d=php(r'''$p=['target_phid'=>'PHID-PROJ-'.str_repeat('a',20),'grant_id'=>str_repeat('b',64),'description'=>'项目描述','expected_description_digest'=>str_repeat('c',64)];$r=PhorgeProjectDescriptionRequest::newFromPayload($p);$n=0;foreach([['actor','PHID-USER-x'],['CAN_EDIT',true],['description',null],['description',str_repeat('a',65537)],['target_phid','PHID-TASK-'.str_repeat('a',20)],['grant_id','bad'],['expected_description_digest',false]]as$c){$x=$p;$x[$c[0]]=$c[1];try{PhorgeProjectDescriptionRequest::newFromPayload($x);throw new Exception('accepted');}catch(InvalidArgumentException$e){$n++;}}$v=0;foreach([true,-1,'01','18446744073709551615']as$x){try{PhorgeProjectDescriptionRequest::unsigned($x);throw new Exception('accepted');}catch(RuntimeException$e){$v++;}}echo json_encode(['description'=>$r->getDescription(),'negative'=>$n,'bad_period'=>$v,'zero'=>PhorgeProjectDescriptionRequest::unsigned('0')]);''')
  self.assertEqual(d,{'description':'项目描述','negative':7,'bad_period':4,'zero':0})
 def test_actual_grant_row_decoder_fixed_scope_and_fresh_period_is_not_authority(self):
  d=php(r'''$actor='PHID-USER-'.str_repeat('d',20);$p=['target_phid'=>'PHID-PROJ-'.str_repeat('a',20),'grant_id'=>str_repeat('b',64),'description'=>'new','expected_description_digest'=>str_repeat('c',64)];$r=PhorgeProjectDescriptionRequest::newFromPayload($p);$row=['grantID'=>$p['grant_id'],'targetPHID'=>$p['target_phid'],'operation'=>'project.description','subjectKind'=>'user','subjectID'=>$actor,'validFrom'=>'10','validUntil'=>'30','revokedAt'=>null,'version'=>'1'];PhorgeProjectDescriptionRequest::requireGrantFields($row,$r,$actor,20);$team=$row;$team['subjectKind']='business-team';$team['subjectID']='business.example';PhorgeProjectDescriptionRequest::requireGrantFields($team,$r,$actor,20);$n=0;foreach([['targetPHID','PHID-PROJ-'.str_repeat('e',20)],['operation','task.description'],['subjectKind','department'],['subjectID','PHID-USER-'.str_repeat('f',20)],['validFrom','21'],['validUntil','20'],['revokedAt','11'],['version','2'],['version',true],['validUntil','18446744073709551615'],['actor',true]]as$c){$x=$row;$x[$c[0]]=$c[1];try{PhorgeProjectDescriptionRequest::requireGrantFields($x,$r,$actor,20);throw new Exception('accepted');}catch(RuntimeException$e){$n++;}}echo json_encode(['refused'=>$n,'decoder_only'=>true]);''')
  self.assertEqual(d,{'refused':11,'decoder_only':True})
 def test_default_OFF_direct_native_transaction_refuses_before_source_IO(self):
  d=php(r'''$e=new PhabricatorProjectTransactionEditor();$o=new PhabricatorProject();$x=(new PhabricatorProjectTransaction())->setTransactionType(PhorgeProjectDescriptionExecutor::TYPE);$reason=null;try{PhorgeProjectNativeExecutionScope::beginEditor($e,$o,[$x]);}catch(RuntimeException$z){$reason=$z->getMessage();}echo json_encode(['reason'=>$reason,'empty'=>(new PhorgeProjectDescriptionTransaction())->validateTransactions($o,[])]);''')
  self.assertEqual(d,{'reason':'content_grant_disabled','empty':[]})
 def test_enabled_normal_Project_Conduit_or_handbuilt_editor_has_no_sealed_scope(self):
  d=php(r'''$flags=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.project-approval.enabled',true)->overrideEnvConfig('governance.project-content-grants.enabled',true);$e=new PhabricatorProjectTransactionEditor();$o=new PhabricatorProject();$x=(new PhabricatorProjectTransaction())->setTransactionType(PhorgeProjectDescriptionExecutor::TYPE);$type=(new PhorgeProjectDescriptionTransaction())->setEditor($e)->setStorage($x);$a=[];foreach(['entry','capability','old','internal','external']as$mode){try{if($mode==='entry'){PhorgeProjectNativeExecutionScope::beginEditor($e,$o,[$x]);}elseif($mode==='capability'){$type->getRequiredCapabilities($o,$x);}elseif($mode==='old'){$type->generateOldValue($o);}elseif($mode==='internal'){$type->applyInternalEffects($o,'x');}else{$type->applyExternalEffects($o,'x');}throw new Exception('accepted');}catch(RuntimeException$z){$a[]=$z->getMessage();}}echo json_encode($a);''')
  self.assertEqual(d,['description_sealed_dispatch_required']*5)
 def test_actual_builtin_native_description_field_decodes_without_Project_description_column(self):
  d=php(r'''$o=new PhabricatorProject();$f=PhabricatorCustomField::getObjectField($o,PhabricatorCustomField::ROLE_APPLICATIONTRANSACTIONS,PhorgeProjectDescriptionExecutor::FIELD);$f->setValueFromStorage('旧描述');$f->didSetValueFromStorage();$old=$f->getOldValueForApplicationTransactions();$x=(new PhabricatorProjectTransaction())->setNewValue('新描述');echo json_encode(['field'=>$f->getFieldKey(),'storage'=>get_class($f->newStorageObject()),'old'=>$old,'new'=>$f->getNewValueFromApplicationTransactions($x),'column'=>array_key_exists('description',$o->getSchemaColumns()),'storage_enabled'=>$f->shouldEnableForRole(PhabricatorCustomField::ROLE_STORAGE),'edit_enabled'=>$f->shouldEnableForRole(PhabricatorCustomField::ROLE_EDIT)]);''')
  self.assertEqual(d,{'field':'std:project:internal:description','storage':'PhabricatorProjectCustomFieldStorage','old':'旧描述','new':'新描述','column':False,'storage_enabled':True,'edit_enabled':True})
 def test_native_remarkup_event_keeps_builtin_old_null_semantics_and_mail_tag(self):
  d=php(r'''$o=new PhabricatorProject();$f=PhabricatorCustomField::getObjectField($o,PhabricatorCustomField::ROLE_APPLICATIONTRANSACTIONS,PhorgeProjectDescriptionExecutor::FIELD);$legacy=(new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_CUSTOMFIELD)->setMetadataValue('customfield:key',PhorgeProjectDescriptionExecutor::FIELD)->setOldValue('old')->setNewValue('new @synthetic');$x=(new PhabricatorProjectTransaction())->setTransactionType(PhorgeProjectDescriptionExecutor::TYPE)->setOldValue('old')->setNewValue('new @synthetic');$t=(new PhorgeProjectDescriptionTransaction())->setStorage($x);$changes=$t->newRemarkupChanges();echo json_encode(['blocks'=>$f->getApplicationTransactionRemarkupBlocks($legacy),'old'=>$changes[0]->getOldValue(),'new'=>$changes[0]->getNewValue(),'same_tags'=>$x->getMailTags()===$legacy->getMailTags(),'diff'=>$t->hasChangeDetailView()]);''')
  self.assertEqual(d,{'blocks':['new @synthetic'],'old':None,'new':'new @synthetic','same_tags':True,'diff':True})
 def test_actual_native_group_metadata_is_bound_to_same_editor_and_effects_phase_without_SQL(self):
  # Reflect only the guard's isolated bookkeeping. This is not a sealed executor,
  # live identity scope, transaction lease, SQL write or production permission.
  d=php(r'''$make=static function(){
    $scope=(new ReflectionClass(PhorgeProjectNativeExecutionScope::class))->newInstanceWithoutConstructor();
    $editor=new PhabricatorProjectTransactionEditor();
    $x=(new PhabricatorProjectTransaction())->setTransactionType(PhorgeProjectDescriptionExecutor::TYPE)
      ->setMetadataValue('customfield:key',PhorgeProjectDescriptionExecutor::FIELD)->setNewValue('synthetic');
    foreach(['mode'=>'content-description','state'=>'open']as$k=>$v){(new ReflectionProperty(PhorgeProjectNativeExecutionScope::class,$k))->setValue($scope,$v);}
    $scope->bindDescriptionEditor($editor,$x);return[$scope,$editor,$x];
  };
  $set=static function($scope,$k,$v){(new ReflectionProperty(PhorgeProjectNativeExecutionScope::class,$k))->setValue($scope,$v);};
  $effect=static function($scope,$editor)use($set){$nonce=bin2hex(random_bytes(16));
    (new ReflectionProperty(PhabricatorApplicationTransactionEditor::class,'projectGovernanceEffectsNonce'))->setValue($editor,$nonce);
    $set($scope,'effectsNonce',$nonce);$set($scope,'state','consumed');};
  $guard=new ReflectionMethod(PhorgeProjectNativeExecutionScope::class,'assertDescriptionMetadata');
  $native_group=new ReflectionMethod(PhabricatorApplicationTransactionEditor::class,'getTransactionGroupID');
  $refused=0;$accepts=0;
  [$scope,$editor,$x]=$make();$guard->invoke($scope,$x);$accepts++;
  $effect($scope,$editor);$guard->invoke($scope,$x);$accepts++; // Native internal effects precede group assignment.
  $group=$native_group->invoke($editor);$x->setTransactionGroupID($group);$guard->invoke($scope,$x);$accepts++;
  $x->setIsMFATransaction(true);$guard->invoke($scope,$x);$accepts++;
  foreach(['different-group','missing-group','changed-editor','changed-nonce','wrong-phase','false-MFA','unknown-key','reset-native-group','both-groups-changed','invalid-native-group','lost-effects-nonce']as$case){
    [$a,$e,$t]=$make();$effect($a,$e);$g=$native_group->invoke($e);$t->setTransactionGroupID($g);$guard->invoke($a,$t);
    if($case==='different-group'){$t->setTransactionGroupID(str_repeat('a',32)===$g?str_repeat('b',32):str_repeat('a',32));}
    elseif($case==='missing-group'){$t->setMetadata(['customfield:key'=>PhorgeProjectDescriptionExecutor::FIELD]);}
    elseif($case==='changed-editor'){$other=new PhabricatorProjectTransactionEditor();$native_group->invoke($other);$set($a,'editor',$other);}
    elseif($case==='changed-nonce'){(new ReflectionProperty(PhabricatorApplicationTransactionEditor::class,'projectGovernanceEffectsNonce'))->setValue($e,bin2hex(random_bytes(16)));}
    elseif($case==='wrong-phase'){$set($a,'state','open');}
    elseif($case==='false-MFA'){$t->setIsMFATransaction(false);}
    elseif($case==='unknown-key'){$t->setMetadataValue('core.synthetic-unknown',true);}
    elseif($case==='reset-native-group'){(new ReflectionProperty(PhabricatorApplicationTransactionEditor::class,'transactionGroupID'))->setValue($e,null);$t->setMetadata(['customfield:key'=>PhorgeProjectDescriptionExecutor::FIELD]);}
    elseif($case==='invalid-native-group'){(new ReflectionProperty(PhabricatorApplicationTransactionEditor::class,'transactionGroupID'))->setValue($e,'invalid');$t->setTransactionGroupID('invalid');}
    elseif($case==='lost-effects-nonce'){$set($a,'effectsNonce',null);}
    else{$other=new PhabricatorProjectTransactionEditor();$new=$native_group->invoke($other);(new ReflectionProperty(PhabricatorApplicationTransactionEditor::class,'transactionGroupID'))->setValue($e,$new);$t->setTransactionGroupID($new);}
    try{$guard->invoke($a,$t);throw new Exception('accepted');}catch(RuntimeException $expected){$refused++;}
  }
  foreach(['caller-group','preinitialized-editor','null-group-key']as$case){
    $a=(new ReflectionClass(PhorgeProjectNativeExecutionScope::class))->newInstanceWithoutConstructor();$set($a,'mode','content-description');$set($a,'state','open');
    $e=new PhabricatorProjectTransactionEditor();$t=(new PhabricatorProjectTransaction())->setMetadataValue('customfield:key',PhorgeProjectDescriptionExecutor::FIELD);
    if($case==='preinitialized-editor'){$native_group->invoke($e);}else{$t->setTransactionGroupID($case==='null-group-key'?null:str_repeat('a',32));}
    try{$a->bindDescriptionEditor($e,$t);throw new Exception('accepted');}catch(RuntimeException $expected){$refused++;}
  }
  [$a,$e,$t]=$make();$t->setTransactionGroupID($native_group->invoke($e));
  try{$guard->invoke($a,$t);throw new Exception('early accepted');}catch(RuntimeException $expected){$refused++;}
  echo json_encode(['guard_accepts'=>$accepts,'guard_refuses'=>$refused,'actual_native_group_getter_and_setter'=>true,'synthetic_scope_only'=>true,'SQL'=>false,'execution_authorized'=>false]);''')
  self.assertEqual(d,{'guard_accepts':4,'guard_refuses':15,'actual_native_group_getter_and_setter':True,'synthetic_scope_only':True,'SQL':False,'execution_authorized':False})
 def test_unchanged_generic_EDITOR_and_sealed_scope_source_boundaries(self):
  rel='src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php';old=subprocess.check_output(['git','-C',str(ROOT),'show','HEAD:'+rel]);self.assertEqual((ROOT/rel).read_bytes(),old)
  s=(ROOT/'src/infrastructure/governance/execution/PhorgeProjectNativeExecutionScope.php').read_text();self.assertIn('$scope->authorizeDescription();',s);self.assertIn('description_expanded_closure_refused',s);self.assertIn('description_unplanned_project_change',s);self.assertIn('description_membership_changed',s);self.assertIn('description_dual_admin_binding_required',s)
  e=(ROOT/'src/infrastructure/governance/content/PhorgeProjectDescriptionExecutor.php').read_text();self.assertIn("getCredentialType()!=='api-token'",e);self.assertNotIn('getOmnipotentUser',e);self.assertNotIn('CAN_EDIT',e)
if __name__=='__main__':unittest.main()
