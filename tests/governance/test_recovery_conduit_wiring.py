"""Actual fixed driver selection/whole cleanup and strict synthetic receipts; no SQL."""
from pathlib import Path
import unittest,sys,subprocess,json,tempfile,hashlib,copy
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_recovery_conduit_producer as gate
import recovery_conduit_source_delta as delta
import test_recovery_session_lease_gate as previous
class ConduitWiring(unittest.TestCase):
 def template(self,digest='b'*64):
  d=previous.template(digest);labels=sorted(gate.obligations());d.update(batch='native-conduit-producer-owner',scope='owned-native-conduit-producer-owner-no-permit',checks=labels,check_count=len(labels),native=gate.summary('a'*40));return d
 def test_actual_driver_selector_and_exact_prior_driver_inverse(self):
  text=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text();a=text.index('$arguments=');b=text.index('$workbench_http=',a);block=text[a:b]
  for argv,want in [(['--conduit-producer-owner'],'conduit-producer-owner'),(['--conduit-producer-owner','--session-borrowed-owner'],'invalid'),(['--conduit-producer-owner','--conduit-producer-owner'],'invalid'),(['--conduit'],'invalid')]:
   code='$argv=array_merge(["driver"],json_decode($argv[1],true));'+block+'echo json_encode($phase);';p=subprocess.run(['php','-r',code,json.dumps(argv)],capture_output=True,text=True,timeout=5);self.assertEqual(0,p.returncode,p.stderr);self.assertEqual(want,json.loads(p.stdout))
  raw=text.encode();self.assertEqual(delta.reduce_driver(raw),subprocess.check_output(['git','-C',str(ROOT),'show','c496e2cf0ae25fb3e5153c6a1070eb53cf56b504:support/governance/native-organization/test-native-organization-mysql.php']))
  pin=delta.DRIVER_SHA256
  try:
   bad=raw+b'\n// unrelated driver mutation\n';delta.DRIVER_SHA256=hashlib.sha256(bad).hexdigest()
   with self.assertRaises(ValueError):delta.reduce_driver(bad)
  finally:delta.DRIVER_SHA256=pin
 def test_actual_whole_driver_finally_unknown_before_any_generic_cleanup(self):
  for mode in ['unknown','forged-flags','early','good','old']:
   p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/conduit-driver-cleanup-host.php'),str(ROOT),mode],capture_output=True,text=True,timeout=5);self.assertEqual(0,p.returncode,p.stderr);d=json.loads(p.stdout)
   if mode in ['unknown','forged-flags','early']:self.assertEqual([0]*5,[d[k]for k in ['rollbacks','pool_closes','disposes','drops','control_closes']]);self.assertEqual('current',d['env'])
   else:self.assertEqual(1,d['drops']);self.assertEqual(1,d['control_closes'])
  source=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text();self.assertLess(source.index("$GLOBALS['governance_recovery_conduit_cleanup_proved']=$phase!=="),source.index('$fixture = null;'))
 def test_source_derived_6_SQL_16_total_35_sources_and_strict_tamper(self):
  d=self.template();v=gate.validate(d,source_revision='a'*40,file_digest='b'*64);self.assertEqual((6,16,35),(v['SQL_obligations'],v['checks'],v['source_inputs']))
  for mutate in [lambda b:b['checks'].pop(),lambda b:b['native'].__setitem__('all_sources_complete',True),lambda b:b['native'].__setitem__('continuous_admin_lease_proved',True),lambda b:b['native'].__setitem__('source_revision','c'*40),lambda b:b['native']['observer_cleanup'].__setitem__('cleanup_proved',False),lambda b:b['file_service_cleanup'].__setitem__('process_stopped',False),lambda b:b.__setitem__('unknown',False)]:
   b=copy.deepcopy(d);mutate(b)
   with self.assertRaises(ValueError):gate.validate(b,source_revision='a'*40,file_digest='b'*64)
 def test_actual_public_CLI_requires_independent_binary_and_rejects_duplicate_receipt(self):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   p=Path(tmp);binary=p/'file-data';binary.write_bytes(b'binary data only; never executed');binary.chmod(0o700);digest=hashlib.sha256(binary.read_bytes()).hexdigest();receipt=p/'receipt';receipt.touch(mode=0o600);body=json.dumps(self.template(digest));cmd=[sys.executable,'-B',str(ROOT/'scripts/governance/check_recovery_conduit_producer.py'),'--receipt',str(receipt),'--source-revision','a'*40,'--file-binary',str(binary),'--file-binary-sha256',digest]
   for raw,good in [(body,True),(body.replace('"passed": true','"passed": true,"passed": true',1),False),(body[:-1]+',"unknown":false}',False)]:
    receipt.write_text(raw);r=subprocess.run(cmd,capture_output=True,text=True,timeout=10);self.assertEqual(good,r.returncode==0,r.stderr)
   receipt.write_text(body);binary.write_bytes(b'changed');r=subprocess.run(cmd,capture_output=True,text=True,timeout=10);self.assertNotEqual(0,r.returncode)
 def test_actual_worker_unknown_does_not_explicitly_rollback_close_or_replace_primary(self):
  for mode in ['failed','retained','good','old']:
   p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/conduit-worker-cleanup-host.php'),str(ROOT),mode],capture_output=True,text=True,timeout=5);self.assertEqual(0,p.returncode,p.stderr);d=json.loads(p.stdout)
   self.assertEqual([0 if mode in ['failed','retained']else 1]*4,[d[k]for k in ['rollbacks','closes','disposes','proof_closes']])
   if mode=='failed':self.assertTrue(d['original_failure_preserved'])
   if mode=='retained':self.assertEqual('failed',d['status'])

if __name__=='__main__':unittest.main()
