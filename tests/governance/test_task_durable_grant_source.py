"""T1 native classes/value/owned-stack controls. No SQL or live admin qualification."""
import hashlib,json,re,subprocess,unittest
from pathlib import Path
from test_project_description_execution import php
ROOT=Path(__file__).resolve().parents[2]
PAYLOAD=r"$p=['action'=>'grant','grant_id'=>null,'reason'=>'Task body grant','subject_id'=>'PHID-USER-'.str_repeat('b',20),'subject_kind'=>'user','target_phid'=>'PHID-TASK-'.str_repeat('a',20),'valid_for_seconds'=>60];"
class TaskDurableSource(unittest.TestCase):
 def test_request_and_plan_exact_task_body_user_and_team(self):
  d=php(PAYLOAD+r"$out=[];foreach(['user','business-team']as$kind){$p['subject_kind']=$kind;$p['subject_id']=$kind==='user'?'PHID-USER-'.str_repeat('b',20):'business.example';$r=PhorgeTaskContentGrantRequest::submit($p);$plan=PhorgeTaskContentGrantPlan::newForSubmission($r);$plan->assertSubmission($r);$out[]=[$r['operation'],$plan->getPurpose(),$plan->getTargetPHID()];}echo json_encode($out);")
  self.assertEqual(d,[['task.description','submit','PHID-TASK-'+'a'*20]]*2)
 def test_cross_target_actions_and_injected_authority_are_refused(self):
  d=php(PAYLOAD+r"$n=0;foreach([['target_phid','PHID-PROJ-'.str_repeat('a',20)],['target_phid','PHID-WIKI-'.str_repeat('a',20)],['operation','task.title'],['actor','x'],['CAN_EDIT',true],['subject_kind','department'],['subject_kind','project'],['valid_for_seconds',0],['valid_for_seconds',86401],['valid_for_seconds',true]]as$c){$x=$p;$x[$c[0]]=$c[1];try{PhorgeTaskContentGrantRequest::submit($x);}catch(InvalidArgumentException$e){$n++;}}echo json_encode($n);")
  self.assertEqual(d,10)
 def test_revoke_is_explicit_and_never_requires_departed_subject_active(self):
  d=php(PAYLOAD+r"$p['action']='revoke';$p['grant_id']=str_repeat('a',64);$p['valid_for_seconds']=null;echo json_encode(PhorgeTaskContentGrantRequest::submit($p));")
  self.assertEqual(d['operation'],'task.description');self.assertEqual(d['action'],'revoke')
  source=(ROOT/'src/infrastructure/governance/content/PhorgeTaskContentGrantStore.php').read_text();start=source.index("if ($r['action']==='revoke')");end=source.index('return PhorgeTaskContentSource::subject',start);self.assertNotIn('lockIdentityPHID',source[start:end])
 def test_plan_binding_and_untrusted_stored_constructor_refuse(self):
  d=php(PAYLOAD+r"$r=PhorgeTaskContentGrantRequest::submit($p);$q=PhorgeTaskContentGrantPlan::newForSubmission($r);$n=0;$r['reason']='changed';foreach(['request','scope','store','serialize']as$m){try{if($m==='request'){$q->assertSubmission($r);}elseif($m==='scope'){$q->assertScope('history',$p['target_phid']);}elseif($m==='store'){PhorgeTaskContentGrantPlan::newForStoredApproval($p['target_phid'],[]);}else{serialize($q);}}catch(Throwable$e){$n++;}}echo json_encode($n);")
  self.assertEqual(d,4)
 def test_independent_actor_credentials_not_part_of_shared_object_comparison(self):
  d=php(r"$a=['configuration'=>str_repeat('a',64),'credential'=>str_repeat('b',64),'nativeTransactionID'=>'2','spaces'=>str_repeat('c',64),'task'=>str_repeat('d',64)];$b=$a;$b['credential']=str_repeat('e',64);$x=PhorgeTaskContentApprovalBinding::sharedSources($a);$y=PhorgeTaskContentApprovalBinding::sharedSources($b);$n=0;foreach([['task',false],['nativeTransactionID','02'],['authority',true]]as$c){$z=$a;$z[$c[0]]=$c[1];try{PhorgeTaskContentApprovalBinding::sharedSources($z);}catch(Throwable$e){$n++;}}echo json_encode([$x===$y,array_key_exists('credential',$x),$n]);")
  self.assertEqual(d,[True,False,3])
 def test_row_decode_ttl_revoked_version_target_operation_and_subject(self):
  d=php(r"$t='PHID-TASK-'.str_repeat('a',20);$u='PHID-USER-'.str_repeat('b',20);$r=['grantID'=>str_repeat('a',64),'targetPHID'=>$t,'operation'=>'task.description','subjectKind'=>'user','subjectID'=>$u,'validFrom'=>'10','validUntil'=>'30','revokedAt'=>null,'version'=>'1'];$ok=PhorgeTaskContentGrantValue::active($r,$t,'user',$u,20);$n=0;foreach([['targetPHID','PHID-PROJ-'.str_repeat('a',20)],['operation','project.description'],['subjectID','PHID-USER-'.str_repeat('c',20)],['validFrom','21'],['validUntil','20'],['revokedAt','11'],['version','2'],['version',true],['validUntil','18446744073709551615'],['native_EDIT',true]]as$c){$x=$r;$x[$c[0]]=$c[1];try{PhorgeTaskContentGrantValue::active($x,$t,'user',$u,20);}catch(Throwable$e){$n++;}}echo json_encode([$ok,$n]);")
  self.assertEqual(d,[{'active_at_observation':True,'authority':False,'can_execute':False},10])
 def test_direct_membership_decoder_never_transitive_or_expired(self):
  d=php(r"$r=['state'=>'active','unitID'=>'team.example','userPHID'=>'PHID-USER-'.str_repeat('b',20),'validFrom'=>'10','validUntil'=>'30','version'=>'1'];$a=PhorgeTaskContentSource::decodeMembership($r,'team.example',$r['userPHID'],20);$b=PhorgeTaskContentSource::decodeMembership($r,'team.example',$r['userPHID'],30);$r['state']='suspended';$c=PhorgeTaskContentSource::decodeMembership($r,'team.example',$r['userPHID'],20);echo json_encode([$a,$b,$c]);")
  self.assertEqual(d,[True,False,False])
 def test_default_off_scope_store_and_service_cannot_be_enabled_by_project_flag(self):
  d=php(r"$flag=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.project-approval.enabled',true)->overrideEnvConfig('governance.project-content-grants.enabled',true);$reason=null;try{PhorgeTaskContentGrantService::assertEnabled();}catch(Throwable$e){$reason=$e->getMessage();}$s=(new ReflectionClass(PhorgeTaskContentGrantWriteScope::class))->newInstanceWithoutConstructor();$n=0;try{PhorgeTaskContentGrantStore::forScope($s);}catch(Throwable$e){$n++;}echo json_encode([$reason,$n,(new ReflectionProperty(LiskDAO::class,'connections'))->getValue(),PhorgeTaskContentGrantService::executionStatus()]);")
  self.assertEqual(d[:3],['task_content_grant_disabled',1,[]]);self.assertFalse(d[3]['can_execute']);self.assertFalse(d[3]['HTTP_implemented'])
 def test_mfa_required_web_and_unknown_transport_refuse(self):
  d=php(r"$n=0;foreach([['web',false],['api-token',true],['api-token',null],['api-token',0],['unknown',false]]as$x){try{PhorgeTaskContentGrantWriteScope::validateWriteProfile($x[0],$x[1]);}catch(Throwable$e){$n++;}}PhorgeTaskContentGrantWriteScope::validateWriteProfile('api-token',false);echo json_encode($n);")
  self.assertEqual(d,5)
 def test_completion_proof_cannot_be_fabricated_or_serialized(self):
  d=php(r"$s=(new ReflectionClass(PhorgeTaskContentGrantWriteScope::class))->newInstanceWithoutConstructor();$n=0;try{PhorgeTaskContentGrantCommitProof::newFromStore($s,50);}catch(Throwable$e){$n++;}$p=(new ReflectionClass(PhorgeTaskContentGrantCommitProof::class))->newInstanceWithoutConstructor();foreach(['serialize','clone','scope']as$m){try{if($m==='serialize'){serialize($p);}elseif($m==='clone'){$q=clone$p;}else{$p->assertForScope($s);}}catch(Throwable$e){$n++;}}echo json_encode($n);")
  self.assertEqual(d,4)
 def test_classes_load_and_private_methods_do_not_shadow_public_ancestor(self):
  d=php(r"$classes=['PhorgeTaskContentGrantStore','PhorgeTaskContentGrantService','PhorgeTaskContentGrantWriteScope','PhorgeTaskContentSource','PhorgeTaskGrantExecutionConfigSource'];$bad=[];foreach($classes as$c){$r=new ReflectionClass($c);foreach($r->getMethods()as$m){if($m->isPrivate()&&$r->getParentClass()->hasMethod($m->getName())&&!$r->getParentClass()->getMethod($m->getName())->isPrivate()){$bad[]=$c;}}}echo json_encode($bad);")
  self.assertEqual(d,[])
 def test_native_assignee_auto_capability_is_preserved(self):
  d=php(r"$u=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('a',20));$t=(new ManiphestTask())->setOwnerPHID($u->getPHID());echo json_encode([$t->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_VIEW,$u),$t->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_EDIT,$u)]);")
  self.assertEqual(d,[True,True])
 def test_task_writers_only_write_three_governance_tables(self):
  sql=(ROOT/'resources/sql/autopatches/20261112.task.contentgrants.sql').read_text();self.assertEqual(len(re.findall(r'CREATE TABLE ',sql)),3);self.assertNotRegex(sql,r'(?i)ALTER TABLE|UPDATE .*maniphest_task')
  store=(ROOT/'src/infrastructure/governance/content/PhorgeTaskContentGrantStore.php').read_text();targets=re.findall(r'(?:INSERT INTO|UPDATE) ([a-z_]+)',store);self.assertEqual(set(targets),{'governance_taskcontentgrant','governance_taskcontentapproval','governance_taskcontentaudit'})
  for name in ['Service','Store','WriteScope']:
   raw=(ROOT/f'src/infrastructure/governance/content/PhorgeTaskContentGrant{name}.php').read_text();self.assertNotIn('applyTransactions(',raw);self.assertNotIn('setOwnerPHID(',raw);self.assertNotIn('setDescription(',raw)
 def test_closed_migration_manifest_matches_actual_sql_and_schema(self):
  m=json.loads((ROOT/'src/infrastructure/governance/content/task-grant-migration-source.json').read_text());raw=(ROOT/m['sql_path']).read_bytes();self.assertEqual(hashlib.sha256(raw).hexdigest(),m['sql_sha256']);self.assertFalse(m['flags_enabled']);self.assertFalse(m['SQL_executed']);self.assertEqual(m['altered_native_Task_columns'],[])
  s=(ROOT/'src/infrastructure/governance/content/PhorgeTaskContentGrantSchemaSpec.php').read_text();self.assertEqual(set(re.findall(r"buildRawSchema\('maniphest','([^']+)'",s)),set(m['created_tables']))
class TaskMigrationAndIssuerBoundary(unittest.TestCase):
 def test_actual_native_patch_parser_exact_four_statements_and_original_semicolon_red(self):
  import ast,tempfile
  import test_media_fixture_contentgrant_migration as native_parser
  tree=ast.parse((ROOT/'tests/governance/test_media_fixture_contentgrant_migration.py').read_text())
  code=next(n.value for n in ast.walk(tree)if isinstance(n,ast.Constant)and isinstance(n.value,str)and 'final class NativePatchSQLCapture' in n.value)
  current=ROOT/'resources/sql/autopatches/20261112.task.contentgrants.sql'
  with tempfile.TemporaryDirectory()as td:
   previous=Path(td)/'original.sql';previous.write_bytes(current.read_bytes().replace(b'metadata only, no Task',b'metadata only; no Task',1))
   result=native_parser.php(code.replace('ROOT_CURRENT',json.dumps(str(current))).replace('ROOT_OLD',json.dumps(str(previous))))
  self.assertEqual(result['Lisk_connections'],[]);now,old=result['queries'];self.assertEqual(len(now),4);self.assertEqual(len(old),5)
  executable=[re.sub(r'^\s*--[^\n]*(?:\n|$)','',q).lstrip()for q in now]
  self.assertEqual([q.split()[0]for q in executable],['INSERT','CREATE','CREATE','CREATE'])
  self.assertIn('governance.task-content-grants.enabled',executable[0])
  self.assertEqual(set(re.findall(r'CREATE TABLE `?capture_only_maniphest`?\.`?([a-z_]+)', '\n'.join(executable))),{'governance_taskcontentgrant','governance_taskcontentapproval','governance_taskcontentaudit'})
 def test_actual_shared_initializer_refuses_Task_writer_before_any_query(self):
  from test_task_durable_grant_lifecycle import SETUP
  d=php(SETUP+r"$c=new TaskLifecycleCapture();$c->openTransaction();$s=lifecycleScope($c);registerLifecycle($s,$c);$before=$c->queries;$method=new ReflectionMethod(PhorgeGovernanceNativeIdentitySource::class,'initializeIssuerEpoch');$reason=null;try{$method->invoke(null,'PHID-USER-'.str_repeat('a',20),$c);}catch(Throwable$e){$reason=$e->getMessage();}$result=['reason'=>$reason,'no_added_query'=>$before===$c->queries,'no_source_insert'=>count($c->queries)===1];$s->close();echo json_encode($result);")
  self.assertEqual(d,{'reason':'task_grant_existing_issuer_epoch_required','no_added_query':True,'no_source_insert':True})
 def test_actual_prelocked_issuer_gate_accepts_existing_and_refuses_missing(self):
  d=php(r"$m=new ReflectionMethod(PhorgeTaskContentGrantWriteScope::class,'requireExistingIssuerEpoch');$ok=$m->invoke(null,['accountEpoch'=>str_repeat('a',64)])===null;$n=0;foreach([null,[],['accountEpoch'=>null],['accountEpoch'=>true],['accountEpoch'=>'0'],['accountEpoch'=>str_repeat('g',64)]]as$x){try{$m->invoke(null,$x);}catch(Throwable$e){$n++;}}echo json_encode([$ok,$n]);")
  self.assertEqual(d,[True,6])
if __name__=='__main__':unittest.main()
