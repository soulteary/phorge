"""Strict bounded Space source fixture; never a policy or lease."""
import argparse, json, re
from pathlib import Path
import check_completion as completion
import check_native_organization as native
ROOT=Path(__file__).resolve().parents[2]
BOOTSTRAP={'full-current-source-quickstart-and-patch-ledger-restored',
 'completion-reader-real-select-only-grants-and-lineage-proved',
 'completion-writer-forbidden-privilege-denied','completion-writer-cannot-modify-ownership-marker',
 'completion-reader-forbidden-privilege-denied','completion-reader-cannot-modify-ownership-marker'}
SQL={'recovery-spaces-qualified-current-row-and-real-query-cache-facts', 'recovery-spaces-real-restricted-policy-nonadmin-denied', 'recovery-spaces-owned-missing-default-read-refused-and-rollback', 'recovery-spaces-native-editor-and-nonadmin-view-positive', 'recovery-spaces-archived-all-space-policy-versus-active-ui-list', 'recovery-spaces-owned-source-change-refused-terminal-and-rollback', 'recovery-spaces-real-native-editor-description-and-history-rollback', 'recovery-spaces-restored-current-facts-after-all-rollback-controls', 'recovery-spaces-real-nonadmin-native-login-before-guard'}
CLEANUP={'owned-native-write-guard-disposed','owned-writer-environment-and-cache-restored',
 'all-owned-full-native-schemas-and-principals-cleaned'}
def exact(actual,expected):completion.exact(actual,expected,'spaces source evidence refused')
def summary(revision):
 return {'profile':'native-spaces-source-facts-v1','source_revision':revision,
  'native_Editor_and_nonadmin_session_VIEW':True,'native_archive_and_active_list_distinct':True,
  'native_editor_rollback_independently_observed':True,'owned_raw_source_change_and_missing_default_refused':True,
  'query_cache_is_policy_attestation':False,'history_complete':False,'absence_or_producer_lease_proved':False,
  'complete':False,'all_sources_complete':False,'restoration_authorized':False}
def source_obligations(root):
 text=(Path(root)/'support/governance/recovery/spaces-source-focused.php').read_text()
 ids=re.findall(r"'(recovery-spaces-[a-z0-9-]+)'\);",text)
 if len(ids)!=9 or len(set(ids))!=9 or set(ids)!=SQL:raise ValueError('Space source obligations drift')
 return set(ids)

def validate(data,root=ROOT,*,source_revision):
 if type(source_revision)is not str or not re.fullmatch('[a-f0-9]{40}',source_revision):raise ValueError('source revision required')
 completion.fields(data,native.FIELDS,'spaces source evidence shape refused')
 for key,value in {'batch':'native-spaces-source-facts','scope':'owned-native-spaces-source-facts-no-lease',
  'passed':True,'php_version':'8.5.12','check_count':18,'failure':None,'cleanup_failures':[],
  'optional_source_reader_adapter_exercised':True,'isolated_optional_features_enabled':True,
  'file_service_cleanup':None,'successful_owned_file_service_startups':0,'terminal_failure_cleanup_rollbacks':0,
  'production_deployment':False,'production_executor':False,'production_optional_features_enabled':False,
  'native_permission_axes_changed':False}.items():exact(data[key],value)
 checks=data['checks']
 if type(checks)is not list or len(checks)!=18 or any(type(x)is not str for x in checks) or len(set(checks))!=18 or set(checks)!=BOOTSTRAP|source_obligations(root)|CLEANUP:raise ValueError('spaces source obligations refused')
 exact(data['native'],summary(source_revision))
 exact(data['worker_cleanup'],{'private_directories_removed':0,'owned_processes_stopped':0,'cleanup_failures':0,
  'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':0})
 exact(data['cleanup'],{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True})
 native.validate_restore(data['source_restore'],root)
 exact(data['remaining_blocked'],native.BLOCKERS)
 if type(data['captured_native_output_bytes'])is not int or not 0<=data['captured_native_output_bytes']<=65536:raise ValueError('output budget refused')
 if not completion.digest(data['captured_native_output_sha256']):raise ValueError('output hash refused')
 return {'passed':True,'scope':'selected-spaces-source-facts-no-lease','source_revision':source_revision,
  'checks':18,'SQL_obligations':9,'native_policy_interpreter_proved':False,'complete':False,
  'continuous_lease_or_ABA_proved':False,'all_sources_complete':False,'restoration_authorized':False}
def main():
 p=argparse.ArgumentParser();p.add_argument('--receipt',type=Path,required=True);p.add_argument('--source-revision',required=True)
 a=p.parse_args()
 def nonfinite(value):raise ValueError('nonfinite spaces source evidence refused')
 data=json.loads(a.receipt.read_text(),object_pairs_hook=completion.strict_object,parse_constant=nonfinite)
 print(json.dumps(validate(data,source_revision=a.source_revision),sort_keys=True))
if __name__=='__main__':main()
