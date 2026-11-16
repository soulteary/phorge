"""Synthetic contract vectors only; no source-owner, MAC or SQL acceptance."""
import ast,copy,importlib.util,os,sys,types,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
REFERENCE=Path(os.environ.get('TASK_ADOPTER_TEST_REFERENCE_ROOT',str(ROOT))).resolve()
sys.path[:0]=[str(REFERENCE/'scripts/governance'),str(REFERENCE/'tests/governance')]
import check_completion as completion
import check_native_organization as native
from test_native_organization import template
spec=importlib.util.spec_from_file_location('source_facts_CURRENT_controls',ROOT/'scripts/governance/source_facts_task_strict_body_v2.py');adapter=importlib.util.module_from_spec(spec);spec.loader.exec_module(adapter)
SCOPES={'application-config-facts':'owned-native-config-source-facts-no-lease','project-source-facts':'owned-native-project-source-facts-no-lease','spaces-source-facts':'owned-native-spaces-source-facts-no-lease'}
class OriginalSourceFactsBodies(unittest.TestCase):
 def prepared(self,role,space=None):
  filename,pin,count,sql=adapter.PROFILES[role];self.calls=[]
  restore=lambda data,root,operation:self.calls.append((data,root,operation))
  reader=(lambda owner,root,path:(REFERENCE/adapter.SPACE_PATH).read_text())if space is None else space
  validate=adapter.model((REFERENCE/'scripts/governance'/filename).read_bytes(),restore,completion,types.SimpleNamespace(FIELDS=native.FIELDS,BLOCKERS=native.BLOCKERS),str(REFERENCE/'scripts/governance'/filename),role,reader if role=='spaces-source-facts'else None)
  data=template();g=validate.__globals__;data.update(batch='native-'+role,scope=SCOPES[role],checks=sorted(g['BOOTSTRAP']|g['SQL']|g['CLEANUP']),check_count=count,native=g['summary']('a'*40),file_service_cleanup=None,successful_owned_file_service_startups=0)
  data['worker_cleanup']={'private_directories_removed':0,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':0}
  return data,lambda row:validate(row,REFERENCE,source_revision='a'*40,operation='explicit-synthetic-no-issuer')
 def test_closed_three_original_counts_and_restore_seams_preserve_all_claims(self):
  self.assertEqual(set(adapter.PROFILES),set(SCOPES));self.assertEqual({k:v[2:]for k,v in adapter.PROFILES.items()},{'application-config-facts':(19,10),'project-source-facts':(11,2),'spaces-source-facts':(20,11)})
  for role in SCOPES:
   with self.subTest(role=role):
    data,validate=self.prepared(role);original=copy.deepcopy(data);out=validate(data)
    self.assertTrue(out['passed']);self.assertEqual(out['checks'],adapter.PROFILES[role][2]);self.assertEqual(out['SQL_obligations'],adapter.PROFILES[role][3]);self.assertFalse(out['complete']);self.assertFalse(out['all_sources_complete']);self.assertFalse(out['restoration_authorized']);self.assertEqual(data,original)
    self.assertEqual(self.calls,[(data['source_restore'],REFERENCE,'explicit-synthetic-no-issuer')])
 def test_all_missing_fields_and_every_missing_label_refuse_for_each_family(self):
  for role in SCOPES:
   data,validate=self.prepared(role)
   for key in data:
    bad=copy.deepcopy(data);del bad[key]
    with self.subTest(role=role,field=key),self.assertRaises(ValueError):validate(bad)
   for label in data['checks']:
    bad=copy.deepcopy(data);bad['checks'].remove(label)
    with self.subTest(role=role,label=label),self.assertRaises(ValueError):validate(bad)
 def test_typed_cleanup_revision_flags_cache_lease_and_duplicates_refuse(self):
  for role in SCOPES:
   data,validate=self.prepared(role);cases=[]
   for field,value in [('ownedSchemasRemoved',54),('ownedRuntimeUsersRemoved',1),('readerRemoved',1)]:
    bad=copy.deepcopy(data);bad['cleanup'][field]=value;cases.append(bad)
   for field in data['native']:
    if data['native'][field]is False:
     bad=copy.deepcopy(data);bad['native'][field]=True;cases.append(bad)
   bad=copy.deepcopy(data);bad['native']['source_revision']='b'*40;cases.append(bad)
   bad=copy.deepcopy(data);bad['checks'].append(bad['checks'][0]);cases.append(bad)
   bad=copy.deepcopy(data);bad['production_optional_features_enabled']=True;cases.append(bad)
   for bad in cases:
    with self.subTest(role=role),self.assertRaises(ValueError):validate(bad)
 def test_Space_actual_source_label_reader_not_a_supplied_success_set(self):
  raw=(REFERENCE/adapter.SPACE_PATH).read_text();calls=[]
  def read(owner,root,path):calls.append((owner,root,path));return raw
  data,validate=self.prepared('spaces-source-facts',read);self.assertTrue(validate(data)['passed'])
  self.assertEqual(calls,[('explicit-synthetic-no-issuer',REFERENCE,REFERENCE/adapter.SPACE_PATH)])
  for text in [raw.replace('recovery-spaces-real-nonadmin-native-login-before-guard','recovery-spaces-unknown-label'),'',raw+"\n'"+'recovery-spaces-extra' +"');\n"]:
   data,validate=self.prepared('spaces-source-facts',lambda *args:text)
   with self.assertRaises(ValueError):validate(data)
 def test_raw_unknown_role_bad_facade_and_missing_Space_reader_refuse(self):
  for role,(name,pin,count,sql)in adapter.PROFILES.items():
   raw=(REFERENCE/'scripts/governance'/name).read_bytes();args=[lambda *a:None,completion,types.SimpleNamespace(FIELDS=native.FIELDS,BLOCKERS=native.BLOCKERS),str(REFERENCE/'scripts/governance'/name),role]
   with self.assertRaises(ValueError):adapter.model(raw+b'\n',*args)
   bad=args.copy();bad[2]=types.SimpleNamespace(FIELDS=native.FIELDS,BLOCKERS=native.BLOCKERS,authority=True)
   with self.assertRaises(ValueError):adapter.model(raw,*bad)
  with self.assertRaises(ValueError):adapter.model(b'',lambda:None,completion,types.SimpleNamespace(),'unused','unknown')
 def test_wrapper_requires_authentication_runtime_raw_binding_before_original_body(self):
  tree=ast.parse((ROOT/'scripts/governance/check_source_facts_task_schema_receipt_v2.py').read_bytes());fn=next(n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='accept');calls=[(n.lineno,ast.unparse(n.func))for n in ast.walk(fn)if isinstance(n,ast.Call)];line=lambda name:next(n for n,label in calls if label==name)
  for name in ['schema.validate_envelope','runtime_pair',"full['native_exact_tree']",'adapter.model']:self.assertLess(line(name),line('validate'))
  self.assertIn('context.current(adapter.SPACE_PATH)',ast.unparse(fn));self.assertIn('adapter.SPACE_SHA',ast.unparse(fn))
if __name__=='__main__':unittest.main()
