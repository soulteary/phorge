"""Pure source DATA controls; no SourceOperation permit, SQL or runtime activation."""
import importlib.util,hashlib,json,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import task_source_operation_context as c
class PHP86CandidateCohort(unittest.TestCase):
 def raw(self):return (ROOT/c.PHP86_COMPATIBILITY_HISTORY).read_bytes()
 def test_all_selected_current_files_roundtrip_as_data(self):
  doc=c.php86_compatibility_history(self.raw())
  current={p:(ROOT/p).read_bytes()for p in c.PHP86_COMPATIBILITY_PATHS}
  parent=c.php86_compatibility_cohort(current,self.raw())
  self.assertEqual(set(current),set(parent))
  for p in current:
   self.assertEqual(doc['files'][p]['parent_sha256'],hashlib.sha256(parent[p]).hexdigest())
   self.assertEqual(current[p],c.php86_compatibility_roundtrip(p,parent[p],self.raw()))
 def test_every_current_tamper_or_reversion_refused(self):
  for p in c.PHP86_COMPATIBILITY_PATHS:
   raw=(ROOT/p).read_bytes()
   with self.assertRaises(ValueError):c.php86_compatibility_inverse(p,raw+b' ',self.raw())
   parent=c.php86_compatibility_inverse(p,raw,self.raw())
   with self.assertRaises(ValueError):c.php86_compatibility_inverse(p,parent,self.raw())
 def test_recipe_tamper_refused_before_interpretation(self):
  with self.assertRaises(ValueError):c.php86_compatibility_history(self.raw()+b' ')
  doc=json.loads(self.raw());doc['not_runtime_authority']=False
  with self.assertRaises(ValueError):c.php86_compatibility_history(json.dumps(doc).encode())
 def test_missing_or_unknown_cohort_member_refused(self):
  current={p:(ROOT/p).read_bytes()for p in c.PHP86_COMPATIBILITY_PATHS}
  name=next(iter(current));missing=dict(current);missing.pop(name)
  with self.assertRaises(ValueError):c.php86_compatibility_cohort(missing,self.raw())
  unknown=dict(current);unknown['unknown.php']=b''
  with self.assertRaises(ValueError):c.php86_compatibility_cohort(unknown,self.raw())
 def test_unknown_path_and_wrong_parent_refused(self):
  with self.assertRaises(ValueError):c.php86_compatibility_inverse('unknown.php',b'',self.raw())
  for p in c.PHP86_COMPATIBILITY_PATHS:
   current=(ROOT/p).read_bytes();parent=c.php86_compatibility_inverse(p,current,self.raw())
   with self.assertRaises(ValueError):c.php86_compatibility_roundtrip(p,parent+b' ',self.raw())
 def test_every_added_leaf_is_exactly_registered(self):
  for name,pin in c.PHP86_COMPATIBILITY_NEW_PINS.items():
   raw=(ROOT/name).read_bytes();self.assertEqual(pin['bytes'],len(raw));self.assertEqual(pin['sha256'],hashlib.sha256(raw).hexdigest())
   self.assertEqual(pin['mode'],(ROOT/name).stat().st_mode&0o777)
if __name__=='__main__':unittest.main()
