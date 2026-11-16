"""Accept selected real native Calendar SQL evidence, never physical mail proof."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import check_completion as completion
import check_native_organization as native
import check_recovery_partial_composition as shared
import check_recovery_borrowed_session as source_reader
import recovery_conduit_source_delta as conduit_delta

ROOT = Path(__file__).resolve().parents[2]
SOURCE_INPUTS = {'src/applications/calendar/notifications/PhabricatorCalendarNotificationEngine.php': 'f188d0d1e58fcea435853cefa4184a53518e24a69737ec3a7efc069e4413c43d', 'src/applications/calendar/notifications/PhabricatorCalendarEventNotificationView.php': 'd8deff8eca063c9a87d5805a3926580d3bad83d0eaee0b426b730bfc1cb85643', 'src/applications/calendar/storage/PhabricatorCalendarEvent.php': 'e9e7a2c73e61ff54581bd0059f2483ef9a619ac2a06bc68a83ba6bc786fc395c', 'src/applications/calendar/storage/PhabricatorCalendarEventInvitee.php': '7e2d5a0e9c07f355c839345544a6e7dd0bb0176a2f1df172797d4a4ea0207633', 'src/applications/calendar/storage/PhabricatorCalendarNotification.php': '960a2ffcc101669f99cfbbdf0a4c190bbf26e3057b6a1568eac97a521ec2ae95', 'src/applications/calendar/query/PhabricatorCalendarEventQuery.php': '93acdc6451e8a583bf0a8ea07b9f0e7ea396e0de593c6d1eae8225722daa4a16', 'src/applications/metamta/storage/PhabricatorMetaMTAMail.php': '56016d144982aa284ac0afc2fd272f65a785af26d85c502782d45fc3cfa07c28', 'src/infrastructure/governance/notification/PhorgePrivateMailPolicy.php': '67112136ba242de5c8ada4b22ee07958722fcf3fb93b34dabf08bc353364a361', 'support/governance/completion/pipeline-gorge.php': 'ff27390d7a7e5b5c14555758e8b8172fd401bb0dcf51221fe32251b11308fcda', 'support/governance/notification/calendar-reminder-cases.php': '3dfdbd180f2e94b835fecea70154fd5b4f3f52744627814bf754fb9506711dee', 'support/governance/notification/governance-notice-cases.php': '4e5be0c97a039afeea9ddca22cf23478b3b549d2a922d349ee2e5ef7f506f177', 'support/governance/native-organization/test-native-organization-mysql.php': '12f3da8ac1ff4f0eda73200a28247add24066378c9295cb10f23339d6bf589c7'}
CASES = set(['calendar-private-real-queue-ready-no-worker', 'calendar-private-two-real-attending-events-different-view-policies', 'calendar-private-scheduler-persists-two-independent-event-mails', 'calendar-private-stored-mail-events-manually-relay-and-deduplicate-in-real-queue', 'calendar-private-scheduler-native-mark-keys-cover-each-event', 'calendar-private-repeated-scheduler-keeps-mail-and-mark-counts', 'calendar-private-each-original-event-current-query-and-egress-allow', 'calendar-private-persisted-and-wire-payloads-have-no-private-content', 'calendar-private-view-policy-alone-does-not-remove-native-attendee-access', 'calendar-private-uninvited-and-hidden-event-current-query-denied', 'calendar-private-single-event-revocation-denies-only-own-physical-callback', 'calendar-private-restored-view-does-not-revive-quarantined-mail', 'calendar-private-recipient-disable-denies-remaining-physical-callback', 'calendar-private-recipient-reenable-does-not-revive-quarantined-mail', 'calendar-private-disabled-recipient-not-selected-by-native-scheduler', 'calendar-native-off-scheduler-preserves-one-two-event-digest', 'calendar-native-off-repeat-keeps-native-occurrence-marks', 'calendar-native-recurring-parent-and-persisted-child-mark-keys-preserved', 'calendar-native-recurring-scheduler-does-not-resend-same-occurrence', 'calendar-private-unpersisted-ghost-has-no-invented-related-object-authority', 'calendar-private-owned-queue-cleanup'])

CALENDAR_BASE_SOURCE_SHA256 = {'src/applications/calendar/notifications/PhabricatorCalendarNotificationEngine.php': 'aa0c9853cfd288f6ce575e4007fa1d8c6da75b7758e68ff61f72fbb9656951d9', 'support/governance/native-organization/test-native-organization-mysql.php': 'f7f9fd613ec80ce2fd794e392a6e4c7186504f438d5389db993cfa19f4ab078b'}

# Exact reviewed additive source deltas for historical source-closure tests.
# These are not runtime or authority evidence and never exempt other paths.
def reduce_reviewed_calendar_engine(raw):
    name = 'src/applications/calendar/notifications/PhabricatorCalendarNotificationEngine.php'
    if type(raw) is not bytes or hashlib.sha256(raw).hexdigest() != SOURCE_INPUTS[name]:
        raise ValueError('unreviewed Calendar engine delta')
    helper = b"  private function newMailMessages(PhabricatorUser $viewer, array $events) {\n    if (!PhorgePrivateMailPolicy::enabled()) {\n      return array($this->newMailMessage($viewer, $events));\n    }\n\n    // Link-only mail has one related object. Preserve each reminder's own\n    // destination and current VIEW check instead of discarding all but the\n    // first event when the native digest body is removed at private egress.\n    $messages = array();\n    foreach ($events as $event) {\n      $messages[] = $this->newMailMessage($viewer, array($event));\n    }\n    return $messages;\n  }\n\n\n"
    call = b'$mail_list = array_merge(\n          $mail_list,\n          $this->newMailMessages($user, $events));'
    if raw.count(helper) != 1 or raw.count(call) != 1:
        raise ValueError('Calendar engine inverse shape changed')
    reduced = raw.replace(helper, b'', 1).replace(call, b'$mail_list[] = $this->newMailMessage($user, $events);', 1)
    if hashlib.sha256(reduced).hexdigest() != CALENDAR_BASE_SOURCE_SHA256[name]:
        raise ValueError('Calendar engine changes other original bytes')
    return reduced


CALENDAR_WIRE_BASE_DRIVER_SHA256='908ae8660863a531017d900d84e061862f1d9e3ed1781fc302bff4a72774d4f6'

def reduce_reviewed_calendar_wire_driver(raw):
    # Both additive profiles must reduce exactly: Calendar to current main,
    # then Conduit to the unchanged reviewed pre-Conduit Calendar driver.
    return conduit_delta.reduce_driver(raw)


def reduce_reviewed_calendar_driver(raw):
    # Preserve the historical source map after the exact current fixture inverse.
    raw = conduit_delta.reduce_policy_cleanup_driver(raw)
    name = 'support/governance/native-organization/test-native-organization-mysql.php'
    if type(raw) is not bytes or hashlib.sha256(raw).hexdigest() != SOURCE_INPUTS[name]:
        raise ValueError('unreviewed Calendar driver delta')
    raw=conduit_delta.reduce_driver(raw)
    additions = (
        b"  if($phase==='calendar-reminders'&&isset($GLOBALS['governance_calendar_egress_probe'])){\n    $failure['calendar_egress_probe']=$GLOBALS['governance_calendar_egress_probe'];\n    if(($GLOBALS['governance_calendar_attempt']??null)==='calendar-private-each-original-event-current-query-and-egress-allow'){$failure['stage']='calendar-private-each-original-event-current-query-and-egress-allow';}\n  }\n",
        b"'--calendar-reminders'=>'calendar-reminders',",
        b"  }elseif($phase==='calendar-reminders'){\n    require_once dirname(__DIR__).'/completion/pipeline-gorge.php';\n    $stage='owned-real-gorge-file-service';$file_service=governance_completion_start_gorge($fixture,'file',$check);\n    $stack->getStack()[0]->setKeys(array('gorge.file.uri'=>$file_service['uri'],'gorge.file.token'=>$file_service['token']));\n    (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());\n    $stage='focused-native-calendar-reminders';require_once dirname(__DIR__).'/notification/calendar-reminder-cases.php';\n    $native=governance_calendar_reminder_cases($fixture,$check);\n",
        b"$batch=$phase==='calendar-reminders'?'native-private-calendar-reminders':$batch;\n",
        b"$receipt_scope=$phase==='calendar-reminders'?'owned-native-calendar-reminders-no-SMTP':$receipt_scope;\n",
    )
    for addition in additions:
        if raw.count(addition) != 1:
            raise ValueError('Calendar driver inverse shape changed')
        raw = raw.replace(addition, b'', 1)
    if hashlib.sha256(raw).hexdigest() != CALENDAR_BASE_SOURCE_SHA256[name]:
        raise ValueError('Calendar driver changes other original bytes')
    return raw


PRIVATE_MAIL_SETTINGS_BASE_SHA256='c402b587fecc63af7d558b37b6f3dd7a46d7343bb7d8fa3c34d7a8d35d913225'

def reduce_reviewed_private_mail_settings(raw):
    name='src/infrastructure/governance/notification/PhorgePrivateMailPolicy.php'
    if type(raw) is not bytes or hashlib.sha256(raw).hexdigest()!=SOURCE_INPUTS[name]:
        raise ValueError('unreviewed private mail settings delta')
    old=b'      $viewer=(new PhabricatorPeopleQuery())->setViewer(PhabricatorUser::getOmnipotentUser())\n        ->withPHIDs(array($phid))->executeOne();'
    new=b'      $viewer=(new PhabricatorPeopleQuery())->setViewer(PhabricatorUser::getOmnipotentUser())\n        ->withPHIDs(array($phid))->needUserSettings(true)->executeOne();'
    if raw.count(new)!=1:
        raise ValueError('private mail settings inverse shape changed')
    reduced=raw.replace(new,old,1)
    if hashlib.sha256(reduced).hexdigest()!=PRIVATE_MAIL_SETTINGS_BASE_SHA256:
        raise ValueError('private mail settings changes other original bytes')
    return reduced


def exact(actual, expected):
    completion.exact(actual, expected, 'Calendar evidence refused')

def _source_views(root=ROOT):
    import check_recovery_file_parent_policy as native_driver_source
    root=Path(root)
    current_root=root==ROOT
    if current_root:
        import project_description_admission_delta as current
        current.assert_current_recipe(root)
    actual = {}
    wire = {}
    for path, pin in SOURCE_INPUTS.items():
        captured = source_reader.read_source_checked(Path(root) / path)
        raw = captured
        if path == native_driver_source.NATIVE_DRIVER_PATH:
            if current_root or hashlib.sha256(raw).hexdigest()!=pin:
                raw=native_driver_source.admitted_native_driver_source(root,raw)
            raw = conduit_delta.reduce_policy_cleanup_driver(raw)
        if hashlib.sha256(raw).hexdigest() != pin:
            raise ValueError('Calendar source drift')
        actual[path] = pin
        wire[path] = hashlib.sha256(captured).hexdigest()
    if len(actual) != 12 or len(CASES) != 21:
        raise ValueError('Calendar reviewed coverage changed')
    if current_root:current.assert_current_recipe(root)
    return actual, wire

def sources(root=ROOT):
    return _source_views(root)[0]

def wire_sources(root=ROOT):
    return _source_views(root)[1]

def obligations():
    return shared.BOOTSTRAP | shared.PREPARATION | shared.CLEANUP | CASES | {
        'completion-real-gorge-taskqueue-ready-loopback'}

def summary(revision, queue_digest, root=ROOT):
    return {'profile': 'native-private-calendar-reminder-v1',
        'source_revision': revision, 'source_inputs_sha256': wire_sources(root),
        'executed_case_obligations': sorted(CASES),
        'actual_native_scheduler_body': True, 'native_global_lock_or_daemon_proved': False,
        'actual_native_occurrence_marks': True, 'actual_paired_taskqueue': True,
        'manual_stored_event_queue_relay_proved': True, 'automatic_outbox_relay_or_queue_daemon_proved': False,
        'queue_cleanup': {'kind': 'taskqueue', 'binary_sha256': queue_digest,
            'process_stopped': True, 'private_storage_removed': True},
        'physical_SMTP_or_browser_proved': False, 'ephemeral_ghost_delivery_supported': False,
        'all_calendar_producer_paths_complete': False, 'activation_blocked': True}

def validate(data, *, source_revision, file_digest, queue_digest, root=ROOT):
    if not isinstance(source_revision, str) or not re.fullmatch('[a-f0-9]{40}', source_revision):
        raise ValueError('Calendar source revision required')
    for value in (file_digest, queue_digest):
        if not isinstance(value, str) or not re.fullmatch('[a-f0-9]{64}', value):
            raise ValueError('independent service digest required')
    completion.fields(data, native.FIELDS, 'Calendar evidence shape refused')
    expected = obligations()
    for key, value in {'batch': 'native-private-calendar-reminders',
        'scope': 'owned-native-calendar-reminders-no-SMTP', 'passed': True,
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
    exact(data['native'], summary(source_revision, queue_digest, root))
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
    return {'passed': True, 'scope': 'selected-native-calendar-scheduler-SQL-only',
        'source_revision': source_revision, 'checks': len(expected), 'calendar_obligations': len(CASES),
        'source_inputs': len(SOURCE_INPUTS), 'physical_SMTP_or_browser_proved': False,
        'native_global_lock_or_daemon_proved': False, 'ephemeral_ghost_delivery_supported': False,
        'all_calendar_producer_paths_complete': False, 'activation_blocked': True}

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
