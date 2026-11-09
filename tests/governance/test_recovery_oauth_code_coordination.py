"""Exact public-source compatibility, never current-runtime or SQL evidence."""
import ast,hashlib,json,sys,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
from recovery_oauth_code_test_cohort import complete_source_cohort,normalized_source_tree
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import recovery_oauth_code_source_delta as prod
import recovery_oauth_code_coordinator_delta as coord
import check_recovery_session_lease as reader
import check_recovery_oauth_code_producer_v1 as current

class CodeCoordination(unittest.TestCase):
 def baseline(self):
  raw=(ROOT/'tests/governance/data/oauth-code-coordination-baseline-v1.json').read_bytes()
  self.assertEqual(hashlib.sha256(raw).hexdigest(),'ade733f26c06da349a5b428dc97eaa8c5c73a4fe762ab00b72940792b6e27db3')
  d=json.loads(raw);self.assertEqual(d['base_revision'],'9bc725ea3643cf24b6df0e806738b55496d8b388');return d
 def test_all_six_and_sixteen_actual_inverses_match_independent_base_pins(self):
  b=self.baseline();self.assertEqual(prod.BASE_PINS,b['production_base_sha256']);self.assertEqual(coord.BASE_PINS,b['historical_tooling_and_case_base_sha256'])
  self.assertEqual(len(prod.SOURCE_INPUTS),6);self.assertEqual(len(coord.SOURCE_INPUTS),16)
  for path,pin in {**prod.BASE_PINS,**coord.BASE_PINS}.items():
   raw=(ROOT/path).read_bytes();self.assertEqual(hashlib.sha256(coord.source_view(path,raw)).hexdigest(),pin)
   with self.assertRaises(ValueError):coord.source_view(path,raw+b'\n# unauthorized change')
 def test_literal_maps_and_inverse_tables_are_unchanged(self):
  def dictionaries(raw):
   out={}
   for n in ast.parse(raw).body:
    if isinstance(n,ast.Assign)and isinstance(n.value,ast.Dict):
     for target in n.targets:
      if isinstance(target,ast.Name):out[target.id]=ast.dump(n.value,include_attributes=False)
   return out
  for path in coord.SOURCE_INPUTS:
   if path.endswith('.py'):
    raw=(ROOT/path).read_bytes();self.assertEqual(dictionaries(raw),dictionaries(coord.source_view(path,raw)))
 def test_complete_current_or_complete_baseline_only_missing_mixed_unknown_refuse(self):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   root=Path(tmp);complete_source_cohort(root);self.assertTrue(prod.assert_cohort(root,reader.read_source_checked))
   for path in prod.SOURCE_INPUTS:
    p=root/path;raw=p.read_bytes();p.write_bytes(prod.reduce_current(path,raw))
    with self.assertRaises(ValueError):prod.assert_cohort(root,reader.read_source_checked)
    p.write_bytes(raw+b'\n// not reviewed')
    with self.assertRaises(ValueError):prod.assert_cohort(root,reader.read_source_checked)
    p.write_bytes(raw)
   for path in prod.SOURCE_INPUTS:
    p=root/path;p.write_bytes(prod.reduce_current(path,p.read_bytes()))
   self.assertFalse(prod.assert_cohort(root,reader.read_source_checked))
   with self.assertRaises(ValueError):current.SQL_obligations(root)
   (root/next(iter(prod.SOURCE_INPUTS))).unlink()
   with self.assertRaises(OSError):prod.assert_cohort(root,reader.read_source_checked)
 def test_repin_attempt_still_cannot_bless_unrelated_inverse_bytes(self):
  for module in (prod,coord):
   for path in module.SOURCE_INPUTS:
    raw=(ROOT/path).read_bytes()+b'\n// unrelated';pins={**module.SOURCE_INPUTS,path:hashlib.sha256(raw).hexdigest()}
    with patch.object(module,'SOURCE_INPUTS',pins):
     with self.assertRaises(ValueError):coord.source_view(path,raw)
 def test_full_actual_src_tree_restores_reviewed_base_not_only_changed_names(self):
  self.assertEqual(normalized_source_tree(ROOT),'3944b8003bb59a9b67f685df67acbd2354820b1c')
 def test_current_51_source_gate_keeps_raw_current_code_and_old_maps_unchanged(self):
  self.assertEqual(len(current.SOURCE_INPUTS),51);self.assertEqual(len(current.SQL_obligations()),8)
  for path,pin in current.SOURCE_INPUTS.items():self.assertEqual(hashlib.sha256(current.read_source_checked(ROOT/path)).hexdigest(),pin)

 def test_inherited_media_current_runtime_gate_is_not_reduced_to_historical_view(self):
  import importlib.util
  directory=ROOT/'support/governance/media-session-docker'
  spec=importlib.util.spec_from_file_location('oauth_code_media_snapshot',directory/'snapshot.py');snapshot=importlib.util.module_from_spec(spec);spec.loader.exec_module(snapshot)
  with patch.dict(sys.modules,{'snapshot':snapshot}):
   spec=importlib.util.spec_from_file_location('oauth_code_media_freshness',ROOT/'support/governance/media-session-ci/freshness.py');freshness=importlib.util.module_from_spec(spec);spec.loader.exec_module(freshness)
   self.assertEqual(snapshot.BASE,'9bc725ea3643cf24b6df0e806738b55496d8b388');self.assertEqual(len(snapshot.OVERLAY),8)
   with self.assertRaisesRegex(ValueError,'protected inventory changed; rebase required|protected source changed|committed protected source changed; rebase required'):
    freshness.verify(ROOT)
if __name__=='__main__':unittest.main()
