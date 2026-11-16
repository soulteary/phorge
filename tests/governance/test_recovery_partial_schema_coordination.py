"""Exact historical inverse and current source separation, no runtime proof."""
from n05_legacy_audit_test_view import historical_tooling_source
from pathlib import Path
import ast,hashlib,json,subprocess,sys,tempfile,unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
from task_partial_schema_test_api_v2 import TaskPartialSchemaCurrentCase
import recovery_partial_current_schema_delta as partial
import recovery_oauth_authorization_source_delta as authorization_delta
class PartialSchemaCoordination(TaskPartialSchemaCurrentCase):
 def test_all_eight_exact_inverses_recover_real_prior_Git_bytes(self):
  self.assertEqual(len(self.partial.SOURCE_INPUTS),8)
  for path,pin in self.partial.SOURCE_INPUTS.items():
   raw=self.parent_data(path);self.assertEqual(hashlib.sha256(self.authorization.reduce_if_selected(path,raw)).hexdigest(),pin)
   old=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','1a0297901709227e80815ae4aeede1b17d06f7dd:'+path]);self.assertEqual(self.partial.reduce_if_selected(path,raw),old)
   for bad in [raw+b'\n# unknown',raw.replace(b'4',b'7',1)]:
    with patch.dict(self.partial.SOURCE_INPUTS,{path:hashlib.sha256(bad).hexdigest()}):
     with self.assertRaises(ValueError):self.partial.reduce_if_selected(path,bad)
 def test_whole_current_or_old_cohort_only_and_missing_mixed_refuse(self):
  import project_description_admission_delta as admission
  self._admission.assert_current_recipe(ROOT)
  from n05_r3_historical_test_view import checked_modules
  historical_partial=self.r3.checked_modules().partial_delta
  current_sources={p:self.authorization.reduce_if_selected(p,self.parent_data(p)) for p in self.partial.SOURCE_INPUTS}
  self.assertEqual({p:hashlib.sha256(raw).hexdigest() for p,raw in current_sources.items()},self.partial.SOURCE_INPUTS)
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp)
   def fill(current):
    for p in self.partial.SOURCE_INPUTS:
     f=root/p;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(current_sources[p]if current else self.partial.EDITS[p][0][1])
   read=lambda p:p.read_bytes()
   for current in [False,True]:fill(current);historical_partial.assert_cohort(root,read)
   name=next(iter(self.partial.SOURCE_INPUTS));(root/name).write_bytes(self.partial.EDITS[name][0][1])
   with self.assertRaises(ValueError):historical_partial.assert_cohort(root,read)
   (root/name).unlink()
   with self.assertRaises(FileNotFoundError):historical_partial.assert_cohort(root,read)
 def test_old_literal_maps_remain_byte_exact(self):
  for name in ['scripts/governance/check_recovery_assessment_credentials.py','scripts/governance/check_recovery_assessment_credentials_v2.py']:
   raw=self.parent_data(name);baseline=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','08453b13afc7980ca485ecf8b4d5094769bb4438:'+name]);self.assertEqual(self.authorization.reduce_if_selected(name,raw),baseline)
   def maps(body):return {n.targets[0].id:ast.dump(n.value,include_attributes=False)for n in ast.parse(body).body if isinstance(n,ast.Assign)and isinstance(n.value,ast.Dict)}
   self.assertEqual(maps(self.authorization.reduce_if_selected(name,raw)),maps(baseline))
if __name__=='__main__':unittest.main()
