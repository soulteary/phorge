"""Focused experimental fixture receipt gate; never an authority or deadlock claim."""
import argparse
import importlib.util
import json
from pathlib import Path
import re
import sys
sys.dont_write_bytecode = True

ROOT = Path(__file__).resolve().parents[2]
PROFILES = ('api', 'cli', 'oauth')
OPERATIONS = ('insert', 'revoke')
BOUNDARIES = ('commit', 'rollback')
FIELDS = {'batch', 'passed', 'scope', 'checks', 'check_count', 'php_version',
    'source_restore', 'native', 'worker_cleanup', 'file_service_cleanup',
    'successful_owned_file_service_startups', 'cleanup', 'failure', 'cleanup_failures',
    'optional_source_reader_adapter_exercised', 'isolated_optional_features_enabled',
    'terminal_failure_cleanup_rollbacks', 'captured_native_output_bytes',
    'captured_native_output_sha256', 'production_deployment', 'production_executor',
    'production_optional_features_enabled', 'native_permission_axes_changed', 'remaining_blocked'}
BLOCKERS = ['api-two-producer-deadlock-stress', 'oauth-governance-current-reader',
    'all-thirteen-source-families', 'restoration-permit', 'production-deployment']
WORKER_FIELDS = {'workers_registered', 'private_directories_removed',
    'private_directories_remaining', 'live_processes_after_cleanup',
    'cleanup_failures', 'owned_processes_stopped'}

def strict_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('duplicate credential evidence field')
        result[key] = value
    return result

def load_json(raw):
    def nonfinite(value):
        raise ValueError('nonfinite credential evidence refused')
    return json.loads(raw, object_pairs_hook=strict_object, parse_constant=nonfinite)

def exact(actual, wanted):
    if type(actual) is not type(wanted):
        raise ValueError('credential fixture typed value refused')
    if type(wanted) is dict:
        if set(actual) != set(wanted): raise ValueError('credential fixture keys refused')
        for key in wanted: exact(actual[key], wanted[key])
    elif type(wanted) is list:
        if len(actual) != len(wanted): raise ValueError('credential fixture length refused')
        for left, right in zip(actual, wanted): exact(left, right)
    elif actual != wanted:
        raise ValueError('credential fixture typed value refused')

def required_checks():
    return {
        'full-current-source-quickstart-and-patch-ledger-restored',
        'completion-reader-real-select-only-grants-and-lineage-proved',
        'completion-writer-forbidden-privilege-denied',
        'completion-writer-cannot-modify-ownership-marker',
        'completion-reader-forbidden-privilege-denied',
        'completion-reader-cannot-modify-ownership-marker',
        'completion-real-gorge-file-ready-loopback',
        'owned-native-write-guard-disposed',
        'owned-writer-environment-and-cache-restored',
        'all-owned-full-native-schemas-and-principals-cleaned',
    } | {'recovery-api-concurrency-'+p+'-'+o+'-'+b+'-'+suffix
         for p in PROFILES for o in OPERATIONS for b in BOUNDARIES
         for suffix in ('native-auth-baseline', 'bounded-native-observation', 'fresh-native-postcondition')}

def validate(data, revision, file_digest, root=ROOT):
    if not re.fullmatch('[a-f0-9]{40}', revision) or not re.fullmatch('[a-f0-9]{64}', file_digest):
        raise ValueError('independent source and binary inputs required')
    if type(data) is not dict or set(data) != FIELDS:
        raise ValueError('credential receipt fields refused')
    exact(data['remaining_blocked'], BLOCKERS)
    exact(data['optional_source_reader_adapter_exercised'], True)
    exact(data['isolated_optional_features_enabled'], True)
    exact(data['terminal_failure_cleanup_rollbacks'], 0)
    exact(data['successful_owned_file_service_startups'], 1)
    if type(data['worker_cleanup']) is not dict or set(data['worker_cleanup']) != WORKER_FIELDS:
        raise ValueError('credential worker fields refused')
    exact(data['batch'], 'native-api-credential-concurrency')
    exact(data['scope'], 'owned-native-credential-writer-reader-observation')
    exact(data['passed'], True)
    exact(data['php_version'], '8.5.12')
    exact(data['failure'], None)
    exact(data['cleanup_failures'], [])
    for key in ('production_deployment','production_executor','production_optional_features_enabled','native_permission_axes_changed'):
        exact(data[key], False)
    checks = data['checks']
    if type(checks) is not list or any(type(x) is not str for x in checks) or len(checks) != len(set(checks)) or set(checks) != required_checks():
        raise ValueError('credential fixture check coverage refused')
    exact(data['check_count'], 46)
    exact(data['cleanup'], {'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True})
    for key, wanted in {'workers_registered':12,'private_directories_removed':12,'private_directories_remaining':0,'live_processes_after_cleanup':0,'cleanup_failures':0,'owned_processes_stopped':0}.items():
        exact(data['worker_cleanup'][key], wanted)
    exact(data['file_service_cleanup'], {'kind':'file','binary_sha256':file_digest,'process_stopped':True,'private_storage_removed':True})
    spec = importlib.util.spec_from_file_location('native_credential_restore_gate', root/'scripts/governance/check_native_organization.py')
    native = importlib.util.module_from_spec(spec); spec.loader.exec_module(native)
    native.validate_restore(data['source_restore'], root)
    summary=data['native']
    if type(summary) is not dict or set(summary) != {'profile_version','source_revision','observations','attempts','actual_current_reader_profiles','oauth_native_authentication_only','api_token_scan_deadlock_proven','production_query_changed','all_sources_complete','can_execute'}:
        raise ValueError('credential summary fields refused')
    for key, value in {'profile_version':1,'source_revision':revision,'attempts':12,'actual_current_reader_profiles':['api','cli'],'oauth_native_authentication_only':True,'api_token_scan_deadlock_proven':False,'production_query_changed':False,'all_sources_complete':False,'can_execute':False}.items():
        exact(summary[key], value)
    rows=summary['observations']
    if type(rows) is not list or len(rows) != 12:
        raise ValueError('credential observations refused')
    wanted={(p,o,b) for p in PROFILES for o in OPERATIONS for b in BOUNDARIES}; seen=set()
    for row in rows:
        if type(row) is not dict or set(row) != {'profile','operation','boundary','observation','reader_allowed','fresh_auth_allowed'}:
            raise ValueError('credential observation shape refused')
        key=(row['profile'], row['operation'], row['boundary'])
        if key not in wanted or key in seen:
            raise ValueError('credential observation identity refused')
        seen.add(key)
        observed=row['observation']
        if type(observed) is not dict or set(observed) != {'state','exact_requester_and_blocker'}:
            raise ValueError('credential lock observation refused')
        if observed['state'] not in ('completed-before-release','blocked-by-owned-writer'):
            raise ValueError('credential lock state refused')
        exact(observed['exact_requester_and_blocker'], observed['state']=='blocked-by-owned-writer')
        after = not (row['operation']=='revoke' and row['boundary']=='commit')
        exact(row['fresh_auth_allowed'], after)
        if type(row['reader_allowed']) is not bool:
            raise ValueError('credential native outcome refused')
        if row['profile'] != 'oauth':
            exact(row['reader_allowed'], after)
    if type(data['captured_native_output_bytes']) is not int or not 0 <= data['captured_native_output_bytes'] <= 65536:
        raise ValueError('credential output budget refused')
    if type(data['captured_native_output_sha256']) is not str or not re.fullmatch('[a-f0-9]{64}', data['captured_native_output_sha256']):
        raise ValueError('credential unexpected output refused')
    return {'accepted':True,'check_count':46,'native_writer_reader_attempts':12,'source_revision':revision,
            'deadlock_proven':False,'all_sources_complete':False,'can_execute':False,'HTTP_proved':False,'deployment_proved':False}

if __name__ == '__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--receipt',type=Path,required=True)
    parser.add_argument('--source-revision',required=True)
    parser.add_argument('--file-binary',type=Path,required=True)
    args=parser.parse_args()
    digest=__import__('hashlib').sha256(args.file_binary.read_bytes()).hexdigest()
    print(json.dumps(validate(load_json(args.receipt.read_text()), args.source_revision, digest),sort_keys=True))
