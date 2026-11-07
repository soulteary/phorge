"""Describe fixed source-bound Calendar measurement receipts; external execution remains required."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import check_completion as completion
import check_native_organization as native
import check_recovery_partial_composition as shared
import check_recovery_borrowed_session as source_reader

ROOT = Path(__file__).resolve().parents[2]
SOURCE_INPUTS = {'src/applications/calendar/notifications/PhabricatorCalendarNotificationEngine.php': 'f188d0d1e58fcea435853cefa4184a53518e24a69737ec3a7efc069e4413c43d', 'src/applications/calendar/notifications/PhabricatorCalendarEventNotificationView.php': 'd8deff8eca063c9a87d5805a3926580d3bad83d0eaee0b426b730bfc1cb85643', 'src/applications/calendar/storage/PhabricatorCalendarEvent.php': 'e9e7a2c73e61ff54581bd0059f2483ef9a619ac2a06bc68a83ba6bc786fc395c', 'src/applications/calendar/storage/PhabricatorCalendarEventInvitee.php': '7e2d5a0e9c07f355c839345544a6e7dd0bb0176a2f1df172797d4a4ea0207633', 'src/applications/calendar/storage/PhabricatorCalendarNotification.php': '960a2ffcc101669f99cfbbdf0a4c190bbf26e3057b6a1568eac97a521ec2ae95', 'src/applications/calendar/query/PhabricatorCalendarEventQuery.php': '93acdc6451e8a583bf0a8ea07b9f0e7ea396e0de593c6d1eae8225722daa4a16', 'src/applications/metamta/storage/PhabricatorMetaMTAMail.php': '56016d144982aa284ac0afc2fd272f65a785af26d85c502782d45fc3cfa07c28', 'src/infrastructure/governance/notification/PhorgePrivateMailPolicy.php': '67112136ba242de5c8ada4b22ee07958722fcf3fb93b34dabf08bc353364a361', 'support/governance/completion/pipeline-gorge.php': 'ff27390d7a7e5b5c14555758e8b8172fd401bb0dcf51221fe32251b11308fcda', 'support/governance/notification/calendar-reminder-cases.php': '3dfdbd180f2e94b835fecea70154fd5b4f3f52744627814bf754fb9506711dee', 'support/governance/notification/governance-notice-cases.php': '4e5be0c97a039afeea9ddca22cf23478b3b549d2a922d349ee2e5ef7f506f177', 'support/governance/native-organization/test-native-organization-mysql.php': '12f3da8ac1ff4f0eda73200a28247add24066378c9295cb10f23339d6bf589c7', 'support/governance/native-organization/test-calendar-revocation-measurement.php': '12477cc563b9193b88feb72db2db91e64ba0d78084059b56aed3689d5c810e81', 'support/governance/revocation-measurement/calendar-observer.php': 'f8683ffa7d37ca2f836fbb605c9fbf3e9a176fe8051eedeaae0fc451e78c0f69', 'support/governance/revocation-measurement/calendar-cases.php': 'f146b17a1ec435258dd19c71b1f8ad0e9d3cfeef1aa7d16ab24846b2f6150c94', 'support/governance/revocation-measurement/calendar-consumer.py': '64b8a95981689bbe34b3ff82ee3171cd750a64cdba7c5614faffe4d55520679b', 'support/governance/notification/calendar-wire-producer.php': 'bb84e46101b00a627821be0cfd3fa124bde2640f9aedc0e07644b55a820bc443', 'support/governance/native-organization/workers.php': '11df3cffa66837d56ffaf720a65846ed33e4aede6213893ffb5db817436f169d'}
def SQL_obligations(root=ROOT):
    raw=(Path(root)/'support/governance/revocation-measurement/calendar-cases.php').read_text()
    block=raw.split('function governance_calendar_revocation_obligations(){return array(',1)[1].split(');}',1)[0]
    labels=re.findall(r"'([^']+)'",block)
    if len(labels)!=5 or len(set(labels))!=5:raise ValueError('measurement obligations changed')
    return set(labels)
CASES=SQL_obligations()


def exact(actual, expected):
    completion.exact(actual, expected, 'Calendar evidence refused')

def sources(root=ROOT):
    actual = {}
    for path, pin in SOURCE_INPUTS.items():
        raw = source_reader.read_source_checked(Path(root) / path)
        if hashlib.sha256(raw).hexdigest() != pin:
            raise ValueError('Calendar source drift')
        actual[path] = pin
    if len(actual) != 18 or len(CASES) != 5:
        raise ValueError('Calendar reviewed coverage changed')
    return actual

def obligations():
    return shared.BOOTSTRAP | shared.PREPARATION | shared.CLEANUP | CASES | {
        'completion-real-gorge-taskqueue-ready-loopback'}

def summary(revision, queue_digest, root=ROOT):
    return {'profile':'native-calendar-revocation-measurement-v1','queue_cleanup':{'kind':'taskqueue','binary_sha256':queue_digest,'process_stopped':True,'private_storage_removed':True},'measurement_acceptance':False,'SLA_proved':False,'physical_SMTP_proved':False,'convergence_proved':False,'restoration_authorized':False}

def validate(data, *, source_revision, file_digest, queue_digest, root=ROOT):
    if not isinstance(source_revision, str) or not re.fullmatch('[a-f0-9]{40}', source_revision):
        raise ValueError('Calendar source revision required')
    for value in (file_digest, queue_digest):
        if not isinstance(value, str) or not re.fullmatch('[a-f0-9]{64}', value):
            raise ValueError('independent service digest required')
    sources(root)  # Verify pinned descriptor/producer/CLI bytes before importing dynamic consumer.
    completion.fields(data, native.FIELDS, 'Calendar evidence shape refused')
    expected = obligations()
    for key, value in {'batch': 'native-calendar-revocation-measurement-v1',
        'scope': 'owned-native-calendar-revocation-measurement-no-SMTP-v1', 'passed': True,
        'php_version': '8.5.12', 'check_count': len(expected), 'failure': None,
        'cleanup_failures': [], 'terminal_failure_cleanup_rollbacks': 0,
        'optional_source_reader_adapter_exercised': True, 'isolated_optional_features_enabled': True,
        'successful_owned_file_service_startups': 1, 'production_deployment': False,
        'production_executor': False, 'production_optional_features_enabled': False,
        'native_permission_axes_changed': False}.items():
        exact(data[key], value)
    checks = data['checks']
    if type(checks) is not list or len(checks) != len(expected) or any(type(x) is not str for x in checks) or len(set(checks)) != len(checks) or set(checks) != expected:
        raise ValueError('Calendar selected obligations refused')
    measured=data['native']
    if type(measured)is not dict or set(measured)!=set(summary(source_revision,queue_digest))|{'event'}:raise ValueError('measurement summary shape')
    exact({k:v for k,v in measured.items()if k!='event'},summary(source_revision,queue_digest))
    import types
    relative='support/governance/revocation-measurement/calendar-consumer.py'
    module=Path(root)/relative
    raw=source_reader.read_source_checked(module)
    if hashlib.sha256(raw).hexdigest()!=SOURCE_INPUTS[relative]:raise ValueError('measurement descriptor changed')
    consumer=types.ModuleType('calendar_measurement_consumer');consumer.__file__=str(module)
    exec(compile(raw,str(module),'exec',dont_inherit=True),consumer.__dict__)
    descriptor=consumer.describe(measured['event'],source_revision,SOURCE_INPUTS['support/governance/revocation-measurement/calendar-observer.php'])
    if descriptor['postcommit_A_outcomes']!=['denied','denied']or descriptor['postcommit_B_outcome']!='allowed':raise ValueError('current native observation not denied')
    exact(data['file_service_cleanup'], {'kind': 'file', 'binary_sha256': file_digest,
        'process_stopped': True, 'private_storage_removed': True})
    exact(data['worker_cleanup'], {'private_directories_removed': 0, 'owned_processes_stopped': 0,
        'cleanup_failures': 0, 'private_directories_remaining': 0, 'live_processes_after_cleanup': 0,
        'workers_registered': 0})
    exact(data['cleanup'], {'ownedSchemasRemoved': 55, 'ownedRuntimeUsersRemoved': 2, 'readerRemoved': True})
    native.validate_restore(data['source_restore'], Path(root))
    exact(data['remaining_blocked'], native.BLOCKERS)
    if type(data['captured_native_output_bytes']) is not int or not 0 <= data['captured_native_output_bytes'] <= 65536 or not completion.digest(data['captured_native_output_sha256']):
        raise ValueError('Calendar captured-output scope refused')
    return {'passed': True, 'scope': 'native-calendar-measurement-source-receipt-description-only',
        'source_revision': source_revision, 'checks': len(expected), 'calendar_obligations': len(CASES),
        'source_inputs': len(SOURCE_INPUTS), 'physical_SMTP_or_browser_proved': False,
        'native_global_lock_or_daemon_proved': False, 'ephemeral_ghost_delivery_supported': False,
        'all_calendar_producer_paths_complete': False, 'activation_blocked': True, 'measurement_acceptance':False,'producer_attestation':'external-execution-attestation-required','SLA_proved':False}

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--receipt', type=Path, required=True)
    parser.add_argument('--source-revision', required=True)
    for role in ('file', 'queue'):
        parser.add_argument('--' + role + '-binary', type=Path, required=True)
        parser.add_argument('--' + role + '-binary-sha256', required=True)
    args = parser.parse_args()
    try:
        for role in ('file', 'queue'):
            if shared.read_checked(getattr(args, role + '_binary'), binary=True) != getattr(args, role + '_binary_sha256'):
                raise ValueError('independent binary bytes mismatch')
        def nonfinite(_): raise ValueError('nonfinite JSON')
        data = json.loads(shared.read_checked(args.receipt), object_pairs_hook=completion.strict_object, parse_constant=nonfinite)
        result = validate(data, source_revision=args.source_revision,
            file_digest=args.file_binary_sha256, queue_digest=args.queue_binary_sha256)
    except (ValueError, OSError, TypeError, KeyError, UnicodeError):
        raise SystemExit('Calendar receipt refused') from None
    print(json.dumps(result, sort_keys=True))

if __name__ == '__main__':
    main()
