"""Exact full-native two-capture cleanup projection, never runtime proof."""
import ast,copy,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_full_native_task_schema_receipt_v2 as gate
import check_native_organization as original
class FullNativeCaptureCleanup(unittest.TestCase):
 def setUp(self):
  self.expected={'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True}
  self.data={'cleanup':dict(self.expected,ownedAuxiliarySetupConnectionsClosed=2),'other':{'retained':True}}
  self.delta={'native_profile_mode':'full-native-Task-OFF','genuine_bound_capture_pair_verified':True}
 def test_exact_two_capture_projection_keeps_raw_and_every_original_field(self):
  before=copy.deepcopy(self.data);out=gate.project_capture_cleanup(self.data,self.delta)
  self.assertEqual(self.data,before);self.assertEqual(out['cleanup'],self.expected)
  self.assertEqual(out['other'],before['other']);original.exact_tree(out['cleanup'],self.expected)
  with self.assertRaises(ValueError):gate.project_capture_cleanup(out,self.delta)
 def test_unknown_missing_extra_count_type_or_wrong_role_refuses(self):
  for count in [None,True,False,0,1,3,'2',2.0]:
   with self.subTest(count=count),self.assertRaises(ValueError):
    gate.project_capture_cleanup({'cleanup':dict(self.data['cleanup'],ownedAuxiliarySetupConnectionsClosed=count)},self.delta)
  for cleanup in [None,{},dict(self.data['cleanup'],unknown=False),dict(self.expected)]:
   with self.subTest(cleanup=cleanup),self.assertRaises(ValueError):gate.project_capture_cleanup({'cleanup':cleanup},self.delta)
  for delta in [None,{},dict(self.delta,native_profile_mode='conduit-Task-OFF'),dict(self.delta,genuine_bound_capture_pair_verified=False),dict(self.delta,genuine_bound_capture_pair_verified=1)]:
   with self.subTest(delta=delta),self.assertRaises(ValueError):gate.project_capture_cleanup(self.data,delta)
 def test_original_cleanup_corruption_is_never_repaired_or_hidden(self):
  for key,value in [('ownedSchemasRemoved',54),('ownedRuntimeUsersRemoved',1),('readerRemoved',False)]:
   with self.subTest(key=key):
    data=copy.deepcopy(self.data);data['cleanup'][key]=value;out=gate.project_capture_cleanup(data,self.delta)
    self.assertEqual(out['cleanup'][key],value)
    with self.assertRaises(ValueError):original.exact_tree(out['cleanup'],self.expected)
 def test_actual_accept_keeps_authentication_raw_binding_and_strict_body_order(self):
  tree=ast.parse((ROOT/'scripts/governance/check_full_native_task_schema_receipt_v2.py').read_bytes())
  function=next(n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='accept')
  calls=[(n.lineno,ast.unparse(n.func))for n in ast.walk(function)if isinstance(n,ast.Call)]
  line=lambda name:next(n for n,label in calls if label==name)
  self.assertLess(line('schema.validate_envelope'),line('project_capture_cleanup'))
  self.assertLess(line('runtime_pair'),line('project_capture_cleanup'))
  self.assertLess(line("model['native_exact_tree']"),line('project_capture_cleanup'))
  self.assertLess(line('project_capture_cleanup'),line("model['native_validate']"))
if __name__=='__main__':unittest.main()
