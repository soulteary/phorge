"""Exact historical inverse and current source separation, no runtime proof."""
from n05_legacy_audit_test_view import historical_tooling_source
from pathlib import Path
import ast,hashlib,json,subprocess,sys,tempfile,unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import recovery_partial_current_schema_delta as partial
import recovery_oauth_authorization_source_delta as authorization_delta
class PartialSchemaCoordination(unittest.TestCase):
 def test_all_eight_exact_inverses_recover_real_prior_Git_bytes(self):
  self.assertEqual(len(partial.SOURCE_INPUTS),8)
  for path,pin in partial.SOURCE_INPUTS.items():
   raw=(ROOT/path).read_bytes();self.assertEqual(hashlib.sha256(authorization_delta.reduce_if_selected(path,raw)).hexdigest(),pin)
   old=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','1a0297901709227e80815ae4aeede1b17d06f7dd:'+path]);self.assertEqual(partial.reduce_if_selected(path,raw),old)
   for bad in [raw+b'\n# unknown',raw.replace(b'4',b'7',1)]:
    with patch.dict(partial.SOURCE_INPUTS,{path:hashlib.sha256(bad).hexdigest()}):
     with self.assertRaises(ValueError):partial.reduce_if_selected(path,bad)
 def test_whole_current_or_old_cohort_only_and_missing_mixed_refuse(self):
  import project_description_admission_delta as admission
  admission.assert_current_recipe(ROOT)
  from n05_r3_historical_test_view import checked_modules
  historical_partial=checked_modules().partial_delta
  current_sources={p:authorization_delta.reduce_if_selected(p,(ROOT/p).read_bytes()) for p in partial.SOURCE_INPUTS}
  self.assertEqual({p:hashlib.sha256(raw).hexdigest() for p,raw in current_sources.items()},partial.SOURCE_INPUTS)
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp)
   def fill(current):
    for p in partial.SOURCE_INPUTS:
     f=root/p;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(current_sources[p]if current else partial.EDITS[p][0][1])
   read=lambda p:p.read_bytes()
   for current in [False,True]:fill(current);historical_partial.assert_cohort(root,read)
   name=next(iter(partial.SOURCE_INPUTS));(root/name).write_bytes(partial.EDITS[name][0][1])
   with self.assertRaises(ValueError):historical_partial.assert_cohort(root,read)
   (root/name).unlink()
   with self.assertRaises(FileNotFoundError):historical_partial.assert_cohort(root,read)
 def test_old_literal_maps_remain_byte_exact(self):
  for name in ['scripts/governance/check_recovery_assessment_credentials.py','scripts/governance/check_recovery_assessment_credentials_v2.py']:
   raw=(ROOT/name).read_bytes();baseline=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','08453b13afc7980ca485ecf8b4d5094769bb4438:'+name]);self.assertEqual(authorization_delta.reduce_if_selected(name,raw),baseline)
   def maps(body):return {n.targets[0].id:ast.dump(n.value,include_attributes=False)for n in ast.parse(body).body if isinstance(n,ast.Assign)and isinstance(n.value,ast.Dict)}
   self.assertEqual(maps(authorization_delta.reduce_if_selected(name,raw)),maps(baseline))
if __name__=='__main__':unittest.main()
