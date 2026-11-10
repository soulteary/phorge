#!/usr/bin/env python3
"""Strict same-native-User RR/MDL component evidence; never a recovery permit."""
import argparse,hashlib,json,re
from pathlib import Path
import check_completion as completion
ROOT=Path(__file__).resolve().parents[2]
BOOTSTRAP={'full-current-source-quickstart-and-patch-ledger-restored',
 'completion-reader-real-select-only-grants-and-lineage-proved',
 'completion-writer-forbidden-privilege-denied','completion-writer-cannot-modify-ownership-marker',
 'completion-reader-forbidden-privilege-denied','completion-reader-cannot-modify-ownership-marker'}
SQL={f'native-rr-mdl-{ending}-{suffix}' for ending in ('commit','rollback') for suffix in (
 'actual-native-writer-RR-marker-uncommitted',
 'real-revoke-waits-unique-same-native-transaction',
 'same-native-private-identity-before-own-termination',
 'native-scope-terminal-outcome-observed',
 'same-commit-or-rollback-publishes-data-and-releases-MDL',
 'owned-child-and-exact-SQL-sessions-clean')}
CLEANUP={'owned-native-write-guard-disposed','owned-writer-environment-and-cache-restored',
 'all-owned-full-native-schemas-and-principals-cleaned'}
def exact(actual,expected):completion.exact(actual,expected,'native RR evidence refused')
def validate(data,root=ROOT,*,source_revision):
 if not isinstance(source_revision,str) or not re.fullmatch('[a-f0-9]{40}',source_revision):raise ValueError('source revision required')
 completion.fields(data,{'batch','captured_native_output_bytes','captured_native_output_sha256','check_count','checks','cleanup',
  'cleanup_failures','failure','file_service_cleanup','isolated_optional_features_enabled','native','native_permission_axes_changed',
  'optional_source_reader_adapter_exercised','passed','php_version','production_deployment','production_executor',
  'production_optional_features_enabled','remaining_blocked','scope','source_restore','successful_owned_file_service_startups',
  'terminal_failure_cleanup_rollbacks','worker_cleanup'},'native RR shape refused')
 for key,value in {'batch':'native-same-User-RR-MDL-component','scope':'owned-full-native-source-synthetic-accounts',
   'passed':True,'php_version':'8.5.12','check_count':21,'failure':None,'cleanup_failures':[],
   'optional_source_reader_adapter_exercised':True,'isolated_optional_features_enabled':True,
   'file_service_cleanup':None,'successful_owned_file_service_startups':0,'terminal_failure_cleanup_rollbacks':0,
   'production_deployment':False,'production_executor':False,'production_optional_features_enabled':False,
   'native_permission_axes_changed':False}.items():exact(data[key],value)
 checks=data['checks']
 if type(checks)is not list or len(checks)!=21 or any(type(v)is not str for v in checks) or len(set(checks))!=21 or set(checks)!=BOOTSTRAP|SQL|CLEANUP:
  raise ValueError('metadata obligations refused')
 exact(data['cleanup'],{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True})
 exact(data['worker_cleanup'],{'private_directories_removed':0,'owned_processes_stopped':0,'cleanup_failures':0,
  'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':0})
 child={'owned_processes_stopped':True,'owned_SQL_sessions_ended':True,'private_directory_removed':True,'cleanup_proved':True,'pidfd_remaining':0}
 exact(data['native'],{'profile':'isolated-same-native-User-writer-RR-MDL-v1','source_revision':source_revision,
  'component_SQL_obligations':12,'local_children':2,'local_cleanup':[child,child],
  'innocuous_native_User_write':True,'native_enable_executed':False,
  'minimal_grants_negative_SQL_complete':False,'unknown_transport_fault_matrix_complete':False,
  'production_companion_fence_installed':False,'role_proxy_dynamic_privileges_complete':False,
  'all13_complete':False,'restoration_authorized':False,
  'final_owned_cleanup':{'cleanup_proved':True,'local_children_registered':2,'failures':[],
   'revoker_closed':True,'principal_cleanup':{'observer_removed':True,'owned_observer_sessions_absent':True,'cleanup_proved':True}}})
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
 return {'scope':'same-native-User-writer-RR-MDL-component-only','passed':True,'source_revision':source_revision,
  'checks':21,'SQL_obligations':12,'native_children':2,'minimal_grants_negatives_proved':False,
  'transport_fault_matrix_complete':False,'production_companion_fence_installed':False,
  'all_thirteen_families_complete':False,'restoration_authorized':False,'global_completion':False,
  'deployment':False,'HTTP_proved':False}

def main():
 parser=argparse.ArgumentParser();parser.add_argument('--receipt',required=True,type=Path);parser.add_argument('--source-revision',required=True)
 args=parser.parse_args();data=json.loads(args.receipt.read_text(),object_pairs_hook=completion.strict_object)
 print(json.dumps(validate(data,source_revision=args.source_revision),sort_keys=True))
if __name__=='__main__':main()
