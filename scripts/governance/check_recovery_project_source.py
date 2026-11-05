"""Strict bounded Project relationship source fixture; never a policy or lease."""
import argparse, json, re
from pathlib import Path
import check_completion as completion
import check_native_organization as native
ROOT=Path(__file__).resolve().parents[2]
BOOTSTRAP={'full-current-source-quickstart-and-patch-ledger-restored',
 'completion-reader-real-select-only-grants-and-lineage-proved',
 'completion-writer-forbidden-privilege-denied','completion-writer-cannot-modify-ownership-marker',
 'completion-reader-forbidden-privilege-denied','completion-reader-cannot-modify-ownership-marker'}
SQL={'recovery-project-real-editor-hierarchy-query-and-native-materialization',
 'recovery-project-qualified-current-source-and-private-redacted-facts'}
CLEANUP={'owned-native-write-guard-disposed','owned-writer-environment-and-cache-restored',
 'all-owned-full-native-schemas-and-principals-cleaned'}
def exact(actual,expected):completion.exact(actual,expected,'project source evidence refused')
def summary(revision):
 return {'profile':'native-project-source-facts-v1','source_revision':revision,
  'real_native_ProjectEditor_EdgeEditor_IndexEngine':True,'native_ProjectQuery_comparison':True,
  'selected_history_is_current_membership':False,'SQL_absence_or_producer_lease':False,
  'complete':False,'all_sources_complete':False,'restoration_authorized':False}
def validate(data,root=ROOT,*,source_revision):
 if type(source_revision)is not str or not re.fullmatch('[a-f0-9]{40}',source_revision):raise ValueError('source revision required')
 completion.fields(data,native.FIELDS,'project source evidence shape refused')
 for key,value in {'batch':'native-project-source-facts','scope':'owned-native-project-source-facts-no-lease',
  'passed':True,'php_version':'8.5.12','check_count':11,'failure':None,'cleanup_failures':[],
  'optional_source_reader_adapter_exercised':True,'isolated_optional_features_enabled':True,
  'file_service_cleanup':None,'successful_owned_file_service_startups':0,'terminal_failure_cleanup_rollbacks':0,
  'production_deployment':False,'production_executor':False,'production_optional_features_enabled':False,
  'native_permission_axes_changed':False}.items():exact(data[key],value)
 checks=data['checks']
 if type(checks)is not list or len(checks)!=11 or any(type(x)is not str for x in checks) or len(set(checks))!=11 or set(checks)!=BOOTSTRAP|SQL|CLEANUP:raise ValueError('project source obligations refused')
 exact(data['native'],summary(source_revision))
 exact(data['worker_cleanup'],{'private_directories_removed':0,'owned_processes_stopped':0,'cleanup_failures':0,
  'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':0})
 exact(data['cleanup'],{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True})
 native.validate_restore(data['source_restore'],root)
 exact(data['remaining_blocked'],native.BLOCKERS)
 if type(data['captured_native_output_bytes'])is not int or not 0<=data['captured_native_output_bytes']<=65536:raise ValueError('output budget refused')
 if not completion.digest(data['captured_native_output_sha256']):raise ValueError('output hash refused')
 return {'passed':True,'scope':'selected-project-source-facts-no-lease','source_revision':source_revision,
  'checks':11,'SQL_obligations':2,'native_policy_interpreter_proved':False,'complete':False,
  'continuous_lease_or_ABA_proved':False,'all_sources_complete':False,'restoration_authorized':False}
def main():
 p=argparse.ArgumentParser();p.add_argument('--receipt',type=Path,required=True);p.add_argument('--source-revision',required=True)
 a=p.parse_args()
 def nonfinite(value):raise ValueError('nonfinite project source evidence refused')
 data=json.loads(a.receipt.read_text(),object_pairs_hook=completion.strict_object,parse_constant=nonfinite)
 print(json.dumps(validate(data,source_revision=a.source_revision),sort_keys=True))
if __name__=='__main__':main()
