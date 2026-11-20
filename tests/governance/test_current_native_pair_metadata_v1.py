"""CURRENT metadata/AST and owned-filesystem controls; no source/SQL grant."""
import ast,base64,zlib,copy,hashlib,importlib.util,json,os,pathlib,stat,subprocess,sys,tempfile,types,unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
REFERENCE=pathlib.Path(os.environ.get('PHORGE_PAIR_TEST_SOURCE_ROOT',str(ROOT))).resolve()
sys.path.insert(0,str(REFERENCE/'scripts/governance'))
def module(name):
 path=ROOT/'scripts/governance'/name;s=importlib.util.spec_from_file_location('DATA_'+path.stem,path);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
class MetadataControls(unittest.TestCase):
 def setUp(self):self.helper=module('current_native_pair_metadata_v1.py');self.raw=(REFERENCE/'scripts/governance/check_native_organization.py').read_bytes()
 def test_exact_current_inverse_and_closed_refusals(self):
  h=self.helper;prior=h.parent_DATA(self.raw,h.PARENT_SHA);self.assertEqual(hashlib.sha256(prior).hexdigest(),h.PARENT_SHA)
  for raw,pin in [(prior,h.PARENT_SHA),(self.raw+b'\n',h.PARENT_SHA),(b'!'+self.raw[1:],h.PARENT_SHA),(self.raw,'0'*64),(self.raw.decode(),h.PARENT_SHA)]:
   with self.subTest(pin=pin),self.assertRaises(ValueError):h.parent_DATA(raw,pin)
  self.assertEqual(h.current_DATA(h.parent_DATA(self.raw,h.PARENT_SHA)),self.raw)
 def test_all_selected_native_bodies_and_constants_are_unchanged(self):
  old=ast.parse(self.helper.parent_DATA(self.raw,self.helper.PARENT_SHA));current=ast.parse(self.raw)
  for name in ['exact_tree','validate_restore']:
   a=next(n for n in old.body if isinstance(n,ast.FunctionDef)and n.name==name);b=next(n for n in current.body if isinstance(n,ast.FunctionDef)and n.name==name);self.assertEqual(ast.dump(a,include_attributes=False),ast.dump(b,include_attributes=False))
  old_validate=copy.deepcopy(next(n for n in old.body if isinstance(n,ast.FunctionDef)and n.name=='validate'))
  retired_binding=[n for n in old_validate.body if isinstance(n,ast.Assign)and ast.unparse(n.targets[0])=="expected_wiki['render_cleanup']['binary_sha256']"]
  self.assertEqual(len(retired_binding),1)
  old_validate.body.remove(retired_binding[0])
  current_validate=next(n for n in current.body if isinstance(n,ast.FunctionDef)and n.name=='validate')
  self.assertEqual(ast.dump(old_validate,include_attributes=False),ast.dump(current_validate,include_attributes=False))
  for name in ['FIELDS','BLOCKERS']:
   def value(tree):return next(ast.dump(n.value,include_attributes=False)for n in tree.body if isinstance(n,ast.Assign)and any(isinstance(x,ast.Name)and x.id==name for x in n.targets))
   self.assertEqual(value(old),value(current))
 def test_six_ast_adapters_keep_old_pins_and_parse_CURRENT(self):
  common=module('oauth_task_strict_body_v2.py');native='check_native_organization'
  raws={n:(REFERENCE/'scripts/governance'/ (n+'.py')).read_bytes()for n in common.PINS}
  trees=common.trees(raws)
  for role in common.ROLES:self.assertTrue(callable(common.model(raws,role)['validate']))
  self.assertIn('retired-runtime-removed',ast.unparse(trees[native]));self.assertEqual(common.PINS[native],self.helper.PARENT_SHA)
  for file in ['oauth_code_task_strict_body_v2.py','oauth_access_task_strict_body_v2.py','assessment_task_strict_body_v2.py','conduit_task_strict_body_v2.py']:
   adapter=module(file);self.assertEqual(adapter.PINS[native],self.helper.PARENT_SHA);rows={n:(REFERENCE/'scripts/governance'/(n+'.py')).read_bytes()for n in adapter.PINS}
   result=adapter.model(rows,common)if file.startswith('oauth_code_')else adapter.model(rows,common,types.SimpleNamespace(ROOT=REFERENCE))
   self.assertIs(result['source_or_SQL_qualified'],False);self.assertTrue(callable(result['validate']))
   rows[native]=self.helper.parent_DATA(self.raw,self.helper.PARENT_SHA)
   with self.assertRaises(ValueError):adapter.model(rows,common)if file.startswith('oauth_code_')else adapter.model(rows,common,types.SimpleNamespace(ROOT=REFERENCE))
  full=module('full_native_task_strict_body_v2.py');tree,_=full.native_tree(self.raw);self.assertIn('retired-runtime-removed',ast.unparse(tree));self.assertEqual(full.PINS['scripts/governance/check_native_organization.py'],self.helper.PARENT_SHA)
  with self.assertRaises(ValueError):full.native_tree(self.helper.parent_DATA(self.raw,self.helper.PARENT_SHA))
 def test_shared_loader_rejects_tampered_mode_and_symlink(self):
  common=module('oauth_task_strict_body_v2.py');tree=ast.parse((ROOT/'scripts/governance/oauth_task_strict_body_v2.py').read_bytes());function=next(n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='native_pair_parent_DATA')
  with tempfile.TemporaryDirectory()as temporary:
   folder=pathlib.Path(temporary);caller=folder/'common.py';caller.write_text('#DATA');helper=folder/'current_native_pair_metadata_v1.py';body=(ROOT/'scripts/governance/current_native_pair_metadata_v1.py').read_bytes();helper.write_bytes(body);helper.chmod(0o644)
   ns={'__file__':str(caller),'Path':pathlib.Path,'os':os,'stat':stat,'hashlib':hashlib};exec(compile(ast.fix_missing_locations(ast.Module(body=[function],type_ignores=[])),str(caller),'exec'),ns);run=ns['native_pair_parent_DATA']
   self.assertEqual(hashlib.sha256(run(self.raw,self.helper.PARENT_SHA)).hexdigest(),self.helper.PARENT_SHA)
   helper.write_bytes(body+b'\n')
   with self.assertRaises(ValueError):run(self.raw,self.helper.PARENT_SHA)
   helper.write_bytes(body);helper.chmod(0o600)
   with self.assertRaises(ValueError):run(self.raw,self.helper.PARENT_SHA)
   helper.unlink();helper.symlink_to(ROOT/'scripts/governance/current_native_pair_metadata_v1.py')
   with self.assertRaises(ValueError):run(self.raw,self.helper.PARENT_SHA)
 def test_description_current_metadata_inverse_preserves_old_chain(self):
  path=ROOT/'scripts/governance/project_description_admission_delta.py';tree=ast.parse(path.read_bytes())
  # Immutable pre-continuation syntax is DATA, never imported or executed.
  prior=subprocess.check_output(['/usr/bin/git','--no-replace-objects','-C',str(REFERENCE),'show','b37a73ac5ce7a83c396182fdea4f1f962f6263e2:scripts/governance/project_description_admission_delta.py'],timeout=10)
  self.assertEqual(len(prior),540144);self.assertEqual(hashlib.sha256(prior).hexdigest(),'8385588a2cfd4f395a4f4ac6e79319bb6901100765452c311346eabbdc20ba7a');original=ast.parse(prior)
  def literal(name):return next(ast.literal_eval(n.value)for n in tree.body if isinstance(n,ast.Assign)and any(isinstance(x,ast.Name)and x.id==name for x in n.targets))
  functions=[copy.deepcopy(n)for n in tree.body if isinstance(n,ast.FunctionDef)and n.name in ['_constants','_exact','_target_bytes','_normalize_metadata','native_pair_parent_DATA','_admitted_bytes']]
  native='scripts/governance/check_native_organization.py';ns={'__file__':str(path),'Path':pathlib.Path,'os':os,'stat':stat,'hashlib':hashlib,'sha':lambda raw:hashlib.sha256(raw).hexdigest(),'json':json,'zlib':zlib,'base64':base64,'CURRENT_METADATA':literal('CURRENT_METADATA'),'METADATA_CONSTANTS_SHA256':literal('METADATA_CONSTANTS_SHA256'),'MANIFEST':literal('MANIFEST'),'legacy':types.SimpleNamespace(HISTORICAL_PINS={})}
  ns['need']=lambda value:None if value else (_ for _ in ()).throw(ValueError('DATA_refused'))
  exec(compile(ast.fix_missing_locations(ast.Module(body=functions,type_ignores=[])),str(path),'exec'),ns)
  expected=ns['_target_bytes'](native);self.assertEqual(ns['_normalize_metadata'](native,self.raw),expected)
  prior=self.helper.parent_DATA(self.raw,self.helper.PARENT_SHA);self.assertEqual(ns['_normalize_metadata'](native,prior),expected);self.assertEqual(ns['_normalize_metadata'](native,expected),expected)
  with self.assertRaises(ValueError):ns['_normalize_metadata'](native,self.raw+b'\n')
  ns['description']=types.SimpleNamespace(checked_bytes=lambda path:self.raw);self.assertEqual(ns['_admitted_bytes'](None,REFERENCE,REFERENCE/native),prior)
  # Existing public fixture DATA is still checked by the unchanged old recipe.
  ns['description']=types.SimpleNamespace(checked_bytes=lambda path:prior);self.assertEqual(ns['_admitted_bytes'](None,REFERENCE,REFERENCE/native),prior)
  unknown=self.raw+b'\n';ns['description']=types.SimpleNamespace(checked_bytes=lambda path:unknown)
  with self.assertRaises(ValueError):ns['_exact'](ns['_admitted_bytes'](None,REFERENCE,REFERENCE/native),ns['CURRENT_METADATA'][native])
  oldfn=next(n for n in original.body if isinstance(n,ast.FunctionDef)and n.name=='_normalize_metadata');newfn=next(copy.deepcopy(n)for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='_normalize_metadata')
  additions=[n for n in newfn.body if isinstance(n,ast.If)and '5e12dada7162f8c7d696366b3307fa6e0f9fc4a5886f96b4875adc1123f4d853'in ast.unparse(n.test)];self.assertEqual(len(additions),1);newfn.body.remove(additions[0]);self.assertEqual(ast.dump(newfn,include_attributes=False),ast.dump(oldfn,include_attributes=False))
 def test_genuine_public_fixture_positive_and_metadata_tamper(self):
  self.assertEqual(REFERENCE,ROOT,'genuine fixture replay requires the complete signed CURRENT checkout')
  from task_inverse_test_api_v2 import owner
  from project_grant_source_task_test_api_v2 import GrantSourceDATA
  with owner(ROOT)as operation,tempfile.TemporaryDirectory()as temporary:
   data=GrantSourceDATA(operation,ROOT);fixture=pathlib.Path(temporary).resolve();data.complete_fixture_DATA(fixture)
   data.assert_current_recipe(fixture);self.assertEqual(len(data.assert_production_cohort(fixture)),19)
   collector=fixture/'scripts/governance/check_native_organization.py';original=collector.read_bytes()
   self.assertEqual(hashlib.sha256(original).hexdigest(),self.helper.PARENT_SHA)
   collector.write_bytes(original+b'\n')
   with self.assertRaises(ValueError):data.assert_current_recipe(fixture)
if __name__=='__main__':unittest.main()
