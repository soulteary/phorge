import contextlib
import copy
import datetime as dt
import importlib.util
import io
import json
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('upgrade_check', ROOT / 'scripts/operations/upgrade_check.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
CATALOG = ROOT / 'docs/operations/2026.11.19-r3-components.json'
NOW = dt.datetime(2026, 11, 19, 12, tzinfo=dt.timezone.utc)


def fixture():
    catalog = json.loads(CATALOG.read_text())
    services = {}
    for role in m.ROLES:
        target = catalog['components']['phorge' if role == 'phorge-daemon' else role]
        services[role] = {
            'image_id': 'sha256:' + 'a' * 64,
            'configured_image': target['repository'] + '@' + target['index_digest'],
            'repo_digests': [target['repository'] + '@' + target['index_digest']],
            'architecture': 'arm64', 'os': 'linux',
            'revision': target['source_revision'], 'version': catalog['release'],
            'source': target['repository'].replace('ghcr.io/', 'https://github.com/'),
            'running': True, 'health_declared': True, 'health': 'healthy'}
    return catalog, {
        'schema_version': 1, 'captured_at': NOW.isoformat(), 'services': services,
        'optional_flags': {role: {key: False for key in m.FLAGS}
                           for role in ('phorge', 'phorge-daemon')}}


class UpgradeChecks(unittest.TestCase):
    def test_repository_catalog_and_historical_gates(self):
        catalog, snapshot = fixture()
        m.catalog_check(catalog)
        receipt = json.loads((CATALOG.parent / '2026.11.19-upgrade-acceptance.json').read_text())
        self.assertEqual(receipt['acceptance'], 'partial')
        self.assertEqual({g['id']: g['status'] for g in receipt['open_gates']},
                         {'database-redis-images': 'blocked', 'real-otp-login': 'not_executed'})

    def test_matching_images_still_leave_external_gates_open(self):
        catalog, snapshot = fixture()
        report = m.assess(catalog, snapshot, copy.deepcopy(snapshot), NOW)
        self.assertEqual(report['technical_status'], 'passed')
        self.assertEqual(report['acceptance'], 'incomplete')
        self.assertTrue(all(g['status'] == 'not_verified' for g in report['external_gates']))
        self.assertEqual(len(report['checks']), 24)

    def test_preflight_accepts_old_images_without_claiming_target_identity(self):
        catalog, snapshot = fixture()
        snapshot['services']['phorge']['revision'] = 'b' * 40
        report = m.assess(catalog, snapshot, now=NOW)
        self.assertEqual(report['technical_status'], 'passed')
        self.assertFalse(any(c['check'] == 'image-identity' for c in report['checks']))
        self.assertEqual(m.assess(catalog, snapshot, copy.deepcopy(snapshot), NOW)
                         ['technical_status'], 'failed')

    def test_actual_identity_cannot_be_replaced_by_a_configured_tag_or_claim(self):
        cases = {'configured_image': 'ghcr.io/soulteary/phorge:latest',
                 'repo_digests': [], 'architecture': '386', 'os': 'windows',
                 'revision': 'b' * 40, 'version': '2026.11.17-r1',
                 'source': 'https://github.com/example/unrelated'}
        for key, value in cases.items():
            catalog, snapshot = fixture()
            baseline = copy.deepcopy(snapshot)
            snapshot['services']['phorge'][key] = value
            with self.subTest(field=key):
                self.assertEqual(m.assess(catalog, snapshot, baseline, NOW)
                                 ['technical_status'], 'failed')

    def test_daemon_identity_is_checked_separately(self):
        catalog, snapshot = fixture()
        baseline = copy.deepcopy(snapshot)
        snapshot['services']['phorge-daemon']['revision'] = 'b' * 40
        self.assertEqual(m.assess(catalog, snapshot, baseline, NOW)['technical_status'], 'failed')

    def test_only_the_matching_child_platform_can_bind(self):
        catalog, snapshot = fixture()
        baseline = copy.deepcopy(snapshot)
        target = catalog['components']['phorge']
        row = snapshot['services']['phorge']
        for arch, expected in [('arm64', 'passed'), ('amd64', 'failed')]:
            ref = target['repository'] + '@' + target['platform_digests'][arch]
            row['configured_image'] = ref
            row['repo_digests'] = [ref]
            self.assertEqual(m.assess(catalog, snapshot, baseline, NOW)['technical_status'], expected)

    def test_image_config_id_alone_does_not_establish_registry_identity(self):
        catalog, snapshot = fixture()
        baseline = copy.deepcopy(snapshot)
        row = snapshot['services']['phorge']
        row['configured_image'] = row['image_id']
        self.assertEqual(m.assess(catalog, snapshot, baseline, NOW)['technical_status'], 'passed')
        row['repo_digests'] = []
        self.assertEqual(m.assess(catalog, snapshot, baseline, NOW)['technical_status'], 'failed')

    def test_preserved_application_image_must_remain_exact(self):
        catalog, snapshot = fixture()
        snapshot['services']['preserved-app'] = copy.deepcopy(snapshot['services']['phorge'])
        baseline = copy.deepcopy(snapshot)
        self.assertEqual(m.assess(catalog, snapshot, baseline, NOW)['technical_status'], 'passed')
        snapshot['services']['preserved-app']['image_id'] = 'sha256:' + 'b' * 64
        self.assertEqual(m.assess(catalog, snapshot, baseline, NOW)['technical_status'], 'failed')

    def test_absent_or_unhealthy_probes_are_not_assumed_healthy(self):
        for field, value in [('running', False), ('health_declared', False),
                             ('health', None), ('health', 'starting'), ('health', 'unhealthy')]:
            catalog, snapshot = fixture()
            snapshot['services']['gorge-mailer'][field] = value
            with self.subTest(field=field, value=value):
                self.assertEqual(m.assess(catalog, snapshot, now=NOW)['technical_status'], 'failed')

    def test_flags_require_both_processes_exact_false_and_complete_key_sets(self):
        for change in ('enabled', 'string', 'missing', 'unknown', 'daemon-missing'):
            catalog, snapshot = fixture()
            key = 'governance.media-exchange.http-enabled'
            flags = snapshot['optional_flags']['phorge-daemon']
            if change == 'enabled': flags[key] = True
            elif change == 'string': flags[key] = 'false'
            elif change == 'missing': flags.pop(key)
            elif change == 'unknown': flags['governance.future.enabled'] = False
            else: snapshot['optional_flags'].pop('phorge-daemon')
            with self.subTest(change=change):
                self.assertEqual(m.assess(catalog, snapshot, now=NOW)['technical_status'], 'failed')

    def test_recheck_does_not_ignore_baseline_feature_state(self):
        catalog, snapshot = fixture()
        baseline = copy.deepcopy(snapshot)
        baseline['optional_flags']['phorge']['governance.media-exchange.enabled'] = True
        self.assertEqual(m.assess(catalog, snapshot, baseline, NOW)['technical_status'], 'failed')

    def test_missing_roles_and_private_role_names_fail_without_echo(self):
        catalog, snapshot = fixture()
        for change in ('missing', 'extra', 'boolean-string'):
            value = copy.deepcopy(snapshot)
            if change == 'missing': value['services'].pop('phorge-daemon')
            elif change == 'extra': value['services']['private-host.example.invalid'] = {}
            else: value['services']['phorge']['running'] = 'true'
            with self.subTest(change=change), self.assertRaises(m.CheckError) as caught:
                m.assess(catalog, value, now=NOW)
            self.assertEqual(str(caught.exception), 'SNAPSHOT_INVALID')

    def test_stale_future_naive_and_reordered_observations_fail(self):
        for delta in (-3601, 61):
            catalog, snapshot = fixture()
            snapshot['captured_at'] = (NOW + dt.timedelta(seconds=delta)).isoformat()
            with self.subTest(delta=delta), self.assertRaisesRegex(m.CheckError, 'SNAPSHOT_STALE'):
                m.assess(catalog, snapshot, now=NOW)
        catalog, snapshot = fixture()
        baseline = copy.deepcopy(snapshot)
        baseline['captured_at'] = (NOW + dt.timedelta(seconds=1)).isoformat()
        with self.assertRaisesRegex(m.CheckError, 'BASELINE_MISMATCH'):
            m.assess(catalog, snapshot, baseline, NOW)
        snapshot['captured_at'] = '2026-11-19T12:00:00'
        with self.assertRaisesRegex(m.CheckError, 'SNAPSHOT_TIME_INVALID'):
            m.assess(catalog, snapshot, now=NOW)

    def test_catalog_rejects_mutable_digest_partial_pair_and_untrusted_repository(self):
        for change in ('digest', 'revision', 'role', 'repository', 'child', 'mixed-gorge'):
            catalog, _ = fixture()
            target = catalog['components']['phorge']
            if change == 'digest': target['index_digest'] = 'latest'
            elif change == 'revision': target['source_revision'] = 'main'
            elif change == 'role': catalog['components'].pop('gorge-worker')
            elif change == 'repository': target['repository'] = 'private-host.example.invalid/repo'
            elif change == 'child': target['platform_digests']['arm64'] = 'bad'
            else: catalog['components']['gorge-mailer']['source_revision'] = 'b' * 40
            with self.subTest(change=change), self.assertRaisesRegex(m.CheckError, 'CATALOG_INVALID'):
                m.catalog_check(catalog)

    def test_reports_do_not_echo_private_snapshot_values(self):
        catalog, snapshot = fixture()
        baseline = copy.deepcopy(snapshot)
        private = 'private-host.example.invalid:credential-fixture'
        snapshot['services']['phorge']['source'] = private
        snapshot['credentials'] = private
        report = json.dumps(m.assess(catalog, snapshot, baseline, NOW))
        self.assertNotIn(private, report)
        self.assertNotIn(snapshot['services']['phorge']['image_id'], report)

    def test_private_snapshots_are_exclusive_and_restricted(self):
        with tempfile.TemporaryDirectory() as folder:
            directory = Path(folder)
            directory.chmod(0o700)
            out = directory / 'snapshot.json'
            m.write_private(out, {'schema_version': 1})
            self.assertEqual(stat.S_IMODE(out.stat().st_mode), 0o600)
            with self.assertRaises(FileExistsError): m.write_private(out, {})
            link = directory / 'link.json'
            link.symlink_to(out)
            with self.assertRaises(FileExistsError): m.write_private(link, {})
            directory.chmod(0o755)
            with self.assertRaisesRegex(m.CheckError, 'OUTPUT_DIRECTORY_UNSAFE'):
                m.write_private(directory / 'unsafe.json', {})

    def test_ambiguous_or_deeply_nested_json_is_rejected(self):
        with tempfile.TemporaryDirectory() as folder:
            p = Path(folder) / 'input'
            p.write_text('{"enabled":true,"enabled":false}')
            with self.assertRaisesRegex(m.CheckError, 'INPUT_AMBIGUOUS'): m.read_json(p)
            p.write_text('[' * 2000 + ']' * 2000)
            with self.assertRaisesRegex(m.CheckError, 'INPUT_UNREADABLE'): m.read_json(p)

    def test_invalid_cli_and_subprocess_errors_do_not_print_input_or_stderr(self):
        private = 'private-host.example.invalid:credential-fixture'
        for args in [['collect', '--unknown', private], ['preflight', '--catalog', private,
                                                    '--snapshot', private]]:
            output = io.StringIO()
            with contextlib.redirect_stdout(output): self.assertEqual(m.main(args), 1)
            self.assertNotIn(private, output.getvalue())
        for failure in (OSError(private), subprocess.TimeoutExpired(['docker', private], 30),
                        subprocess.CompletedProcess([], 1, b'', private.encode())):
            kwargs = {'side_effect': failure} if isinstance(failure, Exception) else {'return_value': failure}
            with patch.object(m.subprocess, 'run', **kwargs), self.assertRaises(m.CheckError) as caught:
                m.docker(['inspect', 'a' * 64])
            self.assertNotIn(private, str(caught.exception))

    def test_cli_keeps_successful_technical_checks_incomplete(self):
        catalog, snapshot = fixture()
        snapshot['captured_at'] = dt.datetime.now(dt.timezone.utc).isoformat()
        with tempfile.TemporaryDirectory() as folder:
            p = Path(folder)
            for name, value in [('catalog', catalog), ('before', snapshot), ('after', snapshot)]:
                (p / name).write_text(json.dumps(value))
            out = io.StringIO()
            with contextlib.redirect_stdout(out):
                code = m.main(['recheck', '--catalog', str(p / 'catalog'), '--baseline',
                               str(p / 'before'), '--snapshot', str(p / 'after')])
            self.assertEqual(code, 2)
            self.assertEqual(json.loads(out.getvalue())['acceptance'], 'incomplete')

    def test_real_cli_process_emits_only_redacted_report_with_incomplete_exit(self):
        catalog, snapshot = fixture()
        snapshot['captured_at'] = dt.datetime.now(dt.timezone.utc).isoformat()
        snapshot['unreviewed_private_field'] = 'credential-fixture-not-for-output'
        with tempfile.TemporaryDirectory() as folder:
            p = Path(folder)
            for name, value in [('catalog', catalog), ('before', snapshot), ('after', snapshot)]:
                (p / name).write_text(json.dumps(value))
            result = subprocess.run([sys.executable, str(ROOT / 'scripts/operations/upgrade_check.py'),
                                     'recheck', '--catalog', str(p / 'catalog'), '--baseline',
                                     str(p / 'before'), '--snapshot', str(p / 'after')],
                                    capture_output=True, text=True)
            self.assertEqual(result.returncode, 2)
            self.assertEqual(json.loads(result.stdout)['technical_status'], 'passed')
            self.assertEqual(result.stderr, '')
            self.assertNotIn('credential-fixture', result.stdout)


class CollectionChecks(unittest.TestCase):
    def fixture(self):
        bindings = {role: f'{i:064x}' for i, role in enumerate(sorted(m.ROLES), 1)}
        images = {}
        containers = []
        for role, cid in bindings.items():
            image = 'sha256:' + cid
            images[image] = {'Id': image, 'Architecture': 'arm64', 'Os': 'linux',
                             'Config': {'Labels': {}}, 'RepoDigests': []}
            containers.append({'Id': cid, 'Image': image, 'RestartCount': 0,
                               'Config': {'Image': image, 'Env': ['PASSWORD=credential-fixture'],
                                          'Healthcheck': {'Test': ['CMD', 'true']}},
                               'State': {'Running': True, 'StartedAt': 'fixed',
                                         'Health': {'Status': 'healthy'}}})
        return bindings, containers, images

    def test_collector_only_uses_inspect_and_fixed_config_reads(self):
        bindings, containers, images = self.fixture()
        calls = []
        def run(args):
            calls.append(args)
            if args[0] == 'inspect': return copy.deepcopy(containers)
            if args[:2] == ['image', 'inspect']: return [images[args[2]]]
            self.assertEqual(args[:1], ['exec'])
            self.assertIn(args[1], [bindings['phorge'], bindings['phorge-daemon']])
            self.assertEqual(args[2:], ['php', '-r', m.PHP_FLAGS])
            return {key: False for key in m.FLAGS}
        with patch.object(m, 'docker', side_effect=run): result = m.collect(bindings)
        self.assertNotIn('credential-fixture', json.dumps(result))
        self.assertEqual(set(result['services']), m.ROLES)
        self.assertEqual(len([c for c in calls if c[0] == 'exec']), 2)

    def test_restart_during_capture_refuses_snapshot(self):
        bindings, containers, images = self.fixture()
        count = 0
        def run(args):
            nonlocal count
            if args[0] == 'inspect':
                count += 1
                result = copy.deepcopy(containers)
                result[0]['RestartCount'] = count
                return result
            if args[0] == 'image': return [images[args[2]]]
            return {key: False for key in m.FLAGS}
        with patch.object(m, 'docker', side_effect=run), self.assertRaisesRegex(
                m.CheckError, 'CONTAINER_IDENTITY_CHANGED'):
            m.collect(bindings)

    def test_injected_binding_or_missing_inventory_never_invokes_docker(self):
        bindings, _, _ = self.fixture()
        for value in ('--format=credential-fixture', 'short-name', 'private-host.example.invalid'):
            bad = dict(bindings, phorge=value)
            with patch.object(m, 'docker') as docker, self.assertRaises(m.CheckError):
                m.collect(bad)
            docker.assert_not_called()
        with patch.object(m, 'docker', return_value=[]), self.assertRaisesRegex(
                m.CheckError, 'CONTAINER_IDENTITY_CHANGED'):
            m.collect(bindings)


if __name__ == '__main__':
    unittest.main()
