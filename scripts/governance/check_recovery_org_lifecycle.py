#!/usr/bin/env python3
"""Strict bounded lifecycle component evidence; never a recovery permit."""
import argparse,hashlib,json,re
from pathlib import Path
import check_completion as completion
ROOT=Path(__file__).resolve().parents[2]
BOOTSTRAP={'full-current-source-quickstart-and-patch-ledger-restored',
 'completion-reader-real-select-only-grants-and-lineage-proved',
 'completion-writer-forbidden-privilege-denied','completion-writer-cannot-modify-ownership-marker',
 'completion-reader-forbidden-privilege-denied','completion-reader-cannot-modify-ownership-marker'}
SQL={
 'recovery-org-approval-audit-fault-rolls-back-review-and-generation',
 'recovery-org-current-lifecycle-source-captures-real-approval-without-claiming-all-sources',
 'recovery-org-handover-approval-advances-one-generation',
 'recovery-org-handover-approval-audit-fault-rolls-back-domain-and-generation',
 'recovery-org-handover-approval-replay-preserves-generation',
 'recovery-org-handover-execution-audit-fault-rolls-back-domain-and-generation',
 'recovery-org-handover-execution-audit-record-advances-once',
 'recovery-org-handover-execution-replay-preserves-generation',
 'recovery-org-handover-proposal-advances-one-generation',
 'recovery-org-handover-proposal-audit-fault-rolls-back-domain-and-generation',
 'recovery-org-handover-proposal-replay-preserves-generation',
 'recovery-org-isolated-known-binding-backfill-preserves-every-ticket-byte-and-no-authority',
 'recovery-org-proposal-audit-fault-rolls-back-ticket-and-generation',
 'recovery-org-real-approval-replay-preserves-generation',
 'recovery-org-real-independent-approval-advances-one-generation',
 'recovery-org-real-legacy-unindexed-row-hard-blocks-current-absence',
 'recovery-org-real-oversized-binding-refused-before-payload-decode',
 'recovery-org-real-proposal-persists-exact-derived-participants-and-one-generation',
 'recovery-org-real-proposal-replay-preserves-generation',
 'recovery-org-real-service-proposal-after-parent-commit-commits-one-new-source-generation',
 'recovery-org-real-service-proposal-after-parent-rollback-commits-one-new-source-generation',
 'recovery-org-real-service-proposal-waits-current-source-until-parent-commit',
 'recovery-org-real-service-proposal-waits-current-source-until-parent-rollback',
 'recovery-org-real-uint64-exhaustion-rolls-back-proposal-without-wrap',
 'recovery-org-service-audit-record-advances-one-generation-not-two',
 'recovery-org-service-operation-replay-does-not-advance-generation',
 'recovery-org-tampered-derived-role-refused-by-native-load-and-current-source',
 'recovery-org-transfer-approval-advances-one-generation',
 'recovery-org-transfer-approval-audit-fault-rolls-back-domain-and-generation',
 'recovery-org-transfer-approval-replay-preserves-generation',
 'recovery-org-transfer-execution-audit-fault-rolls-back-domain-and-generation',
 'recovery-org-transfer-execution-audit-record-advances-once',
 'recovery-org-transfer-execution-replay-preserves-generation',
 'recovery-org-transfer-proposal-advances-one-generation',
 'recovery-org-transfer-proposal-audit-fault-rolls-back-domain-and-generation',
 'recovery-org-transfer-proposal-replay-preserves-generation',
}
CLEANUP={'owned-native-write-guard-disposed','owned-writer-environment-and-cache-restored',
 'all-owned-full-native-schemas-and-principals-cleaned'}
def exact(actual,expected):completion.exact(actual,expected,'metadata evidence refused')
def validate(data,root=ROOT,*,source_revision):
 if not isinstance(source_revision,str) or not re.fullmatch('[a-f0-9]{40}',source_revision):raise ValueError('source revision required')
 completion.fields(data,{'batch','captured_native_output_bytes','captured_native_output_sha256','check_count','checks','cleanup',
  'cleanup_failures','failure','file_service_cleanup','isolated_optional_features_enabled','native','native_permission_axes_changed',
  'optional_source_reader_adapter_exercised','passed','php_version','production_deployment','production_executor',
  'production_optional_features_enabled','remaining_blocked','scope','source_restore','successful_owned_file_service_startups',
  'terminal_failure_cleanup_rollbacks','worker_cleanup'},'metadata shape refused')
 for key,value in {'batch':'native-recovery-organization-lifecycle-component','scope':'owned-full-native-source-synthetic-accounts',
   'passed':True,'php_version':'8.5.12','check_count':45,'failure':None,'cleanup_failures':[],
   'optional_source_reader_adapter_exercised':True,'isolated_optional_features_enabled':True,
   'file_service_cleanup':None,'successful_owned_file_service_startups':0,'terminal_failure_cleanup_rollbacks':0,
   'production_deployment':False,'production_executor':False,'production_optional_features_enabled':False,
   'native_permission_axes_changed':False}.items():exact(data[key],value)
 checks=data['checks']
 if type(checks)is not list or len(checks)!=45 or any(type(v)is not str for v in checks) or len(set(checks))!=45 or set(checks)!=BOOTSTRAP|SQL|CLEANUP:
  raise ValueError('metadata obligations refused')
 exact(data['cleanup'],{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True})
 exact(data['worker_cleanup'],{'private_directories_removed':2,'owned_processes_stopped':0,'cleanup_failures':0,
  'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':2})
 exact(data['native'],{'profile':'isolated-indexed-organization-lifecycle-generation-v1','source_revision':source_revision,
  'actual_service_proposal_approval_success_rollback_replay':True,
  'actual_transfer_handover_success_rollback_replay':True,'SQL_obligations':36,
  'derived_metadata_is_authority':False,'automatic_legacy_backfill':False,
  'retained_metadata_privilege_DDL_lease':False,'producer_concurrency_proved':True,
  'organization_lifecycle_readset_complete':False,'all_thirteen_families_complete':False,
  'restoration_authorized':False,'HTTP_controller_evidence':False})
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
 return {'scope':'indexed-organization-lifecycle-generation-component-only','passed':True,'source_revision':source_revision,
  'checks':45,'SQL_obligations':36,'all_thirteen_families_complete':False,'restoration_authorized':False,
  'producer_concurrency_proved':True,'global_completion':False,'deployment':False,'HTTP_proved':False}

def main():
 parser=argparse.ArgumentParser();parser.add_argument('--receipt',required=True,type=Path);parser.add_argument('--source-revision',required=True)
 args=parser.parse_args();data=json.loads(args.receipt.read_text(),object_pairs_hook=completion.strict_object)
 print(json.dumps(validate(data,source_revision=args.source_revision),sort_keys=True))
if __name__=='__main__':main()
