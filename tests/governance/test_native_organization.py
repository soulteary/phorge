"""Mutation tests for the native evidence gate, not runtime permission proof."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('native_organization_gate', ROOT/'scripts/governance/check_native_organization.py')
gate=importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


def validate(data, **kwargs):
    return gate.validate(data, measurement_revision='a'*40, **kwargs)


def template():
    hashes={'phabricator:'+p: hashlib.sha256((ROOT/'resources/sql/autopatches'/p).read_bytes()).hexdigest()
        for p in gate.completion.PATCHES}
    return {'batch': 'native-organization-media-integration', 'passed': True,
        'scope': 'owned-full-native-source-synthetic-accounts', 'checks': sorted(gate.REQUIRED_CHECKS),
        'check_count': len(gate.REQUIRED_CHECKS), 'php_version': '8.5.12',
        'source_restore': {'quickstart_sha256': hashlib.sha256((ROOT/'resources/sql/quickstart.sql').read_bytes()).hexdigest(),
            'app_schema_count': 54, 'owned_schema_count': 55, 'quickstart_source_table_count': 500,
            'current_table_count': 540, 'restore_phase_workers_started': 0,
            'restore_phase_network_endpoints_configured': 0, 'patch_audit': {
                'source_patch_count': 1570, 'applied_patch_count': 1570, 'pending_patch_keys': [],
                'current_patch_ledger_complete': True, 'migration_source_hashes': hashes,
                'migrations_executed': len(hashes)}},
        'native': copy.deepcopy(gate.NATIVE), 'cleanup': {
            'ownedSchemasRemoved': 55, 'ownedRuntimeUsersRemoved': 2, 'readerRemoved': True},
        'worker_cleanup': {'workers_registered': gate.WORKERS,
            'private_directories_removed': gate.WORKERS, 'private_directories_remaining': 0,
            'live_processes_after_cleanup': 0, 'owned_processes_stopped': 0, 'cleanup_failures': 0},
        'file_service_cleanup': {'kind':'file','binary_sha256':'b'*64,
            'process_stopped':True,'private_storage_removed':True},
        'successful_owned_file_service_startups': 1,
        'optional_source_reader_adapter_exercised': True, 'isolated_optional_features_enabled': True,
        'failure': None, 'cleanup_failures': [], 'terminal_failure_cleanup_rollbacks': 0,
        'captured_native_output_bytes': 323, 'captured_native_output_sha256': 'a'*64,
        'production_deployment': False, 'production_executor': False,
        'production_optional_features_enabled': False, 'native_permission_axes_changed': False,
        'remaining_blocked': list(gate.BLOCKERS)}


class NativeOrganizationEvidenceTest(unittest.TestCase):
