#!/usr/bin/env python3
"""Strict bounded policy-isolation component evidence; never a recovery permit."""
import argparse,hashlib,json,re
from pathlib import Path
import check_completion as completion
ROOT=Path(__file__).resolve().parents[2]
BOOTSTRAP={'full-current-source-quickstart-and-patch-ledger-restored',
 'completion-reader-real-select-only-grants-and-lineage-proved',
 'completion-writer-forbidden-privilege-denied','completion-writer-cannot-modify-ownership-marker',
 'completion-reader-forbidden-privilege-denied','completion-reader-cannot-modify-ownership-marker'}
SQL={'recovery-policy-only-owned-rr-native-rows-deleted-and-committed-before-rc', 'recovery-policy-caller-rollback-restores-real-native-source-bytes', 'recovery-policy-real-service-rc-empty-source-change-refused-with-producer-commit-retained', 'recovery-policy-current-locked-mutation-changes-source-digest', 'recovery-policy-real-populated-primary-range-blocks-native-dao-insert-until-rollback', 'recovery-policy-real-longtext-budget-refused-with-caller-transaction-preserved', 'recovery-policy-real-empty-primary-gap-blocks-native-dao-insert-until-commit', 'recovery-policy-rollback-releases-native-producer-without-restoration', 'recovery-policy-next-transaction-rr-does-not-change-session-default', 'recovery-policy-real-service-rc-populated-source-change-refused-with-producer-commit-retained', 'recovery-policy-real-native-dao-row-captured-without-completion', 'recovery-policy-real-service-rc-populated-range-allows-native-commit-and-current-read-change', 'recovery-policy-real-service-rc-empty-range-allows-native-commit-and-current-read-change', 'recovery-policy-commit-releases-native-producer-without-restoration'}
CLEANUP={'owned-native-write-guard-disposed','owned-writer-environment-and-cache-restored',
 'all-owned-full-native-schemas-and-principals-cleaned'}
def exact(actual,expected):completion.exact(actual,expected,'policy isolation evidence refused')
def validate(data,root=ROOT,*,source_revision):
 if not isinstance(source_revision,str) or not re.fullmatch('[a-f0-9]{40}',source_revision):raise ValueError('source revision required')
 completion.fields(data,{'batch','captured_native_output_bytes','captured_native_output_sha256','check_count','checks','cleanup',
  'cleanup_failures','failure','file_service_cleanup','isolated_optional_features_enabled','native','native_permission_axes_changed',
  'optional_source_reader_adapter_exercised','passed','php_version','production_deployment','production_executor',
  'production_optional_features_enabled','remaining_blocked','scope','source_restore','successful_owned_file_service_startups',
  'terminal_failure_cleanup_rollbacks','worker_cleanup'},'policy isolation shape refused')
 for key,value in {'batch':'native-recovery-policy-range-component','scope':'owned-full-native-source-synthetic-accounts',
   'passed':True,'php_version':'8.5.12','check_count':23,'failure':None,'cleanup_failures':[],
   'optional_source_reader_adapter_exercised':True,'isolated_optional_features_enabled':True,
   'file_service_cleanup':None,'successful_owned_file_service_startups':0,'terminal_failure_cleanup_rollbacks':0,
   'production_deployment':False,'production_executor':False,'production_optional_features_enabled':False,
   'native_permission_axes_changed':False}.items():exact(data[key],value)
 checks=data['checks']
 if type(checks)is not list or len(checks)!=23 or any(type(v)is not str for v in checks) or len(set(checks))!=23 or set(checks)!=BOOTSTRAP|SQL|CLEANUP:
  raise ValueError('policy isolation obligations refused')
 exact(data['cleanup'],{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True})
 exact(data['worker_cleanup'],{'private_directories_removed':4,'owned_processes_stopped':0,'cleanup_failures':0,
  'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':4})
 exact(data['native'],{'profile':'isolated-native-policy-isolation-comparison-v2','source_revision':source_revision,
  'actual_native_policy_dao':True,'service_owned_rr_transaction':False,'fixture_owned_rr_transaction':True,
  'service_rc_producer_commits_and_current_read_changes':True,'service_rc_absence_fence_proved':False,'fixture_refuses_observed_rc_source_changes':True,
  'actual_empty_and_populated_primary_insert_waits':True,'registered_policy_workers':4,
  'policy_source_SQL_obligations':14,'identified_fixture_seed_restored':True,'all_mode_native_evidence':False,
  'full_storage_upgrade_acceptance':False,'all_thirteen_families_complete':False,'restoration_authorized':False,
  'production_scale_ready':False,'HTTP_controller_evidence':False})
 patches=completion.PATCHES
 if len(patches)!=38 or len(set(patches))!=38:raise ValueError('source migration profile refused')
 hashes={'phabricator:'+name:hashlib.sha256((root/'resources/sql/autopatches'/name).read_bytes()).hexdigest()for name in patches}
 exact(data['source_restore'],{'quickstart_sha256':hashlib.sha256((root/'resources/sql/quickstart.sql').read_bytes()).hexdigest(),
  'app_schema_count':54,'owned_schema_count':55,'quickstart_source_table_count':500,'current_table_count':549,
  'restore_phase_workers_started':0,'restore_phase_network_endpoints_configured':0,
  'patch_audit':{'source_patch_count':1575,'applied_patch_count':1575,'pending_patch_keys':[],
   'current_patch_ledger_complete':True,'migration_source_hashes':hashes,'migrations_executed':38}})
 if type(data['captured_native_output_bytes'])is not int or data['captured_native_output_bytes']<0:raise ValueError('output metadata refused')
 if not completion.digest(data['captured_native_output_sha256']):raise ValueError('output digest refused')
 exact(data['remaining_blocked'],['media-http-and-cookie-integration','file-object-guards','notification-producers','global-governance-completion'])
 return {'scope':'policy-service-RC-and-fixture-RR-comparison-only','passed':True,'source_revision':source_revision,
  'checks':23,'SQL_obligations':14,'registered_policy_workers':4,'service_owned_rr_transaction':False,
  'service_rc_absence_fence_proved':False,'all_thirteen_families_complete':False,'restoration_authorized':False,
  'global_completion':False,'deployment':False,'HTTP_proved':False}

def main():
 parser=argparse.ArgumentParser();parser.add_argument('--receipt',required=True,type=Path);parser.add_argument('--source-revision',required=True)
 args=parser.parse_args();data=json.loads(args.receipt.read_text(),object_pairs_hook=completion.strict_object,parse_constant=lambda _:(_ for _ in()).throw(ValueError("nonfinite")))
 print(json.dumps(validate(data,source_revision=args.source_revision),sort_keys=True))
if __name__=='__main__':main()
