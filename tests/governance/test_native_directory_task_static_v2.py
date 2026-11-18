"""Pure Task static-inventory boundaries; no actual source grant or SQL."""
import ast,copy,hashlib,subprocess,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_native_directory_task_source_v2 as gate
class TaskStaticInventory(unittest.TestCase):
 def test_current_gate_closed_seams_keep_current_semantic_reads(self):
  raw=(ROOT/gate.GATE).read_bytes();tree,seams=gate.current_tree(raw)
  self.assertEqual(seams,{'metadata_read_seams':2,'runtime_inventory_seams':1})
  method=lambda t:next(n for n in t.body if isinstance(n,ast.FunctionDef)and n.name=='validate')
  texts=lambda f:[ast.dump(n,include_attributes=False)for n in ast.walk(f)if isinstance(n,ast.Call)and isinstance(n.func,ast.Attribute)and n.func.attr=='read_text']
  self.assertEqual(texts(method(ast.parse(raw))),texts(method(tree)))
 def test_unknown_gate_and_manifest_bytes_refuse(self):
  raw=(ROOT/gate.GATE).read_bytes();manifest=(ROOT/gate.MANIFEST).read_bytes()
  self.assertEqual(gate._manifest_data(manifest),manifest)
  for body in(raw+b'\n# unreviewed',b''):
   with self.assertRaises(ValueError):gate.current_tree(body)
  for body in(manifest+b'\n',b'',manifest.decode()):
   with self.assertRaises(ValueError):gate._manifest_data(body)
 def test_unknown_inventory_AST_refuses(self):
  tree=ast.parse((ROOT/gate.GATE).read_bytes());f=next(n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='validate')
  actual=next(n for n in f.body if isinstance(n,ast.Assign)and len(n.targets)==1 and isinstance(n.targets[0],ast.Name)and n.targets[0].id=='actual');actual.value=ast.List(elts=[],ctx=ast.Load())
  with self.assertRaises(ValueError):gate.TypedSeams().visit(f)
 def test_fake_owner_refuses_before_source_capture(self):
  with self.assertRaises(ValueError):gate.verify_in_operation(object(),ROOT)
 def test_only_two_exact_current_parent_metadata_deltas(self):
  # Retain the existing test ID/count; the reviewed closed set now has three.
  self.assertEqual(set(gate.METADATA_DELTAS),{
   'src/applications/people/governance/auth/PhorgeGovernanceNativeIdentitySource.php',
   'src/infrastructure/governance/execution/PhorgeProjectConfigEpochScope.php',
   'src/infrastructure/governance/media/PhorgeGovernanceMediaExchangeService.php'})
  for name,(old,new)in gate.METADATA_DELTAS.items():
   current=(ROOT/name).read_bytes();parent=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','5903d98b14926401f0be49cb0a9d6168026a2642:'+name])
   self.assertEqual((gate.sha(parent),gate.sha(current)),(old,new));self.assertEqual(gate._metadata_data(name,current,parent),parent)
   for raw,prior in((current+b'unknown',parent),(current,parent+b'unknown'),(current,None),
     (b'!'+current[1:],parent),(current,b'!'+parent[1:]),(current.decode(),parent),(current,bytearray(parent))):
    with self.assertRaises(ValueError):gate._metadata_data(name,raw,prior)
 def test_unknown_metadata_path_cannot_gain_inverse_role(self):
  with self.assertRaises(ValueError):gate._metadata_data('src/unknown.php',b'current',b'parent')
  self.assertEqual(gate._metadata_data('src/unchanged.php',b'same',b'same'),b'same')
 def inventory(self,**changes):
  names=sorted(gate.TASK_RUNTIME);old=['src/old-static.php'];actual=sorted(old+names);additional={n:(n.encode(),None,{'bytes':len(n.encode()),'sha256':gate.sha(n.encode())})for n in names}
  args={'actual':actual,'captured':actual,'old_names':old,'extra_names':set(names),'additional':additional};args.update(changes);return gate._inventory_data(**args)
 def test_closed_new_classes_are_not_reported_as_old_manifest_coverage(self):
  self.assertEqual(len(gate.TASK_RUNTIME),13);self.assertEqual(self.inventory(),['src/old-static.php'])
 def test_unknown_physical_captured_and_role_names_refuse(self):
  names=sorted(['src/old-static.php',*gate.TASK_RUNTIME]);extra=set(gate.TASK_RUNTIME)
  for change in({'actual':names+['src/unknown.php']},{'captured':names+['src/unknown.php']},{'extra_names':extra|{'src/unknown.php'}},{'actual':names[1:]},{'old_names':['src/old-static.php',next(iter(extra))]}):
   with self.assertRaises(ValueError):self.inventory(**change)
 def test_new_class_requires_exact_bytes_pin_and_no_parent(self):
  good={n:(n.encode(),None,{'bytes':len(n.encode()),'sha256':gate.sha(n.encode())})for n in gate.TASK_RUNTIME};name=next(iter(good));raw,parent,pin=good[name]
  for value in((raw+b'drift',parent,pin),(raw,b'parent',pin),(raw,parent,{'bytes':len(raw),'sha256':'0'*64})):
   changed=copy.deepcopy(good);changed[name]=value
   with self.assertRaises(ValueError):self.inventory(additional=changed)
if __name__=='__main__':unittest.main()
