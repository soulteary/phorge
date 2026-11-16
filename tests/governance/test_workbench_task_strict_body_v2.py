"""Synthetic/static contract controls only; no SQL, source-owner or runtime proof."""
import ast,copy,hashlib,importlib.util,os,sys,types,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
REFERENCE=Path(os.environ.get('TASK_ADOPTER_TEST_REFERENCE_ROOT',str(ROOT))).resolve()
sys.path[:0]=[str(REFERENCE/'scripts/governance'),str(REFERENCE/'tests/governance')]
import check_completion as completion
import check_native_organization as native
from test_native_organization import template
spec=importlib.util.spec_from_file_location('workbench_current_contract',ROOT/'scripts/governance/workbench_task_strict_body_v2.py');adapter=importlib.util.module_from_spec(spec);spec.loader.exec_module(adapter)
class WorkbenchCurrentContracts(unittest.TestCase):
 def prepared(self,reader=None):
  self.restores=[];self.reads=[]
  def read(owner,root,path):
   self.reads.append((owner,root,path));return (REFERENCE/adapter.SOURCE).read_text()
  model=adapter.model((REFERENCE/adapter.CHECKER).read_bytes(),lambda data,root,operation:self.restores.append((data,root,operation)),completion,types.SimpleNamespace(FIELDS=native.FIELDS,BLOCKERS=native.BLOCKERS),str(REFERENCE/adapter.CHECKER),read if reader is None else reader)
  validate=model['validate'];g=validate.__globals__;data=template()
  data.update(batch='native-organization-approval-workbench',scope='owned-native-organization-approval-workbench',checks=sorted(g['BOOTSTRAP']|model['obligations'](REFERENCE,operation='explicit-synthetic-no-issuer')|g['CLEANUP']),check_count=26,native=g['summary']('a'*40),successful_owned_file_service_startups=0,file_service_cleanup=None)
  data['worker_cleanup']={'private_directories_removed':0,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':0}
  self.reads.clear()
  return data,lambda row:validate(row,REFERENCE,source_revision='a'*40,operation='explicit-synthetic-no-issuer')
 def test_positive_original26_claims_restore_and_read_identity(self):
  data,validate=self.prepared();old=copy.deepcopy(data);result=validate(data)
  self.assertTrue(result['passed']);self.assertEqual(result['checks'],26);self.assertFalse(result['HTTP_server_or_browser_executed']);self.assertFalse(result['full_approval_family_complete']);self.assertFalse(result['all_sources_complete']);self.assertFalse(result['restoration_authorized']);self.assertEqual(data,old)
  self.assertEqual(self.restores,[(data['source_restore'],REFERENCE,'explicit-synthetic-no-issuer')]);self.assertEqual(self.reads,[('explicit-synthetic-no-issuer',REFERENCE,REFERENCE/adapter.SOURCE)])
 def test_each_original_field_and_label_still_required(self):
  data,validate=self.prepared()
  for key in data:
   bad=copy.deepcopy(data);del bad[key]
   with self.subTest(field=key),self.assertRaises((ValueError,KeyError)):validate(bad)
  for label in data['checks']:
   bad=copy.deepcopy(data);bad['checks'].remove(label)
   with self.subTest(label=label),self.assertRaises(ValueError):validate(bad)
 def test_cleanup_worker_revision_claims_and_counts_refuse(self):
  data,validate=self.prepared();cases=[]
  for key,value in [('ownedSchemasRemoved',54),('ownedRuntimeUsersRemoved',True),('readerRemoved',1),('ownedAuxiliarySetupConnectionsClosed',2)]:
   bad=copy.deepcopy(data);bad['cleanup'][key]=value;cases.append(bad)
  for key,value in [('check_count',27),('successful_owned_file_service_startups',1),('production_optional_features_enabled',True),('native_permission_axes_changed',True)]:
   bad=copy.deepcopy(data);bad[key]=value;cases.append(bad)
  for key in data['native']:
   bad=copy.deepcopy(data);bad['native'][key]='b'*40 if key=='source_revision'else not bad['native'][key];cases.append(bad)
  bad=copy.deepcopy(data);bad['worker_cleanup']['workers_registered']=1;cases.append(bad)
  for bad in cases:
   with self.assertRaises(ValueError):validate(bad)
 def test_checker_pin_facade_and_missing_operation_refuse(self):
  raw=(REFERENCE/adapter.CHECKER).read_bytes();facade=types.SimpleNamespace(FIELDS=native.FIELDS,BLOCKERS=native.BLOCKERS)
  for changed in (raw+b'\n',raw.replace(b'check_count',b'check_count_changed',1)):
   with self.assertRaises(ValueError):adapter.model(changed,lambda *a:None,completion,facade,'unused',lambda *a:'')
  with self.assertRaises(ValueError):adapter.model(raw,lambda *a:None,completion,types.SimpleNamespace(FIELDS=native.FIELDS,BLOCKERS=native.BLOCKERS,authority=True),'unused',lambda *a:'')
  model=adapter.model(raw,lambda *a:None,completion,facade,'unused',lambda *a:'')
  with self.assertRaises(TypeError):model['validate']({})
 def test_actual_consumer_authenticates_before_original_body(self):
  tree=ast.parse((ROOT/'scripts/governance/check_workbench_task_schema_receipt_v2.py').read_bytes());fn=next(n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='accept');calls=[(n.lineno,ast.unparse(n.func))for n in ast.walk(fn)if isinstance(n,ast.Call)];line=lambda name:next(n for n,label in calls if label==name)
  for name in ['schema.validate_envelope','runtime_pair',"full['native_exact_tree']",'adapter.model']:self.assertLess(line(name),line("model['validate']"))
  text=ast.unparse(fn);self.assertIn('source.require_task_operation(owner, base)',text);self.assertIn('owner is operation',text);self.assertIn('context.current(adapter.SOURCE)',text);self.assertIn('adapter.SOURCE_SHA',text)
 def test_original_obligation_drift_is_not_normalized(self):
  data,validate=self.prepared()
  for text in ('',"'approval-workbench-only');"):
   with self.assertRaises(ValueError):self.prepared(lambda *a:text)
if __name__=='__main__':unittest.main()
