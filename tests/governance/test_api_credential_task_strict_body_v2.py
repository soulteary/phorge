"""Synthetic original-body controls only; never a MAC, source-owner or SQL proof."""
import ast,copy,hashlib,importlib.util,os,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
REFERENCE=Path(os.environ.get('TASK_ADOPTER_TEST_REFERENCE_ROOT',str(ROOT))).resolve()
sys.path[:0]=[str(REFERENCE/'scripts/governance'),str(REFERENCE/'tests/governance')]
def load(path):
 spec=importlib.util.spec_from_file_location('API_'+path.stem,path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
adapter=load(ROOT/'scripts/governance/api_credential_task_strict_body_v2.py')
class APIOriginalStrictBody(unittest.TestCase):
 def setUp(self):
  old=load(REFERENCE/'tests/governance/test_api_credential_concurrency.py').CredentialConcurrencyGateTests();old.setUp()
  self.data=old.data;self.raw=(REFERENCE/'scripts/governance/check_api_credential_concurrency.py').read_bytes();self.calls=[]
  self.validator=adapter.model(self.raw,lambda data,root,operation:self.calls.append((data,root,operation)))
 def accept(self,data):return self.validator(data,'a'*40,self.data['file_service_cleanup']['binary_sha256'],REFERENCE,operation='explicit-synthetic-no-issuer')
 def test_original46_labels12_observations_and_single_restore_seam(self):
  original=copy.deepcopy(self.data);out=self.accept(self.data)
  self.assertTrue(out['accepted']);self.assertEqual(out['check_count'],46);self.assertEqual(out['native_writer_reader_attempts'],12)
  self.assertFalse(out['can_execute']);self.assertFalse(out['deployment_proved']);self.assertEqual(self.data,original)
  self.assertEqual(self.calls,[(self.data['source_restore'],REFERENCE,'explicit-synthetic-no-issuer')])
 def test_each_missing_field_label_and_observation_refused(self):
  for key in self.data:
   bad=copy.deepcopy(self.data);del bad[key]
   with self.subTest(field=key),self.assertRaises(ValueError):self.accept(bad)
  for label in self.data['checks']:
   bad=copy.deepcopy(self.data);bad['checks'].remove(label)
   with self.subTest(label=label),self.assertRaises(ValueError):self.accept(bad)
  for index in range(12):
   bad=copy.deepcopy(self.data);bad['native']['observations'].pop(index)
   with self.subTest(index=index),self.assertRaises(ValueError):self.accept(bad)
 def test_original_typed_cleanup_authority_duplicate_identity_and_revision_refuse(self):
  mutations=[]
  for field,value in [('ownedSchemasRemoved',54),('ownedRuntimeUsersRemoved',1),('readerRemoved',1)]:
   bad=copy.deepcopy(self.data);bad['cleanup'][field]=value;mutations.append(bad)
  for field,value in [('can_execute',True),('source_revision','b'*40),('attempts',True),('api_token_scan_deadlock_proven',True)]:
   bad=copy.deepcopy(self.data);bad['native'][field]=value;mutations.append(bad)
  bad=copy.deepcopy(self.data);bad['native']['observations'][-1]=copy.deepcopy(bad['native']['observations'][0]);mutations.append(bad)
  bad=copy.deepcopy(self.data);bad['checks'].append(bad['checks'][0]);mutations.append(bad)
  for bad in mutations:
   with self.assertRaises(ValueError):self.accept(bad)
 def test_changed_raw_or_noncallable_restore_refuse_before_compilation(self):
  for raw in [self.raw+b'\n',self.raw.replace(b'46',b'45',1)]:
   with self.assertRaises(ValueError):adapter.model(raw,lambda *a:None)
  with self.assertRaises(ValueError):adapter.model(self.raw,None)
 def test_consumer_keeps_MAC_runtime_binary_raw_binding_before_original_body(self):
  tree=ast.parse((ROOT/'scripts/governance/check_api_credential_task_schema_receipt_v2.py').read_bytes());fn=next(n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='accept')
  calls=[(n.lineno,ast.unparse(n.func))for n in ast.walk(fn)if isinstance(n,ast.Call)];line=lambda name:next(n for n,label in calls if label==name)
  for name in ('schema.validate_envelope','runtime_pair','checker.file_binary_digest',"full['native_exact_tree']"):
   self.assertLess(line(name),line('validate'))
  self.assertIn('source_operation_exit_verified',ast.unparse(fn));self.assertEqual(ast.unparse(fn).count("result['source_operation_exit_verified'] = True"),1)
if __name__=='__main__':unittest.main()
