"""Versioned selected receipt controls; synthetic receipts are not runtime evidence."""
import sys,copy,json,hashlib,subprocess,unittest,tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_calendar_revocation_measurement as gate
from test_private_calendar_sql_gate import native_template,REVISION,FILE,QUEUE
from test_calendar_revocation_observer import Observer
class Gate(unittest.TestCase):
 def template(self):
  d=native_template.template();d.update(batch='native-calendar-revocation-measurement-v1',scope='owned-native-calendar-revocation-measurement-no-SMTP-v1',native=gate.summary(REVISION,QUEUE),checks=sorted(gate.obligations()),check_count=len(gate.obligations()),worker_cleanup={'private_directories_removed':0,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':0},file_service_cleanup={'kind':'file','binary_sha256':FILE,'process_stopped':True,'private_storage_removed':True},cleanup={'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True});d['native']['event']=Observer().actual();return d
 def validate(self,d):return gate.validate(d,source_revision=REVISION,file_digest=FILE,queue_digest=QUEUE)
 def test_exact_sources_and_callable_case_inventory(self):
  self.assertEqual(18,len(gate.sources()));p=subprocess.run(['php','-r','require $argv[1];echo json_encode(governance_calendar_revocation_obligations());',str(ROOT/'support/governance/revocation-measurement/calendar-cases.php')],capture_output=True,text=True,check=True);self.assertEqual(gate.CASES,set(json.loads(p.stdout)))
 def test_valid_synthetic_receipt_still_never_accepts_measurement(self):
  r=self.validate(self.template());self.assertTrue(r['passed']);self.assertFalse(r['measurement_acceptance']);self.assertEqual('external-execution-attestation-required',r['producer_attestation'])
 def test_bad_source_boot_timing_cleanup_outcome_or_extra_field_refused(self):
  for mode in ['producer','boot','time','cleanup','Aallow','extra','missingcheck']:
   d=self.template()
   if mode=='producer':d['native']['event']['producer_sha256']='b'*64
   if mode=='boot':d['native']['event']['boot_id_sha256']='wrong'
   if mode=='time':d['native']['event']['operation_start_ns']=True
   if mode=='cleanup':d['cleanup']['ownedSchemasRemoved']=54
   if mode=='Aallow':d['native']['event']['attempts'][4]['outcome']='allowed'
   if mode=='extra':d['native']['unexpected']=True
   if mode=='missingcheck':d['checks']=d['checks'][:-1]
   with self.assertRaises(ValueError):self.validate(d)
 def test_fixed_CLI_refuses_other_phase_before_body(self):
  text=(ROOT/'support/governance/native-organization/test-calendar-revocation-measurement.php').read_text();phase=text.split('$phase=',1)[1].split(';',1)[0]
  for args,expected in [([], 'invalid'),(['--calendar-revocation-measurement'],'calendar-reminders'),(['--calendar-reminders'],'invalid'),(['--calendar-revocation-measurement','extra'],'invalid')]:
   code='$arguments=json_decode($argv[1],true);echo '+phase+';';p=subprocess.run(['php','-r',code,json.dumps(args)],capture_output=True,text=True,check=True);self.assertEqual(expected,p.stdout)

 def test_source_drift_refuses_before_loading_changed_consumer(self):
  d=self.template()
  with tempfile.TemporaryDirectory()as directory:
   root=Path(directory)
   for relative in gate.SOURCE_INPUTS:
    f=root/relative;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes((ROOT/relative).read_bytes())
   (root/'support/governance/revocation-measurement/calendar-consumer.py').write_text("raise RuntimeError('untrusted consumer executed')")
   with self.assertRaises(ValueError):gate.validate(d,source_revision=REVISION,file_digest=FILE,queue_digest=QUEUE,root=root)

 def test_descriptor_second_capture_is_verified_before_execution(self):
  from unittest.mock import patch
  d=self.template();original=gate.source_reader.read_source_checked;calls=[]
  def changed(path):
   raw=original(path)
   if str(path).endswith('/calendar-consumer.py'):
    calls.append(str(path))
    if len(calls)==2:return b"raise RuntimeError('untrusted second read executed')"
   return raw
  with patch.object(gate.source_reader,'read_source_checked',side_effect=changed):
   with self.assertRaises(ValueError):self.validate(d)
  self.assertEqual(2,len(calls))

 def test_actual_CLI_phase_loads_real_empty_worker_registry(self):
  source=(ROOT/'support/governance/native-organization/test-calendar-revocation-measurement.php').read_text();phase=source.split("}elseif($phase==='calendar-reminders'){",1)[1].split("}elseif",1)[0];self.assertIn("require_once __DIR__.'/workers.php';",phase)
  code="require $argv[1];echo json_encode(governance_native_cleanup_workers());"
  p=subprocess.run(['php','-r',code,str(ROOT/'support/governance/native-organization/workers.php')],capture_output=True,text=True,check=True);self.assertEqual({'private_directories_removed':0,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':0},json.loads(p.stdout))
