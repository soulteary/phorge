import unittest,subprocess,json,copy,tempfile
from pathlib import Path
from unittest.mock import Mock,patch
import importlib.util
ROOT=Path(__file__).resolve().parents[2]
SPEC=importlib.util.spec_from_file_location('project_content_grant_qualify',ROOT/'support/governance/project-content-grants-qualification/qualify.py')
Q=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(Q)
WT=ROOT
class Tests(unittest.TestCase):
 def test_actual_native_patch_and_schema_registration_no_SQL(self):
  script="define('N05_QUALIFICATION_LIBRARY_ONLY',true);require "+json.dumps(str(Q.HERE/'native.php'))+";qload("+json.dumps(str(WT))+");echo json_encode(qdiscover());"
  r=subprocess.run(['php','-r',script],capture_output=True,text=True,timeout=30);self.assertEqual(r.returncode,0,r.stderr);d=json.loads(r.stdout);self.assertTrue(d['native_patch_discovered']);self.assertTrue(d['native_schema_spec_registered']);self.assertFalse(d['default_enabled']);self.assertFalse(d['execution_authorized']);self.assertEqual(d['patch_sha256'],'16f043e830f29b246efd12c22569e66bf0d2b629f4b9dbd062ab0f30a487d27e')
 def test_defaultOFF_gate(self):
  with self.assertRaises(ValueError):Q.bind('/no/read','/no/read','/no/read')
 def test_upgrade_is_standard_native(self):
  a=Q.upgrade_argv({'php':'/fixed/php','source_root':'/fixed/root'});self.assertEqual(a,['/fixed/php','/fixed/root/bin/storage','upgrade','--force','--no-quickstart'])
  for x in ('--apply','--init-only','--no-adjust','--password','--allow-surplus'):self.assertNotIn(x,a)
 def test_no_fixture_create_table_in_harness(self):
  s=(Q.HERE/'native.php').read_text();self.assertNotIn('CREATE TABLE',s);self.assertIn('PhabricatorSQLPatchList::buildAllPatches()',s);self.assertIn('PhorgeProjectContentGrantService::call',s);self.assertIn('hasSameColumnTypeAs',s)
 def test_native_context_is_actual_token_not_fake_source(self):
  s=(Q.HERE/'native.php').read_text();self.assertIn('newFromAuthenticatedAPIToken',s);self.assertIn('new PhutilOpaqueEnvelope',s)
  for x in ('ReflectionClass','newInstanceWithoutConstructor','overrideConfig','setIsAdmin','productionSchemaSpecSpec'):self.assertNotIn(x,s)
 def test_native_argv_contains_only_paths_and_action(self):
  j=Mock();j.i=0;j.path=Path('/private/tmp/owned');j.run.return_value=b'{"execution_authorized":false}'
  Q.native(j,{'php':'/fixed/php','source_root':'/owned/root'},Path('/owned/profile'),'approve',{'approval_id':'a'*64})
  a=j.run.call_args.args[0];self.assertNotIn('a'*64,' '.join(a));self.assertEqual(a[4],'approve');self.assertEqual(j.write.call_args.args[1],Q.canon({'approval_id':'a'*64}))
 def test_duplicate_keys_refused(self):
  with self.assertRaises(ValueError):Q.decode(b'{"a":1,"a":2}')
 def test_actual_owned_child_failure_retained(self):
  with tempfile.TemporaryDirectory()as t:
   j=Q.Journal(Path(t).resolve()/'owned')
   with self.assertRaises(ValueError):j.run(['/usr/bin/false'],t)
   self.assertTrue((j.path/'01-terminal.json').exists());self.assertFalse(any('retry' in p.name for p in j.path.iterdir()))
 def test_child_timeout_no_signal_or_retry(self):
  with tempfile.TemporaryDirectory()as t:
   j=Q.Journal(Path(t).resolve()/'owned');child=Mock();child.pid=123;child.wait.side_effect=subprocess.TimeoutExpired('mock',1)
   with patch.object(Q.subprocess,'Popen',return_value=child)as spawn:
    with self.assertRaises(subprocess.TimeoutExpired):j.run(['/mock/php'],t,timeout=1)
   child.kill.assert_not_called();child.terminate.assert_not_called();self.assertEqual(spawn.call_count,1);self.assertTrue((j.path/'01-unknown.json').exists())
 def test_grant_readback_negative_vectors(self):
  p={'target_phid':'PHID-PROJ-'+'a'*20,'subjects':{'user':'PHID-USER-'+'b'*20}}
  g=dict(grantID='c'*64,targetPHID=p['target_phid'],operation='project.description',subjectKind='user',subjectID=p['subjects']['user'],validFrom='10',validUntil='20',revokedAt=None,version='1')
  state=dict(approval_state='applied',approval_version=2,approver_present=True,applied_at_present=True,grant=g,grant_id='c'*64,audit=[dict(actorPHID='actor1',action='submitted'),dict(actorPHID='actor2',action='granted')]);Q.check_grant(state,p,'user')
  for key,value in [('targetPHID','wrong'),('operation','task.description'),('subjectKind','department'),('version','2'),('revokedAt','11')]:
   x=copy.deepcopy(state);x['grant'][key]=value
   with self.assertRaises(ValueError):Q.check_grant(x,p,'user')
 def test_orchestrator_stops_before_upgrade_on_precheck_failure(self):
  j=Mock();p={'source_root':'/owned'}
  def fake(*args,**kwargs):
   if args[3]=='discover':return dict(native_patch_discovered=True,native_schema_spec_registered=True)
   raise ValueError('actual preupgrade refused')
  with patch.object(Q,'native',side_effect=fake):
   with self.assertRaises(ValueError):Q.sequence(j,p,'profile')
  j.run.assert_not_called()
if __name__=='__main__':unittest.main()

class NativeTargetDigestTests(unittest.TestCase):
 def php(self,code):
  pre="define('N05_QUALIFICATION_LIBRARY_ONLY',true);require "+json.dumps(str(Q.HERE/'native.php'))+";qload("+json.dumps(str(WT))+");"
  r=subprocess.run(['php','-r',pre+code],capture_output=True,text=True,timeout=30);self.assertEqual(r.returncode,0,r.stderr);return json.loads(r.stdout)
 def test_actual_native_target_branch_and_customfield_storage_without_SQL(self):
  d=self.php(r'''
  $stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDefaultSource());(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,[]);
  $p=['target_phid'=>'PHID-PROJ-'.str_repeat('a',20)];$row=['id'=>'1','phid'=>$p['target_phid'],'name'=>'Pure target fixture','subtype'=>PhabricatorEditEngineSubtype::SUBTYPE_DEFAULT,'properties'=>'[]','spacePHID'=>null,'viewPolicy'=>'users','editPolicy'=>'admin','joinPolicy'=>'users'];$calls=[];$value='synthetic description';
  $read=static function($kind,$storage=null,$index=null)use(&$calls,&$value,$row,$p){$calls[]=$kind;if($kind==='project')return$row;qneed(get_class($storage)===PhabricatorProjectCustomFieldStorage::class&&strlen($index)===12);return['id'=>'9','objectPHID'=>$p['target_phid'],'fieldIndex'=>$index,'fieldValue'=>$value];};
  $a=qtarget($p,$read);$value='changed description';$b=qtarget($p,$read);$value=null;
  $missing=qtarget($p,static function($kind,$storage=null,$index=null)use($row){return$kind==='project'?$row:null;});
  echo json_encode(['no_description_column'=>!isset((new PhabricatorProject())->getSchemaColumns()['description']),'calls'=>$calls,'same_project'=>$a['project_row_sha256']===$b['project_row_sha256'],'changed_description'=>$a['native_description_source_sha256']!==$b['native_description_source_sha256'],'missing_differs'=>$missing['native_description_source_sha256']!==$a['native_description_source_sha256'],'execution'=>$a['execution_authorized']]);
  ''')
  self.assertEqual(d,dict(no_description_column=True,calls=['project','description','project','description'],same_project=True,changed_description=True,missing_differs=True,execution=False))
 def test_complete_row_digest_detects_every_previously_omitted_field(self):
  d=self.php(r'''
  $rows=['approval'=>[['approvalID'=>'a','appliedAt'=>null,'bindingJSON'=>'private binding','bindingDigest'=>'digest','approverBindingJSON'=>null,'version'=>'1','state'=>'submitted']],'grant'=>[['grantID'=>'g','revokedAt'=>null,'version'=>'1']],'audit'=>[['id'=>'1','approvalID'=>'a','grantID'=>'g','actorPHID'=>'actor','action'=>'submitted','createdAt'=>'1']]];$first=qrows_digest($rows);$n=0;
  foreach(['appliedAt','bindingJSON','bindingDigest','approverBindingJSON','version','state']as$k){$x=$rows;$x['approval'][0][$k]='mutation';qneed(qrows_digest($x)!==$first);$n++;}
  foreach(['id','approvalID','grantID','actorPHID','action','createdAt']as$k){$x=$rows;$x['audit'][0][$k]='mutation';qneed(qrows_digest($x)!==$first);$n++;}
  $x=$rows;$x['grant'][0]['revokedAt']='2';qneed(qrows_digest($x)!==$first);$n++;
  qneed(qrows_digest(['binary'=>"\xff"])!==qrows_digest(['binary'=>"\xfe"]));$n++;
  $x=$rows;$x['audit'][]=$x['audit'][0];qneed(qrows_digest($x)!==$first);$n++;
  echo json_encode(['mutations_refused'=>$n,'digest_only'=>strlen($first)===64]);
  ''');self.assertEqual(d,dict(mutations_refused=15,digest_only=True))
 def test_complete_rows_are_private_digest_not_projected_binding_values(self):
  s=(Q.HERE/'native.php').read_text();body=s[s.index('function qstate('):s.index('function qservice(')]
  self.assertIn('SELECT * FROM %T WHERE targetPHID=%s',body);self.assertIn("'complete_target_rows_sha256'=>qrows_digest($all)",body)
  result=body[body.index('$result='):];self.assertNotIn("'bindingJSON'=>",result);self.assertNotIn("'bindingDigest'=>",result);self.assertNotIn("'approverBindingJSON'=>",result)
