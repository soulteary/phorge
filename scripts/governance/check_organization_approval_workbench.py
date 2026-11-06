"""Strict isolated native controller/service checks; no server/browser acceptance."""
import argparse,json,re
from pathlib import Path
import check_completion as completion
import check_native_organization as native
from check_recovery_file_source import read_receipt
ROOT=Path(__file__).resolve().parents[2]
BOOTSTRAP={'full-current-source-quickstart-and-patch-ledger-restored','completion-reader-real-select-only-grants-and-lineage-proved','completion-writer-forbidden-privilege-denied','completion-writer-cannot-modify-ownership-marker','completion-reader-forbidden-privilege-denied','completion-reader-cannot-modify-ownership-marker'}
CLEANUP={'owned-native-write-guard-disposed','owned-writer-environment-and-cache-restored','all-owned-full-native-schemas-and-principals-cleaned'}
def obligations(root):
 text=(Path(root)/'support/governance/organization/approval-workbench-cases.php').read_text()
 ids=re.findall(r"'(approval-workbench-[a-zA-Z0-9-]+)'\);",text)
 if len(ids)!=len(set(ids)) or len(ids)!=17:raise ValueError('workbench obligation drift')
 return set(ids)
def summary(revision):
 return {'source_revision':revision,'native_sessions_and_current_sysadmin_service':True,'native_controller_objects_exercised':True,'HTTP_server_or_browser_executed':False,'wall_clock_expiry_wait_proved':False,'real_owned_expired_binding_fault_tested':True,'execution_rollback_fault_tested':True,'full_approval_family_complete':False,'all_sources_complete':False,'restoration_authorized':False}
def validate(data,root=ROOT,*,source_revision):
 if type(source_revision)is not str or re.fullmatch('[a-f0-9]{40}',source_revision)is None:raise ValueError('source revision refused')
 completion.fields(data,native.FIELDS,'workbench evidence fields refused')
 for key,value in {'batch':'native-organization-approval-workbench','scope':'owned-native-organization-approval-workbench','passed':True,'php_version':'8.5.12','check_count':26,'failure':None,'cleanup_failures':[],'optional_source_reader_adapter_exercised':True,'isolated_optional_features_enabled':True,'successful_owned_file_service_startups':0,'file_service_cleanup':None,'terminal_failure_cleanup_rollbacks':0,'production_deployment':False,'production_executor':False,'production_optional_features_enabled':False,'native_permission_axes_changed':False}.items():completion.exact(data[key],value,'workbench typed fact refused')
 checks=data['checks']
 if type(checks)is not list or any(type(x)is not str for x in checks)or len(checks)!=len(set(checks))or set(checks)!=BOOTSTRAP|obligations(root)|CLEANUP:raise ValueError('workbench checks refused')
 completion.exact(data['native'],summary(source_revision),'workbench summary refused')
 completion.exact(data['cleanup'],{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True},'workbench cleanup refused')
 completion.exact(data['worker_cleanup'],{'private_directories_removed':0,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':0},'workbench worker cleanup refused')
 native.validate_restore(data['source_restore'],root);completion.exact(data['remaining_blocked'],native.BLOCKERS,'workbench blockers refused')
 if type(data['captured_native_output_bytes'])is not int or not 0<=data['captured_native_output_bytes']<=65536 or not completion.digest(data['captured_native_output_sha256']):raise ValueError('workbench output budget refused')
 return {'passed':True,'scope':'isolated-native-approval-workbench-controller-service','source_revision':source_revision,'checks':26,'HTTP_server_or_browser_executed':False,'full_approval_family_complete':False,'all_sources_complete':False,'restoration_authorized':False}
def main():
 p=argparse.ArgumentParser();p.add_argument('--receipt',required=True,type=Path);p.add_argument('--source-revision',required=True);a=p.parse_args()
 try:
  data=json.loads(read_receipt(a.receipt),object_pairs_hook=completion.strict_object,parse_constant=lambda _:(_ for _ in ()).throw(ValueError('nonfinite')))
  result=validate(data,source_revision=a.source_revision)
 except(OSError,ValueError,TypeError,KeyError,UnicodeError):raise SystemExit('Workbench receipt refused')from None
 print(json.dumps(result,sort_keys=True))
if __name__=='__main__':main()
