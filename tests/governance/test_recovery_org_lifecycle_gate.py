"""Synthetic strict-consumer mutations, not metadata runtime evidence."""
import copy,hashlib,importlib.util,json,subprocess,sys,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
spec=importlib.util.spec_from_file_location('lifecycle_gate',ROOT/'scripts/governance/check_recovery_org_lifecycle.py')
gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(gate)
REV='a'*40
def template():
 hashes={'phabricator:'+n:hashlib.sha256((ROOT/'resources/sql/autopatches'/n).read_bytes()).hexdigest()for n in gate.completion.PATCHES}
 return {'batch':'native-recovery-organization-lifecycle-component','passed':True,'scope':'owned-full-native-source-synthetic-accounts',
  'check_count':45,'checks':sorted(gate.BOOTSTRAP|gate.SQL|gate.CLEANUP),'php_version':'8.5.12',
  'cleanup':{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True},
  'worker_cleanup':{'private_directories_removed':2,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':2},
  'source_restore':{'quickstart_sha256':hashlib.sha256((ROOT/'resources/sql/quickstart.sql').read_bytes()).hexdigest(),
   'app_schema_count':54,'owned_schema_count':55,'quickstart_source_table_count':500,'current_table_count':549,
   'restore_phase_workers_started':0,'restore_phase_network_endpoints_configured':0,
   'patch_audit':{'source_patch_count':1575,'applied_patch_count':1575,'pending_patch_keys':[],'current_patch_ledger_complete':True,'migration_source_hashes':hashes,'migrations_executed':38}},
  'native':{'profile':'isolated-indexed-organization-lifecycle-generation-v1','source_revision':REV,
   'actual_service_proposal_approval_success_rollback_replay':True,
   'actual_transfer_handover_success_rollback_replay':True,'SQL_obligations':36,
   'derived_metadata_is_authority':False,'automatic_legacy_backfill':False,
   'retained_metadata_privilege_DDL_lease':False,'producer_concurrency_proved':True,
   'organization_lifecycle_readset_complete':False,'all_thirteen_families_complete':False,
   'restoration_authorized':False,'HTTP_controller_evidence':False},
  'failure':None,'cleanup_failures':[],'file_service_cleanup':None,'successful_owned_file_service_startups':0,
  'terminal_failure_cleanup_rollbacks':0,'captured_native_output_bytes':0,'captured_native_output_sha256':hashlib.sha256(b'').hexdigest(),
  'production_deployment':False,'production_executor':False,'production_optional_features_enabled':False,'native_permission_axes_changed':False,
  'optional_source_reader_adapter_exercised':True,'isolated_optional_features_enabled':True,
  'remaining_blocked':['media-http-and-cookie-integration','file-object-guards','notification-producers','global-governance-completion']}
class LifecycleGateTests(unittest.TestCase):
 def validate(self,data):return gate.validate(data,source_revision=REV)
 def test_valid_synthetic_control_and_actual_cli_match(self):
  data=template();accepted=self.validate(data);self.assertFalse(accepted['restoration_authorized'])
  with tempfile.TemporaryDirectory()as tmp:
   path=Path(tmp)/'receipt.json';path.write_text(json.dumps(data))
   result=subprocess.run([sys.executable,'-B',str(ROOT/'scripts/governance/check_recovery_org_lifecycle.py'),'--receipt',str(path),'--source-revision',REV],capture_output=True,text=True)
   self.assertEqual(0,result.returncode);self.assertEqual('',result.stderr);self.assertEqual(accepted,json.loads(result.stdout))
 def test_every_obligation_and_cleanup_count_is_required(self):
  self.assertEqual(36,len(gate.SQL))
  for name in gate.SQL|gate.CLEANUP:
   data=template();data['checks'].remove(name)
   with self.subTest(name=name),self.assertRaises(ValueError):self.validate(data)
  data=template();data['checks'][0]=data['checks'][1]
  with self.assertRaises(ValueError):self.validate(data)
  data=template();data['native']['SQL_obligations']=35
  with self.assertRaises(ValueError):self.validate(data)
 def test_unmeasured_authority_types_and_unknown_fields_refused(self):
  for key in ('all_thirteen_families_complete','restoration_authorized','derived_metadata_is_authority','automatic_legacy_backfill','retained_metadata_privilege_DDL_lease'):
   data=template();data['native'][key]=True
   with self.subTest(key=key),self.assertRaises(ValueError):self.validate(data)
  for value in (True,'45',44):
   data=template();data['check_count']=value
   with self.assertRaises(ValueError):self.validate(data)
  for target in ('root','native'):
   data=template();(data if target=='root' else data['native'])['raw_secret']='SYNTHETIC_PRIVATE_SENTINEL'
   with self.assertRaises(ValueError):self.validate(data)
 def test_source_binding_and_hashes_cannot_be_omitted(self):
  data=template();data['native']['source_revision']='b'*40
  with self.assertRaises(ValueError):self.validate(data)
  data=template();del data['source_restore']['patch_audit']['migration_source_hashes']['phabricator:20261104.people.organizationparticipants.sql']
  with self.assertRaises(ValueError):self.validate(data)
  data=template();data['captured_native_output_sha256']='not-a-hash'
  with self.assertRaises(ValueError):self.validate(data)
  for rev in (None,True,'0'*39):
   with self.assertRaises(ValueError):gate.validate(template(),source_revision=rev)
if __name__=='__main__':unittest.main()
