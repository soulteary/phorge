"""Host-only real classifier/strict gate controls; no SQL or gap-lock proof."""
import copy,hashlib,importlib.util,json,pathlib,subprocess,sys,tempfile,unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_completion as completion
import check_recovery_policy_isolation as gate
REV='a'*40

def template():
 return dict(batch='native-recovery-policy-range-component',scope='owned-full-native-source-synthetic-accounts',passed=True,
 php_version='8.5.12',check_count=23,checks=sorted(gate.BOOTSTRAP|gate.SQL|gate.CLEANUP),failure=None,cleanup_failures=[],
 optional_source_reader_adapter_exercised=True,isolated_optional_features_enabled=True,file_service_cleanup=None,
 successful_owned_file_service_startups=0,terminal_failure_cleanup_rollbacks=0,production_deployment=False,
 production_executor=False,production_optional_features_enabled=False,native_permission_axes_changed=False,
 cleanup=dict(ownedSchemasRemoved=55,ownedRuntimeUsersRemoved=2,readerRemoved=True),
 worker_cleanup=dict(private_directories_removed=4,owned_processes_stopped=0,cleanup_failures=0,private_directories_remaining=0,live_processes_after_cleanup=0,workers_registered=4),
 native=dict(profile='isolated-native-policy-isolation-comparison-v2',source_revision=REV,actual_native_policy_dao=True,
 service_owned_rr_transaction=False,fixture_owned_rr_transaction=True,service_rc_producer_commits_and_current_read_changes=True,
 service_rc_absence_fence_proved=False,fixture_refuses_observed_rc_source_changes=True,actual_empty_and_populated_primary_insert_waits=True,registered_policy_workers=4,
 policy_source_SQL_obligations=14,identified_fixture_seed_restored=True,all_mode_native_evidence=False,
 full_storage_upgrade_acceptance=False,all_thirteen_families_complete=False,restoration_authorized=False,
 production_scale_ready=False,HTTP_controller_evidence=False),
 source_restore=dict(quickstart_sha256=hashlib.sha256((ROOT/'resources/sql/quickstart.sql').read_bytes()).hexdigest(),app_schema_count=54,
 owned_schema_count=55,quickstart_source_table_count=500,current_table_count=549,restore_phase_workers_started=0,restore_phase_network_endpoints_configured=0,
 patch_audit=dict(source_patch_count=1575,applied_patch_count=1575,pending_patch_keys=[],current_patch_ledger_complete=True,
 migration_source_hashes={'phabricator:'+n:hashlib.sha256((ROOT/'resources/sql/autopatches'/n).read_bytes()).hexdigest()for n in completion.PATCHES},migrations_executed=38)),
 captured_native_output_bytes=0,captured_native_output_sha256=hashlib.sha256(b'').hexdigest(),remaining_blocked=['media-http-and-cookie-integration','file-object-guards','notification-producers','global-governance-completion'])
class PolicyIsolationTests(unittest.TestCase):
 def test_real_partial_difference_guard_rejects_change_and_unknown_authority(self):
  program=r'''class Phobject{} require $argv[1];require $argv[2];
$x=json_decode(stream_get_contents(STDIN),true);try{governance_recovery_policy_refuse_changed($x[0],$x[1]);echo 'equal-partial-only';}
catch(RuntimeException $e){echo $e->getMessage();}'''
  facts=dict(complete=False,restoration_authorized=False,empty_range_absence_proved=False,policy_interpreter_complete=False,automatic_object_grants_complete=False,producer_lineage_complete=False,current_rows_locked=True,selected_source_rows={'policy':[]})
  def invoke(a,b):
   x=subprocess.run(['php','-r',program,str(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreRequest.php'),str(ROOT/'support/governance/recovery/policy-range-cases.php')],input=json.dumps([a,b]),text=True,capture_output=True,check=True)
   self.assertEqual('',x.stderr);return x.stdout
  self.assertEqual('equal-partial-only',invoke(facts,facts))
  changed=copy.deepcopy(facts);changed['selected_source_rows']['policy']=[{'identity_digest':'b'*64}]
  self.assertEqual('recovery_policy_current_source_changed',invoke(facts,changed))
  for key in ('complete','restoration_authorized','empty_range_absence_proved','current_rows_locked'):
   for value in (True,None,'false'):
    if key=='current_rows_locked'and value is True:continue
    malformed=copy.deepcopy(facts);malformed[key]=value
    self.assertEqual('recovery_policy_partial_facts_required',invoke(facts,malformed))
 def test_strict_positive_and_every_obligation_required(self):
  self.assertTrue(gate.validate(template(),source_revision=REV)['passed']);self.assertEqual(14,len(gate.SQL))
  for obligation in gate.SQL|gate.CLEANUP|gate.BOOTSTRAP:
   d=template();d['checks'].remove(obligation)
   with self.subTest(obligation=obligation),self.assertRaises(ValueError):gate.validate(d,source_revision=REV)
 def test_old_service_rr_and_authority_or_wrong_workers_refused(self):
  for key in ('service_owned_rr_transaction','service_rc_absence_fence_proved','all_thirteen_families_complete','restoration_authorized'):
   d=template();d['native'][key]=True
   with self.subTest(key=key),self.assertRaises(ValueError):gate.validate(d,source_revision=REV)
  for key,value in [('check_count',18),('check_count',True)]:
   d=template();d[key]=value
   with self.assertRaises(ValueError):gate.validate(d,source_revision=REV)
  d=template();d['worker_cleanup']['workers_registered']=2
  with self.assertRaises(ValueError):gate.validate(d,source_revision=REV)
 def test_cli_exact_source_and_duplicate_json(self):
  with tempfile.TemporaryDirectory()as temp:
   p=pathlib.Path(temp)/'receipt.json';p.write_text(json.dumps(template()))
   command=[sys.executable,'-B',str(ROOT/'scripts/governance/check_recovery_policy_isolation.py'),'--receipt',str(p),'--source-revision',REV]
   good=subprocess.run(command,capture_output=True,text=True);self.assertEqual(0,good.returncode);self.assertEqual('',good.stderr)
   p.write_text('{"passed":false,'+json.dumps(template())[1:]);bad=subprocess.run(command,capture_output=True,text=True);self.assertNotEqual(0,bad.returncode)
 def test_fixed_owner_join_and_production_rc_preserved(self):
  source=(ROOT/'support/governance/recovery/policy-range-cases.php').read_text()
  self.assertIn('l.ENGINE=w.ENGINE',source);self.assertIn('w.BLOCKING_ENGINE_TRANSACTION_ID',source)
  self.assertIn('r.TRX_REQUESTED_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID',source);self.assertNotIn('w.BLOCKING_THREAD_ID',source)
  service=(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreService.php').read_text()
  self.assertIn("queryfx($conn,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED')",service)
if __name__=='__main__':unittest.main()
