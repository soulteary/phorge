"""Synthetic gate controls, not SQL or native default-provider proof."""
import copy, importlib.util,json,subprocess,sys,tempfile,unittest,re
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_recovery_application_config as gate
spec=importlib.util.spec_from_file_location('config_native_template',ROOT/'tests/governance/test_native_organization.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
def template():
 d=module.template();d.update(batch='native-application-config-facts',scope='owned-native-config-source-facts-no-lease',
  checks=sorted(gate.BOOTSTRAP|gate.SQL|gate.CLEANUP),check_count=19,native=gate.summary('a'*40),
  file_service_cleanup=None,successful_owned_file_service_startups=0)
 d['worker_cleanup']={k:0 for k in ('private_directories_removed','owned_processes_stopped','cleanup_failures','private_directories_remaining','live_processes_after_cleanup','workers_registered')}
 return d
class ApplicationConfigGate(unittest.TestCase):
 def test_control_then_every_obligation_and_authority_negative(self):
  d=template();self.assertTrue(gate.validate(d,source_revision='a'*40)['passed'])
  for name in d['checks']:
   bad=copy.deepcopy(d);bad['checks'].remove(name)
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40)
  for key,value in [('php_version','unsupported'),('raw_secret','SENTINEL'),('check_count',True),('terminal_failure_cleanup_rollbacks',False)]:
   bad=copy.deepcopy(d);bad[key]=value
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40)
  for key in ('complete','all_sources_complete','restoration_authorized','continuous_lease_or_ABA_proved'):
   bad=copy.deepcopy(d);bad['native'][key]=True
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40)
  bad=copy.deepcopy(d);bad['worker_cleanup']['workers_registered']=False
  with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40)
 def test_actual_cli_positive_duplicate_and_nonfinite(self):
  with tempfile.TemporaryDirectory()as tmp:
   p=Path(tmp)/'receipt.json';raw=json.dumps(template());p.write_text(raw)
   cmd=[sys.executable,'-B',str(ROOT/'scripts/governance/check_recovery_application_config.py'),'--receipt',str(p),'--source-revision','a'*40]
   result=subprocess.run(cmd,text=True,capture_output=True);self.assertEqual(0,result.returncode,result.stderr);self.assertEqual('',result.stderr)
   for mutant in [raw.replace('"complete": false','"complete": true,"complete": false'),raw.replace('"check_count": 19','"check_count": NaN')]:
    p.write_text(mutant);self.assertNotEqual(0,subprocess.run(cmd,text=True,capture_output=True).returncode)
 def test_real_entry_dependencies_and_obligation_set(self):
  source=(ROOT/'support/governance/recovery/application-config-focused.php').read_text()
  ids=set(re.findall(r"'(recovery-config-[A-Za-z-]+)'",source));self.assertEqual(gate.SQL,ids)
  self.assertIn('new PhabricatorConfigDatabaseSource($namespace)',source)
  self.assertNotIn('ConfigEditor',source)
  driver=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text()
  self.assertIn("'--application-config-facts'=>'application-config-facts'",driver)
  self.assertIn("$native=governance_recovery_application_config_focused($fixture,$check)",driver)
if __name__=='__main__':unittest.main()
