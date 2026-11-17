"""Pinned AST/typed DATA controls only. No SQL/browser/source-owner qualification."""
import ast,copy,hashlib,importlib.util,os,sys,types,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2];REFERENCE=Path(os.environ.get('N11_TEST_SOURCE_ROOT',str(ROOT))).resolve();sys.path.insert(0,str(REFERENCE/'scripts/governance'))
def load(path):
 s=importlib.util.spec_from_file_location('N11_source_DATA_'+path.stem,path);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
adapter=load(ROOT/'scripts/governance/n11_task_source_current_v2.py')
class Controls(unittest.TestCase):
 def test_exact_source_transform_inverts_and_keeps_prepared_FD_bodies_out(self):
  raw=(REFERENCE/adapter.TASK).read_bytes();tree=adapter.source_functions(raw)
  self.assertEqual({n.name for n in tree.body},{'_native_and_source_cli_gates','_protected_source_witness','admitted_source'})
  original=ast.parse(raw);untouched={'prepared_context','_protected_capture','_protected_leaf'}
  self.assertTrue(untouched<={n.name for n in original.body if isinstance(n,ast.FunctionDef)})
  text=ast.unparse(tree);self.assertIn('current_source_task_v2',text);self.assertIn('check_directory_transports_task_source_v2.py',text);self.assertIn('open_task_source_operation',text);self.assertIn('expected_checkpoint=_N11_CHECKPOINT',text);self.assertIn('timeout=10',text)
 def test_source_pin_and_parent_pin_changes_refuse(self):
  for path,method in [(adapter.TASK,adapter.source_functions),(adapter.PARENT,adapter.parent_functions)]:
   for raw in [(REFERENCE/path).read_bytes()+b'\n',b'pass\n']:
    with self.assertRaises(ValueError):method(raw)
 def test_parent_run_and_finalize_body_and_globals_binding_are_exact(self):
  raw=(REFERENCE/adapter.PARENT).read_bytes();tree=adapter.parent_functions(raw);original={n.name:n for n in ast.parse(raw).body if isinstance(n,ast.FunctionDef)}
  for n in tree.body:self.assertEqual(adapter.dump(n),adapter.dump(original[n.name]))
  sentinel=object();admitted=lambda c:None;namespace={'admitted_source':admitted,'_run_task_with_source_operation':sentinel}
  exec(compile(tree,'CURRENT-parent-DATA','exec'),namespace)
  self.assertIs(namespace['run_task'].__globals__,namespace);self.assertIs(namespace['main'].__globals__,namespace);self.assertIs(namespace['run_task'].__globals__['admitted_source'],admitted);self.assertIs(namespace['main'].__globals__['admitted_source'],admitted);self.assertIs(namespace['_run_task_with_source_operation'],sentinel)
  main=next(n for n in tree.body if n.name=='main');self.assertEqual(sum(isinstance(n,ast.Call)and isinstance(n.func,ast.Name)and n.func.id=='admitted_source'for n in ast.walk(main)),1)
 def valid_receipt(self):
  return {'schema':'native-directory-transports-Task-source-v2','source_only_passed':True,'exact_current_checkpoint':'a'*40,'current_signed_leaf_count':9000,'original_manifest_sha256':adapter.MANIFEST_SHA,'original76_pins_verified_against_exact_parent_projection':True,'complete_src_resources_parent_tree_verified':True,'parent_git_tree_inventory_sha256':'b'*64,'CURRENT_typed_SourceOperation_issued':True,'source_operation_exit_verified':True,'original_v1_CLI_or_76_test_suite_executed':False,'runtime_SQL_or_business_qualified':False,'SQL_executed':False,'old_manifest_or_pins_modified':False}
 def test_actual_v2_schema_every_field_is_mandatory(self):
  value=self.valid_receipt();self.assertTrue(adapter.directory_receipt(value,'a'*40))
  for key in value:
   bad=copy.deepcopy(value);del bad[key]
   with self.subTest(missing=key),self.assertRaises(ValueError):adapter.directory_receipt(bad,'a'*40)
  for key in value:
   bad=copy.deepcopy(value);bad[key]=None
   with self.subTest(changed=key),self.assertRaises(ValueError):adapter.directory_receipt(bad,'a'*40)
 def test_wrong_checkpoint_old_schema_extra_and_bool_count_refuse(self):
  value=self.valid_receipt()
  for bad in [dict(value,schema='native-directory-transports-source-only-v1'),dict(value,extra=True),dict(value,current_signed_leaf_count=True),dict(value,source_operation_exit_verified=False)]:
   with self.assertRaises(ValueError):adapter.directory_receipt(bad,'a'*40)
  with self.assertRaises(ValueError):adapter.directory_receipt(value,'c'*40)
 def test_duplicate_and_nonfinite_child_JSON_refuse(self):
  for raw in [b'{"x":1,"x":2}',b'{"x":NaN}',b'{"x":Infinity}']:
   with self.assertRaises(ValueError):adapter.strict_json(raw)
 def test_new_dependency_closure_is_exact_and_disjoint_old_parent_paths(self):
  self.assertEqual(len(adapter.DEPENDENCIES),4);self.assertIn('scripts/governance/run_n11_task_current_v2.py',adapter.DEPENDENCIES);self.assertNotIn(adapter.TASK,adapter.DEPENDENCIES)
 def test_external_candidate_never_opens_owner_by_root_alias(self):
  with self.assertRaises(ValueError):adapter.modules(REFERENCE/'foreign-root')
 def test_typed_equality_rejects_boolean_integer_and_missing_extra_roles(self):
  original={'runtime_qualified':False,'files':{'known':{'bytes':1}}}
  self.assertTrue(adapter.exact(copy.deepcopy(original),original))
  for bad in [{'runtime_qualified':0,'files':{'known':{'bytes':1}}},{'runtime_qualified':False,'files':{'known':{'bytes':True}}},{'runtime_qualified':False,'files':{}},{'runtime_qualified':False,'files':{'known':{'bytes':1},'unknown':{}}}]:
   with self.assertRaises(ValueError):adapter.exact(bad,original)
 def test_sidecar_wiring_refuses_false_integer_and_unknown_roles(self):
  from unittest.mock import patch
  expected={'schema':'N11-CURRENT-adapter-dependencies-v2','source_root':'DATA-only','source_revision':'a'*40,'source_tree':'b'*40,'original_task_manifest_sha256':'c'*64,'files':{},'runtime_qualified':False}
  with patch.object(adapter,'sidecar',return_value=expected):
   self.assertEqual(adapter.require_sidecar(copy.deepcopy(expected),'unused','unused',{}),expected)
   for bad in [dict(expected,runtime_qualified=0),dict(expected,runtime_qualified=None),dict(expected,runtime_qualified=True),dict(expected,files={'unknown':{}})]:
    with self.assertRaises(ValueError):adapter.require_sidecar(bad,'unused','unused',{})
 def test_private_module_function_mutation_refused_without_global_replacement(self):
  module=types.ModuleType('N11_CURRENT_DATA_only');module.__file__=str(REFERENCE/adapter.TASK);raw=(REFERENCE/adapter.TASK).read_bytes();exec(compile(raw,module.__file__,'exec'),module.__dict__)
  self.assertTrue(adapter.current_functions(module,raw,REFERENCE/adapter.TASK));module.prepared_context=lambda *args:None
  with self.assertRaises(ValueError):adapter.current_functions(module,raw,REFERENCE/adapter.TASK)
 def graph_DATA(self):
  # Guard is patched only for deterministic graph DATA comparisons below.
  # These synthetic dictionaries are never accepted as source owners.
  pins={f'p/{i}':{'kind':'file','mode':0o644}for i in range(8419)};base={n:('file',b'BASE',0o644)for n in pins};entries={**pins,'new/task.php':{'kind':'file','mode':0o644}}
  overlays={f'overlay/{i}':adapter.digest(b'overlay')for i in range(8)}
  context=types.SimpleNamespace(parent_pins=pins,entries=entries,current=lambda n:b'overlay'if n.startswith('overlay/')else b'CURRENT')
  operation=types.SimpleNamespace(_task_expected_checkpoint='a'*40,_task_parent_bytes=lambda p:b'BASE')
  snapshot=types.SimpleNamespace(BASE=adapter.BASE,ROOT_TREE=adapter.BASE_TREE,OVERLAY=overlays,base_entries=lambda root:(base,{}))
  current={n:('file',b'CURRENT',0o644)for n in entries}
  freshness=types.SimpleNamespace(protected=lambda n:n.startswith('p/')or n=='new/task.php',head_entries=lambda root,revision:current)
  return operation,context,snapshot,freshness,base,current
 def test_parent_BASE_and_physical_CURRENT_are_separate_DATA_views(self):
  from unittest.mock import patch
  operation,context,snapshot,freshness,base,current=self.graph_DATA()
  with patch.object(adapter,'_guard',return_value=context):
   self.assertTrue(adapter.verify_parent_base(operation,REFERENCE,snapshot,freshness));self.assertEqual(adapter.current_expected(operation,REFERENCE,'a'*40,snapshot,freshness,base),current)
  self.assertNotEqual(current['p/0'],base['p/0']);self.assertIn('new/task.php',current);self.assertNotIn('new/task.php',base)
 def test_parent_BASE_overlay_and_current_graph_mutations_refuse_DATA(self):
  from unittest.mock import patch
  for kind in('base','tree','overlay-count','overlay-pin','parent-content','current-wire','current-mode','current-extra'):
   operation,context,snapshot,freshness,base,current=self.graph_DATA()
   if kind=='base':snapshot.BASE='0'*40
   elif kind=='tree':snapshot.ROOT_TREE='0'*40
   elif kind=='overlay-count':snapshot.OVERLAY['unknown']='0'*64
   elif kind=='overlay-pin':snapshot.OVERLAY['overlay/0']='0'*64
   elif kind=='parent-content':operation._task_parent_bytes=lambda path:b'WRONG'
   elif kind=='current-wire':current['p/0']=('file',b'WRONG',0o644)
   elif kind=='current-mode':current['p/0']=('file',b'CURRENT',0o755)
   elif kind=='current-extra':current['p/unknown']=('file',b'CURRENT',0o644)
   with patch.object(adapter,'_guard',return_value=context),self.subTest(kind=kind),self.assertRaises(ValueError):adapter.current_expected(operation,REFERENCE,'a'*40,snapshot,freshness,base)
 def test_no_historical_source_execution_or_public_module_replacement(self):
  raw=(ROOT/'scripts/governance/n11_task_source_current_v2.py').read_text();tree=ast.parse(raw)
  for n in ast.walk(tree):
   if isinstance(n,(ast.Assign,ast.AugAssign)):
    targets=n.targets if isinstance(n,ast.Assign)else[n.target]
    self.assertFalse(any(isinstance(t,ast.Subscript)and ast.unparse(t.value)=='sys.modules'for t in targets))
  self.assertNotIn("git','show'",raw);self.assertNotIn('test_api',raw);self.assertIn('operation._task_parent_bytes',raw)
if __name__=='__main__':unittest.main()
