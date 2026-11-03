#!/usr/bin/env python3
"""Validate bounded event observations. Input shape is never producer attestation."""
import argparse
import json
from pathlib import Path
import re

FAMILIES = frozenset({'gateway', 'app-session', 'api-token', 'media', 'background', 'long-connection'})
HEX64 = re.compile(r'[a-f0-9]{64}')


def require(value, reason):
    if not value:
        raise ValueError(reason)


def exact(value, keys):
    require(type(value) is dict and set(value) == set(keys), 'event_shape_refused')


def uint(value):
    require(type(value) is int and 0 <= value <= 2**63 - 1, 'monotonic_time_refused')
    return value


def describe_observation(event):
    """Pure input analysis, not proof that a native event actually occurred."""
    exact(event, ['schema', 'source_revision', 'producer_sha256', 'family', 'clock', 'transition', 'attempts'])
    require(type(event['schema']) is int and event['schema'] in (1, 2), 'event_version_refused')
    require(type(event['family']) is str and event['family'] in FAMILIES, 'adapter_family_refused')
    require(type(event['source_revision']) is str and re.fullmatch('[a-f0-9]{40}', event['source_revision']), 'source_revision_refused')
    require(type(event['producer_sha256']) is str and HEX64.fullmatch(event['producer_sha256']), 'producer_digest_refused')
    clock = event['clock']
    exact(clock, ['kind', 'boot_id_sha256'])
    require(clock['kind'] == 'monotonic_ns' and type(clock['boot_id_sha256']) is str and HEX64.fullmatch(clock['boot_id_sha256']), 'clock_domain_refused')
    transition = event['transition']
    binding_key = 'native_commit_id_sha256' if event['schema'] == 1 else 'operation_binding_sha256'
    exact(transition, ['id', 'action', binding_key, 'boundary', 'operation_start_ns', 'commit_confirmed_return_ns'])
    for key in ('id', binding_key):
        require(type(transition[key]) is str and HEX64.fullmatch(transition[key]), 'transition_binding_refused')
    require(transition['action'] in ('revoke', 'disable', 'logout', 'expire', 'reenable'), 'transition_action_refused')
    if event['schema'] == 1:
        require(transition['boundary'] == 'native-transaction-operation-call-return', 'commit_boundary_refused')
    else:
        require(event['family'] == 'api-token' and transition['action'] == 'revoke' and
            transition['boundary'] == 'native-token-dao-delete-own-transaction' and
            transition['operation_binding_sha256'] == transition['id'], 'token_delete_boundary_refused')
    send = uint(transition['operation_start_ns']); ack = uint(transition['commit_confirmed_return_ns'])
    require(send <= ack, 'commit_order_refused')
    attempts = event['attempts']
    require(type(attempts) is list and len(attempts) <= 10000, 'attempt_budget_refused')
    first = None; later_allow = False; previous = -1; ids = set(); postcommit = 0
    for attempt in attempts:
        exact(attempt, ['id', 'transition_id', 'boot_id_sha256', 'start_ns', 'finish_ns', 'outcome'])
        require(type(attempt['id']) is int and attempt['id'] >= 1 and attempt['id'] not in ids, 'attempt_identity_refused'); ids.add(attempt['id'])
        require(attempt['transition_id'] == transition['id'], 'transition_binding_refused')
        require(attempt['boot_id_sha256'] == clock['boot_id_sha256'], 'cross_boot_measurement_refused')
        start = uint(attempt['start_ns']); finish = uint(attempt['finish_ns'])
        require(previous <= start <= finish, 'attempt_order_refused'); previous = finish
        require(attempt['outcome'] in ('allowed', 'denied', 'unknown', 'transport-error'), 'attempt_outcome_refused')
        if start < ack:
            continue  # In-flight/precommit requests are not postcommit evidence.
        postcommit += 1
        if first is None and attempt['outcome'] == 'denied':
            first = {'attempt_id': attempt['id'], 'from_commit_confirmed_return_ns': [start - ack, finish - ack],
                     'from_actual_commit_bracket_ns': [max(0, start - ack), finish - send]}
        elif first is not None and attempt['outcome'] == 'allowed':
            later_allow = True
    return {'schema': event['schema'], 'family': event['family'], 'source_revision': event['source_revision'],
            'transition_id': transition['id'], 't0': 'native-operation-return-confirming-commit',
            'postcommit_attempts': postcommit, 'first_denied_observation': first,
            'later_allowed_observed': later_allow, 'convergence_proved': False,
            'SLA_proved': False, 'native_authority_proved': False,
            'producer_attestation': 'unverified-input', 'measurement_acceptance': False}


def describe_native_query_receipt(receipt, expected_revision, expected_producer_digest):
    """Bind the narrow producer envelope; trusted execution is an outer gate."""
    exact(receipt, ['profile', 'actual_source_checked', 'actual_independent_query_processes',
        'HTTP_proved', 'gateway_proved', 'long_connection_proved', 'SLA_proved',
        'convergence_proved', 'event', 'native_source_binding_sha256'])
    require(receipt['profile'] == 'native-session-authenticated-project-query-only' and
        receipt['actual_source_checked'] is True and
        type(receipt['actual_independent_query_processes']) is int and
        receipt['actual_independent_query_processes'] == 3, 'native_query_profile_refused')
    for key in ('HTTP_proved', 'gateway_proved', 'long_connection_proved', 'SLA_proved', 'convergence_proved'):
        require(receipt[key] is False, 'native_query_scope_promotion_refused')
    require(type(receipt['native_source_binding_sha256']) is str and
        HEX64.fullmatch(receipt['native_source_binding_sha256']), 'native_source_binding_refused')
    result = describe_observation(receipt['event'])
    event = receipt['event']
    require(event['schema'] == 1 and event['source_revision'] == expected_revision and
        event['producer_sha256'] == expected_producer_digest and event['family'] == 'app-session',
        'native_producer_source_mismatch')
    require(len(event['attempts']) == 3 and [a['outcome'] for a in event['attempts']] ==
        ['allowed', 'denied', 'denied'] and event['attempts'][0]['finish_ns'] <=
        event['transition']['operation_start_ns'] and result['postcommit_attempts'] == 2,
        'native_query_control_sequence_refused')
    result['profile'] = receipt['profile']
    result['producer_attestation'] = 'source-bound-envelope-execution-attestation-required'
    # An independent current-source execution/artifact envelope must establish
    # that these events occurred. JSON/hash agreement alone never proves it.
    return result


def describe_native_token_receipt(receipt, expected_revision, expected_producer_digest):
    """Version2 token deletion has no native User transaction-group record."""
    exact(receipt, ['profile', 'actual_source_checked', 'actual_independent_authentication_processes',
        'token_rows_deleted', 'native_transaction_record_available', 'HTTP_proved', 'gateway_proved',
        'SLA_proved', 'convergence_proved', 'event', 'before_source_binding_sha256', 'after_source_binding_sha256'])
    require(receipt['profile'] == 'native-conduit-token-revoke-authentication-only' and
        receipt['actual_source_checked'] is True and
        type(receipt['actual_independent_authentication_processes']) is int and
        receipt['actual_independent_authentication_processes'] == 3 and
        type(receipt['token_rows_deleted']) is int and receipt['token_rows_deleted'] == 1,
        'native_token_profile_refused')
    for key in ('native_transaction_record_available', 'HTTP_proved', 'gateway_proved', 'SLA_proved', 'convergence_proved'):
        require(receipt[key] is False, 'native_token_scope_promotion_refused')
    for key in ('before_source_binding_sha256', 'after_source_binding_sha256'):
        require(type(receipt[key]) is str and HEX64.fullmatch(receipt[key]), 'native_token_source_binding_refused')
    require(receipt['before_source_binding_sha256'] != receipt['after_source_binding_sha256'], 'native_token_source_unchanged')
    result = describe_observation(receipt['event']); event = receipt['event']
    require(event['schema'] == 2 and event['source_revision'] == expected_revision and
        event['producer_sha256'] == expected_producer_digest and event['family'] == 'api-token',
        'native_token_producer_source_mismatch')
    require(len(event['attempts']) == 3 and [a['outcome'] for a in event['attempts']] ==
        ['allowed', 'denied', 'denied'] and event['attempts'][0]['finish_ns'] <=
        event['transition']['operation_start_ns'] and result['postcommit_attempts'] == 2,
        'native_token_control_sequence_refused')
    result['profile'] = receipt['profile']
    result['producer_attestation'] = 'source-bound-envelope-execution-attestation-required'
    return result


def strict_object(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, 'duplicate_event_key_refused'); result[key] = value
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--events', type=Path, required=True)
    args = parser.parse_args()
    require(args.events.is_file() and not args.events.is_symlink() and args.events.stat().st_size <= 4 * 1024 * 1024, 'event_file_refused')
    event = json.loads(args.events.read_text(), object_pairs_hook=strict_object,
        parse_constant=lambda value: (_ for _ in ()).throw(ValueError('nonfinite_event_refused')))
    result = describe_observation(event)
    # Inventory remains deliberately uninstrumented until real source adapters
    # emit same-clock actual commit and request observations in isolated tests.
    result['adapter_runtime_status'] = 'unsupported-uninstrumented'
    print(json.dumps(result, sort_keys=True))


if __name__ == '__main__':
    main()
