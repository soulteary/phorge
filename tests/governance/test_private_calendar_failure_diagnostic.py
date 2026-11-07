"""Actual strict-FD projection/owned failure-shell controls, never SQL proof."""
import copy
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/governance'))
import project_calendar_failure as diagnostic

SECRET = 'SYNTHETIC_SECRET_SQL_BODY_EMAIL_ARGS_CREDENTIAL'


def failure():
    return {'batch': 'native-private-calendar-reminders',
        'scope': 'owned-native-calendar-reminders-no-SMTP', 'passed': False,
        'checks': ['full-current-source-quickstart-and-patch-ledger-restored',
            'calendar-private-real-queue-ready-no-worker'], 'check_count': 2,
        'failure': {'stage': 'calendar-private-real-queue-ready-no-worker',
            'error_type': 'Error', 'source_file': 'calendar-reminder-cases.php',
            'source_line': 117, 'sql_errno': 1146, 'message': SECRET,
            'SQL': SECRET, 'body': SECRET, 'args': [SECRET]},
        'cleanup': {'ownedSchemasRemoved': 55, 'ownedRuntimeUsersRemoved': 2,
            'readerRemoved': True, 'namespace': SECRET},
        'worker_cleanup': {'workers_registered': 0, 'live_processes_after_cleanup': 0,
            'private_directories_remaining': 0, 'cleanup_failures': 0, 'args': [SECRET]},
        'file_service_cleanup': {'kind': 'file', 'process_stopped': True,
            'private_storage_removed': True, 'token': SECRET},
        'cleanup_failures': [], 'terminal_failure_cleanup_rollbacks': 0,
        'native': {'body': SECRET}, 'source_restore': {'SQL': SECRET}}


class CalendarFailureDiagnostic(unittest.TestCase):
    def cli(self, runner, raw, mode='normal', output_name='diagnostic.json'):
        receipt_dir = runner / diagnostic.RECEIPT_DIRECTORY
        receipt_dir.mkdir(mode=0o700)
        receipt = receipt_dir / 'integration.json'
        if mode == 'fifo': os.mkfifo(receipt, 0o600)
        else:
            receipt.touch(mode=0o600); receipt.write_text(raw)
            if mode == 'writable': receipt.chmod(0o644)
            if mode == 'hardlink': os.link(receipt, receipt_dir / 'other')
            if mode == 'symlink':
                receipt.rename(receipt_dir / 'original')
                receipt.symlink_to(receipt_dir / 'original')
        output_dir = runner / diagnostic.FAILURE_DIRECTORY
        output_dir.mkdir(mode=0o700)
        output = output_dir / output_name
        result = subprocess.run([sys.executable, '-B', str(ROOT / 'scripts/governance/project_calendar_failure.py'),
            '--receipt', str(receipt), '--output', str(output)],
            env=dict(os.environ, RUNNER_TEMP=str(runner)), capture_output=True, text=True, timeout=15)
        return result, output

    def test_exact_failed_phase_emits_only_known_failure_and_cleanup_metadata(self):
        value = diagnostic.project(failure())
        self.assertTrue(value['diagnostic_available'])
        self.assertFalse(value['SQL_success_proved'])
        self.assertEqual({'stage': 'calendar-private-real-queue-ready-no-worker',
            'error_type': 'Error', 'source_file': 'calendar-reminder-cases.php',
            'source_line': 117, 'sql_errno': 1146}, value['failure'])
        self.assertNotIn(SECRET, json.dumps(value))
        self.assertEqual(55, value['cleanup']['owned_schemas_removed'])
        self.assertTrue(value['cleanup']['reader_removed'])
        self.assertEqual(2, len(value['completed_obligations']))

    def test_unknown_names_paths_and_bool_numbers_are_never_echoed_or_coerced(self):
        data = failure(); data['failure'].update(stage=SECRET, error_type=SECRET,
            source_file='/'+SECRET+'/calendar-reminder-cases.php', source_line=True, sql_errno=False)
        data['cleanup'].update(ownedSchemasRemoved=True, ownedRuntimeUsersRemoved='2', readerRemoved='yes')
        data['worker_cleanup'].update(workers_registered=False, live_processes_after_cleanup='0')
        value = diagnostic.project(data)
        self.assertEqual(dict.fromkeys(('stage', 'error_type', 'source_file', 'source_line', 'sql_errno')), value['failure'])
        for key in ('owned_schemas_removed', 'owned_runtime_users_removed', 'reader_removed', 'registered_workers', 'live_processes_after_cleanup'):
            self.assertIsNone(value['cleanup'][key])
        self.assertNotIn(SECRET, json.dumps(value))

    def test_success_other_phase_and_untyped_passed_refuse_diagnostic_adoption(self):
        for patch in ({'passed': True}, {'passed': 0}, {'batch': 'native-borrowed-session-owner'},
                      {'scope': SECRET}, {'passed': None}):
            with self.subTest(patch=patch):
                self.assertEqual(diagnostic.unavailable(), diagnostic.project(dict(failure(), **patch)))

    def test_only_exact_known_unique_case_ids_are_reported(self):
        for checks, count in [([SECRET], 1), ([True], 1), (failure()['checks'] * 2, 4),
                              (failure()['checks'], True), (failure()['checks'], 1)]:
            data = failure(); data.update(checks=checks, check_count=count)
            value = diagnostic.project(data)
            self.assertFalse(value['checks_valid']); self.assertEqual([], value['completed_obligations'])
            self.assertNotIn(SECRET, json.dumps(value))

    def test_cleanup_error_projection_and_budget_never_copy_raw_errors(self):
        data = failure(); data['cleanup_failures'] = [{'stage': 'owned-cleanup', 'error_type': 'RuntimeException',
            'source_file': 'calendar-reminder-cases.php', 'source_line': 42, 'message': SECRET}]
        value = diagnostic.project(data)
        self.assertEqual(1, value['cleanup']['cleanup_error_count'])
        self.assertEqual('owned-cleanup', value['cleanup']['cleanup_errors'][0]['stage'])
        self.assertNotIn(SECRET, json.dumps(value))
        for invalid in (data['cleanup_failures'] * 17, [SECRET], False):
            data['cleanup_failures'] = invalid; value = diagnostic.project(data)
            self.assertFalse(value['cleanup']['cleanup_error_list_valid'])
            self.assertEqual([], value['cleanup']['cleanup_errors'])

    def test_actual_public_cli_owns_private_file_and_stderr_matches_held_safe_bytes(self):
        with tempfile.TemporaryDirectory() as tmp:
            runner = Path(tmp).resolve()
            result, output = self.cli(runner, json.dumps(failure()))
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual('', result.stdout)
            self.assertNotIn(SECRET, result.stderr)
            self.assertEqual(output.read_text(), result.stderr)
            self.assertIs(json.loads(result.stderr)['diagnostic_available'], True)
            self.assertEqual(0o600, stat.S_IMODE(output.stat().st_mode))
            self.assertEqual(1, output.stat().st_nlink)

    def test_actual_receipt_alias_fifo_hardlink_and_writable_mode_refuse_without_blocking(self):
        for mode in ('symlink', 'fifo', 'hardlink', 'writable'):
            with self.subTest(mode=mode), tempfile.TemporaryDirectory() as tmp:
                result, output = self.cli(Path(tmp).resolve(), json.dumps(failure()), mode)
                self.assertEqual(0, result.returncode)
                self.assertEqual(diagnostic.unavailable(), json.loads(output.read_text()))
                self.assertNotIn(SECRET, result.stderr)

    def test_duplicate_nonfinite_truncated_and_wrong_phase_json_are_not_adopted(self):
        for raw in ('{"passed":false,"passed":true}', '{"number":NaN}', '{',
                    json.dumps(dict(failure(), batch=SECRET))):
            with self.subTest(raw=raw[:30]), tempfile.TemporaryDirectory() as tmp:
                result, output = self.cli(Path(tmp).resolve(), raw)
                self.assertEqual(0, result.returncode)
                self.assertEqual(diagnostic.unavailable(), json.loads(output.read_text()))
                self.assertNotIn(SECRET, result.stderr)

    def test_fixed_output_role_and_existing_foreign_file_are_never_adopted(self):
        with tempfile.TemporaryDirectory() as tmp:
            runner = Path(tmp).resolve()
            result, output = self.cli(runner, json.dumps(failure()), output_name='other.json')
            self.assertNotEqual(0, result.returncode); self.assertFalse(output.exists())
            self.assertNotIn(SECRET, result.stderr)
        with tempfile.TemporaryDirectory() as tmp:
            parent = Path(tmp).resolve(); output = parent / 'diagnostic.json'; output.touch(mode=0o600)
            output.write_text(SECRET)
            with self.assertRaises(FileExistsError): diagnostic.write_exclusive(output, b'{"safe":true}\n')
            self.assertEqual(SECRET, output.read_text())

    def test_actual_exclusive_writer_refuses_hardlink_added_during_write(self):
        with tempfile.TemporaryDirectory() as tmp:
            parent = Path(tmp).resolve(); output = parent / 'diagnostic.json'; link = parent / 'unexpected-link'
            original = os.write
            def add_link(fd, raw):
                if not link.exists(): os.link(output, link)
                return original(fd, raw)
            with mock.patch.object(diagnostic.os, 'write', side_effect=add_link):
                with self.assertRaises(ValueError): diagnostic.write_exclusive(output, b'{"safe":true}\n')
            self.assertEqual(2, output.stat().st_nlink)

    def test_actual_exclusive_writer_does_not_accept_replaced_output_inode(self):
        with tempfile.TemporaryDirectory() as tmp:
            parent = Path(tmp).resolve(); output = parent / 'diagnostic.json'; original_file = parent / 'original'
            original_write = os.write
            def replace(fd, raw):
                if not original_file.exists():
                    output.rename(original_file); output.touch(mode=0o600); output.write_text(SECRET)
                return original_write(fd, raw)
            with mock.patch.object(diagnostic.os, 'write', side_effect=replace):
                with self.assertRaises(ValueError): diagnostic.write_exclusive(output, b'{"safe":true}\n')
            self.assertEqual(SECRET, output.read_text())
            self.assertEqual(b'{"safe":true}\n', original_file.read_bytes())

    def test_original_fd_readback_rejects_same_inode_content_replacement(self):
        with tempfile.TemporaryDirectory() as tmp:
            parent = Path(tmp).resolve(); output = parent / 'diagnostic.json'
            original = os.write
            def alter_content(fd, raw):
                written = original(fd, raw)
                os.pwrite(fd, b'x' * len(raw), 0)
                return written
            with mock.patch.object(diagnostic.os, 'write', side_effect=alter_content):
                with self.assertRaises(ValueError): diagnostic.write_exclusive(output, b'{"safe":true}\n')
            self.assertEqual(b'x' * len(b'{"safe":true}\n'), output.read_bytes())

    def test_actual_writer_rejects_public_directory_or_output_mode_change(self):
        with tempfile.TemporaryDirectory() as tmp:
            parent = Path(tmp).resolve(); output = parent / 'diagnostic.json'
            parent.chmod(0o755)
            with self.assertRaises(ValueError): diagnostic.write_exclusive(output, b'{"safe":true}\n')
            self.assertFalse(output.exists())
            parent.chmod(0o700)
            original = os.write
            def change_mode(fd, raw):
                written = original(fd, raw)
                os.chmod(output, 0o644)
                return written
            with mock.patch.object(diagnostic.os, 'write', side_effect=change_mode):
                with self.assertRaises(ValueError): diagnostic.write_exclusive(output, b'{"safe":true}\n')

    def test_oversized_receipt_is_refused_before_content_adoption(self):
        with tempfile.TemporaryDirectory() as tmp:
            result, output = self.cli(Path(tmp).resolve(), 'x' * (4194304 + 1))
            self.assertEqual(0, result.returncode)
            self.assertEqual(diagnostic.unavailable(), json.loads(output.read_text()))
            self.assertLess(len(result.stderr), 512)

    def test_failure_shell_preserves_original_status_and_marks_only_new_owned_artifact(self):
        workflow = (ROOT / '.github/workflows/atomic-feature-regressions.yml').read_text()
        line = next(row for row in workflow.splitlines() if '--calendar-reminders >' in row)
        suffix = line[line.index('|| {'):]
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp).resolve(); foreign = root / 'foreign'; foreign.mkdir(mode=0o700)
            (foreign / 'sentinel').write_text(SECRET)
            for mode in ('good', 'foreign-directory', 'foreign-symlink', 'raced-existing-file'):
                runner = root / mode; runner.mkdir(mode=0o700)
                receipt_dir = runner / diagnostic.RECEIPT_DIRECTORY; receipt_dir.mkdir(mode=0o700)
                receipt = receipt_dir / 'integration.json'; receipt.touch(mode=0o600); receipt.write_text(json.dumps(failure()))
                output = runner / 'github-output'; output.touch(mode=0o600)
                failed_dir = runner / diagnostic.FAILURE_DIRECTORY
                if mode == 'foreign-directory': failed_dir.mkdir(mode=0o700)
                if mode == 'foreign-symlink': failed_dir.symlink_to(foreign, target_is_directory=True)
                setup = ''
                if mode == 'raced-existing-file':
                    setup = 'mkdir(){ command mkdir "$@"; echo ' + SECRET + ' > "$RUNNER_TEMP/governance-private-calendar-failure/diagnostic.json"; }; '
                result = subprocess.run(['bash', '-c', 'set -eu; umask 077; ' + setup + '(exit 37) ' + suffix],
                    cwd=ROOT, env=dict(os.environ, RUNNER_TEMP=str(runner), GITHUB_OUTPUT=str(output)),
                    capture_output=True, text=True, timeout=20)
                self.assertEqual(37, result.returncode)
                self.assertEqual('', result.stdout); self.assertNotIn(SECRET, result.stderr)
                self.assertEqual('diagnostic_owned=true\n' if mode == 'good' else '', output.read_text())
                if mode == 'good':
                    self.assertIs(json.loads(result.stderr)['diagnostic_available'], True)
                    self.assertEqual(result.stderr, (failed_dir / 'diagnostic.json').read_text())
                if mode == 'raced-existing-file':
                    self.assertEqual(SECRET, (failed_dir / 'diagnostic.json').read_text().strip())
                self.assertEqual(SECRET, (foreign / 'sentinel').read_text())

    def test_marker_sink_failure_or_missing_variable_cannot_mask_original_native_status(self):
        workflow = (ROOT / '.github/workflows/atomic-feature-regressions.yml').read_text()
        line = next(row for row in workflow.splitlines() if '--calendar-reminders >' in row)
        suffix = line[line.index('|| {'):]
        for mode in ('directory', 'readonly-file', 'unset', 'empty', 'not-directory'):
            # root can legitimately write an ordinary mode0400 file. The other
            # sink failures remain mandatory on every UID; this permission-only
            # case is exercised on the normal non-root host/Actions runner.
            if mode == 'readonly-file' and os.geteuid() == 0:
                continue
            for native_status in (1, 37):
                with self.subTest(mode=mode, native_status=native_status), tempfile.TemporaryDirectory() as tmp:
                    runner = Path(tmp).resolve()
                    receipt_dir = runner / diagnostic.RECEIPT_DIRECTORY; receipt_dir.mkdir(mode=0o700)
                    receipt = receipt_dir / 'integration.json'; receipt.touch(mode=0o600); receipt.write_text(json.dumps(failure()))
                    environment = dict(os.environ, RUNNER_TEMP=str(runner))
                    marker = runner / 'github-output'
                    if mode == 'directory': marker.mkdir(mode=0o700); environment['GITHUB_OUTPUT'] = str(marker)
                    elif mode == 'readonly-file': marker.touch(mode=0o400); environment['GITHUB_OUTPUT'] = str(marker)
                    elif mode == 'unset': environment.pop('GITHUB_OUTPUT', None)
                    elif mode == 'empty': environment['GITHUB_OUTPUT'] = ''
                    else: marker.touch(mode=0o600); environment['GITHUB_OUTPUT'] = str(marker / 'child')
                    result = subprocess.run(['bash', '-c', 'set -euo pipefail; umask 077; (exit ' + str(native_status) + ') ' + suffix],
                        cwd=ROOT, env=environment, capture_output=True, text=True, timeout=20)
                    self.assertEqual(native_status, result.returncode, result.stderr)
                    self.assertEqual('', result.stdout); self.assertNotIn(SECRET, result.stderr)
                    # Projection succeeded before the marker sink failed. Only
                    # the fixed safe file remains; no marker can permit upload.
                    safe = runner / diagnostic.FAILURE_DIRECTORY / 'diagnostic.json'
                    self.assertIs(json.loads(safe.read_text())['diagnostic_available'], True)
                    if marker.is_file(): self.assertEqual('', marker.read_text())
                    self.assertNotIn('diagnostic_owned=true', result.stdout)

    def test_success_path_never_enters_diagnostic_suffix_and_unknown_arguments_do_not_echo(self):
        workflow = (ROOT / '.github/workflows/atomic-feature-regressions.yml').read_text()
        line = next(row for row in workflow.splitlines() if '--calendar-reminders >' in row)
        with tempfile.TemporaryDirectory() as tmp:
            runner = Path(tmp).resolve()
            result = subprocess.run(['bash', '-c', 'set -eu; (exit 0) ' + line[line.index('|| {'):]],
                env=dict(os.environ, RUNNER_TEMP=str(runner)), capture_output=True, text=True, timeout=10)
            self.assertEqual(0, result.returncode); self.assertFalse((runner / diagnostic.FAILURE_DIRECTORY).exists())
        result = subprocess.run([sys.executable, '-B', str(ROOT / 'scripts/governance/project_calendar_failure.py'),
            '--unreviewed-argument', SECRET], capture_output=True, text=True, timeout=10)
        self.assertNotEqual(0, result.returncode); self.assertNotIn(SECRET, result.stderr)

    def test_failure_artifact_is_exact_safe_file_and_existing_success_gate_remains(self):
        workflow = (ROOT / '.github/workflows/atomic-feature-regressions.yml').read_text()
        self.assertIn("if: failure() && steps.calendar-reminders.outputs.diagnostic_owned == 'true'", workflow)
        self.assertIn('path: ${{ runner.temp }}/governance-private-calendar-failure/diagnostic.json', workflow)
        self.assertNotIn('path: ${{ runner.temp }}/governance-private-calendar-failure\n', workflow)
        block = workflow[workflow.index('      - name: Verify actual private Calendar reminder'):workflow.index('      - name: Verify fresh native organization approval workbench')]
        self.assertIn('check_private_calendar_reminders.py --receipt', block)
        self.assertIn('if: success()', block)
        self.assertIn('accepted.pending.json', block)
        self.assertIn('exit "$native_status"', block)
        self.assertNotIn('cat "$RUNNER_TEMP/governance-private-calendar-failure', block)
        self.assertNotIn('continue-on-error', block)


if __name__ == '__main__':
    unittest.main()
