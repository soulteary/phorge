"""Misuse/strict-negative tests for CURRENT receipt unit API; no SQL execution."""
import copy,hashlib,json,os,subprocess,sys,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
from task_native_gate_test_api_v2 import ROOT,current_native_gate,FAMILIES
from task_admission_test_api_v2 import checkpoint
class CurrentGateControls(unittest.TestCase):
 def test_unknown_family_cannot_enter_an_owner(self):
  import task_native_gate_test_api_v2 as api
  with patch.object(api,'owner',side_effect=AssertionError('source owner unexpectedly entered'))as entered:
   with self.assertRaises(ValueError):
    with current_native_gate(ROOT,'unknown'):pass
   entered.assert_not_called()
 def test_actual_closed_CURRENT_bodies_retain_original_counts_and_refuse_claims(self):
  for family in FAMILIES:
   with self.subTest(family=family),current_native_gate(ROOT,family)as gate:
    data=gate.template();good=gate.validate(data,source_revision='a'*40,file_digest='b'*64)
    expected={'session':(16,6),'borrowed':(16,6),'conduit':(16,6),'oauth-access':(18,8),'assessment-credential':(18,8),'fileparent':(16,6),'oauth-code':(18,8)}
    self.assertEqual((good['checks'],good['SQL_obligations']),expected[family])
    self.assertFalse(good['restoration_authorized']);self.assertFalse(good['all_sources_complete']);self.assertTrue(good['execution_blocked'])
    for label in data['checks']:
     bad=copy.deepcopy(data);bad['checks'].remove(label);bad['check_count']-=1
     with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
    for key in ['all_sources_complete','restoration_authorized']:
     bad=copy.deepcopy(data);bad['native'][key]=True
     with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
    for key,value in [('process_stopped',False),('private_storage_removed',False),('kind','image')]:
     bad=copy.deepcopy(data);bad['file_service_cleanup'][key]=value
     with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
    bad=copy.deepcopy(data);bad['production_optional_features_enabled']=True
    with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
 def test_wrong_root_foreign_capture_bytes_and_closed_owner_refuse(self):
  with current_native_gate(ROOT,'session')as gate:
   data=gate.template();name=gate.relative;raw=gate.raws[name]
   self.assertEqual(gate.capture_bytes(name,raw),raw)
   for candidate in [raw+b'\n# changed',raw.decode()]:
    with self.assertRaises(ValueError):gate.capture_bytes(name,candidate)
   with self.assertRaises(ValueError):gate.capture_bytes('unknown',raw)
   with self.assertRaises(ValueError):gate.SQL_obligations(ROOT.parent)
   with self.assertRaises(ValueError):gate.validate(data,ROOT.parent,source_revision='a'*40,file_digest='b'*64)
  with self.assertRaises(ValueError):gate.validate(data,source_revision='a'*40,file_digest='b'*64)
 def test_registered_fixture_DATA_keeps_tamper_and_unknown_root_refusals(self):
  with tempfile.TemporaryDirectory()as tmp,current_native_gate(ROOT,'session')as gate:
   fixture=Path(tmp).resolve()/'fixture';gate.fixture_DATA(fixture)
   self.assertEqual(gate.fixture_SQL_obligations(fixture),gate.SQL_obligations())
   with self.assertRaises(ValueError):gate.fixture_SQL_obligations(Path(tmp).resolve())
   name='support/governance/recovery/session-lease-cases.php';path=fixture/name;raw=path.read_bytes()
   path.write_bytes(raw+b'// unreviewed fixture mutation')
   with self.assertRaises(ValueError):gate.fixture_SQL_obligations(fixture)
   path.write_bytes(raw)
   self.assertEqual(gate.fixture_SQL_obligations(fixture),gate.SQL_obligations())
 def test_original_public_default_still_refuses_Task_source(self):
  with current_native_gate(ROOT,'session')as gate:original=gate.checker
  with self.assertRaises(ValueError):original.SQL_obligations(ROOT)
 def test_versioned_child_CLI_retains_strict_JSON_independent_binary_and_revision(self):
  with tempfile.TemporaryDirectory()as tmp:
   base=Path(tmp).resolve();binary=base/'binary';body=b'synthetic independent binary; not executed';binary.write_bytes(body);binary.chmod(0o700)
   digest=hashlib.sha256(body).hexdigest();receipt=base/'receipt';receipt.touch(mode=0o600)
   with current_native_gate(ROOT,'session')as gate:good=gate.template(digest)
   cmd=[sys.executable,'-B',str(ROOT/'tests/governance/task_native_gate_test_api_v2.py'),'--family','session','--expected-checkpoint',checkpoint(),'--receipt',str(receipt),'--source-revision','a'*40,'--file-binary',str(binary),'--file-binary-sha256',digest]
   raw=json.dumps(good)
   for text,ok in [(raw,True),(raw.replace('"passed": true','"passed": true,"passed": false',1),False),(raw.replace('a'*40,'c'*40),False),(raw[:-1]+',"unknown":false}',False)]:
    receipt.write_text(text);result=subprocess.run(cmd,capture_output=True,text=True,timeout=10);self.assertEqual(result.returncode==0,ok,result.stderr)
    if ok:
     accepted=json.loads(result.stdout);self.assertTrue(accepted['CURRENT_source_exit_verified']);self.assertFalse(accepted['physical_SQL_executed']);self.assertFalse(accepted['runtime_qualified'])
   receipt.write_text(raw);binary.write_bytes(body+b'changed')
   self.assertNotEqual(subprocess.run(cmd,capture_output=True,text=True,timeout=10).returncode,0)
if __name__=='__main__':unittest.main()
