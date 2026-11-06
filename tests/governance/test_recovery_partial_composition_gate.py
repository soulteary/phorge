"""Synthetic gate acceptance/rejection controls only; not native SQL evidence."""
import copy, importlib.util, json, re, subprocess, sys, tempfile, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_recovery_partial_composition as gate
spec=importlib.util.spec_from_file_location('partial_native_template',ROOT/'tests/governance/test_native_organization.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
def template():
 d=module.template();checks=sorted(gate.BOOTSTRAP|gate.SQL|gate.CLEANUP)
 d.update(batch='native-recovery-partial-composition',scope='owned-native-partial-composition-no-permit',checks=checks,check_count=len(checks),native=gate.summary('a'*40),file_service_cleanup=None,successful_owned_file_service_startups=0)
 d['worker_cleanup']={k:0 for k in ('private_directories_removed','owned_processes_stopped','cleanup_failures','private_directories_remaining','live_processes_after_cleanup','workers_registered')}
 return d
class PartialGate(unittest.TestCase):
 def test_every_obligation_and_authority_negative(self):
  d=template();self.assertTrue(gate.validate(d,source_revision='a'*40)['passed'])
  for label in d['checks']:
   bad=copy.deepcopy(d);bad['checks'].remove(label)
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40)
  for k,v in [('complete',True),('all_sources_complete',True),('restoration_authorized',True),('Task_File_owned_RR_combined',True),('assessment_schema',2),('actual_current_writer_adapters',[])]:
   bad=copy.deepcopy(d);bad['native'][k]=v
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40)
 def test_duplicate_and_unrun_or_wrong_revision_refused(self):
  with tempfile.TemporaryDirectory() as tmp:
   p=Path(tmp)/'receipt.json';raw=json.dumps(template())
   cmd=[sys.executable,'-B',str(ROOT/'scripts/governance/check_recovery_partial_composition.py'),'--receipt',str(p),'--source-revision','a'*40]
   for value,ok in [(raw,True),(raw.replace('"complete": false','"complete": true,"complete": false'),False),(raw.replace('"passed": true','"passed": false'),False),(raw.replace('a'*40,'b'*40),False)]:
    p.write_text(value);r=subprocess.run(cmd,capture_output=True,text=True);self.assertEqual(r.returncode==0,ok,r.stderr)
 def test_gate_obligations_match_actual_fixture_cases(self):
  labels=set()
  for name in ('restore-review-cases.php','partial-composition-cases.php'):
   labels.update(re.findall(r"'(recovery-(?:restore|partial)-[a-z0-9-]+)'",(ROOT/'support/governance/recovery'/name).read_text()))
  self.assertEqual(labels,gate.SQL)
