"""Use real disposable Git repositories; PHP/database work is not needed here."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from types import SimpleNamespace
from unittest import mock


HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("provenance_runner", HERE / "run.py")
RUNNER = importlib.util.module_from_spec(spec)
spec.loader.exec_module(RUNNER)
spec = importlib.util.spec_from_file_location(
    "provenance_http", HERE.parent / "project-workspace/serve.py")
HTTP = importlib.util.module_from_spec(spec)
spec.loader.exec_module(HTTP)
REAL_RUN = subprocess.run


class SourceProvenanceTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)
        self.root = self.base / "phorge"
        self.root.mkdir()
        self.git("init", "--quiet")
        self.git("config", "user.name", "Atomic Test")
        self.git("config", "user.email", "atomic@example.invalid")
        self.git("config", "commit.gpgsign", "false")
        (self.root / ".gitignore").write_text("conf/local/*\n")
        (self.root / "source.php").write_text("original source\n")
        self.git("add", ".")
        self.git("commit", "--quiet", "-m", "Initial disposable source")
        self.output = self.base / "evidence"
        self.native_calls = 0

    def git(self, *arguments):
        return REAL_RUN(["git", *arguments], cwd=self.root, text=True,
                        capture_output=True, check=True).stdout.strip()

    def environment(self):
        return {"ATOMIC_TEST_DISPOSABLE": "1", "GORGE_CONTRACT_REF": "a" * 40,
                "PHABRICATOR_ENV": "",
                "GORGE_TEST_MYSQL_PORT": "3307", "GORGE_TEST_MYSQL_PASSWORD": "test-only",
                "GORGE_TEST_FILE_URL": "http://127.0.0.1:18100",
                "GORGE_TEST_FILE_TOKEN": "test-file",
                "GORGE_TEST_IMAGE_URL": "http://127.0.0.1:18190",
                "GORGE_TEST_IMAGE_TOKEN": "test-image",
                "GORGE_TEST_RENDER_URL": "http://127.0.0.1:18140",
                "GORGE_TEST_RENDER_TOKEN": "test-render"}

    def run_native(self, *, strict=True, mutation=None, produce_evidence=True):
        def child(command, **options):
            if command[0] == "git":
                return REAL_RUN(command, **options)
            self.assertEqual(["php", "tests/atomic/run.php"], command[:2])
            self.native_calls += 1
            if mutation:
                mutation()
            if produce_evidence:
                report = Path(command[command.index("--report") + 1])
                report.write_text(json.dumps({"passed": True, "results": [
                    {"id": "DisposableTest::testCurrent", "result": "pass"}]}))
            return SimpleNamespace(returncode=0)

        args = ["--output", str(self.output)]
        if strict:
            args.append("--require-clean-worktree")
        with mock.patch.object(RUNNER, "__file__", str(self.root / "tests/atomic/run.py")), \
                mock.patch.dict(os.environ, self.environment()), \
                mock.patch.object(subprocess, "run", side_effect=child):
            status = RUNNER.run(args)
        report = json.loads((self.output / "native-results.json").read_text())
        return status, report

    def test_nested_paired_checkout_is_dirty_until_relocated_outside_source(self):
        fixture = self.root / ".test-gorge"
        fixture.mkdir()
        REAL_RUN(["git", "init", "--quiet", str(fixture)], check=True)
        (fixture / "paired.go").write_text("synthetic paired source\n")
        self.assertTrue(RUNNER.capture_source(self.root)["worktreeDirty"])
        fixture.rename(self.base / "paired-gorge")
        status, report = self.run_native()
        self.assertEqual(0, status)
        self.assertTrue(report["passed"])
        self.assertFalse(report["worktreeDirty"])
        self.assertEqual(self.git("rev-parse", "HEAD"), report["gitHead"])
        self.assertFalse(report["sourceProvenance"]["before"]["worktreeDirty"])
        self.assertFalse(report["sourceProvenance"]["after"]["worktreeDirty"])

    def test_dirty_preflight_replaces_stale_success_and_never_runs_php(self):
        self.output.mkdir()
        (self.output / "native-results.json").write_text('{"passed":true}')
        (self.root / "source.php").write_text("changed source\n")
        status, report = self.run_native()
        self.assertEqual(1, status)
        self.assertFalse(report["passed"])
        self.assertEqual(0, self.native_calls)
        self.assertIn("source.php", report["sourceProvenance"]["before"]["worktreeStatus"])
        self.assertNotIn("changed source", json.dumps(report))
        self.assertFalse((self.root / "conf/local/local.json").exists())

    def test_untracked_source_is_rejected_before_php(self):
        (self.root / "new-source.php").write_text("untracked source\n")
        status, report = self.run_native()
        self.assertEqual(1, status)
        self.assertFalse(report["passed"])
        self.assertEqual(0, self.native_calls)
        self.assertIn("?? new-source.php", report["sourceProvenance"]["before"]["worktreeStatus"])

    def test_strict_configuration_refusal_cannot_leave_old_passed_evidence(self):
        self.output.mkdir()
        report_path = self.output / "native-results.json"
        report_path.write_text('{"passed":true}')
        with mock.patch.object(RUNNER, "configuration", side_effect=ValueError("Refused configuration")), \
                self.assertRaisesRegex(ValueError, "Refused configuration"):
            self.run_native()
        self.assertFalse(json.loads(report_path.read_text())["passed"])
        self.assertEqual(0, self.native_calls)

    def test_strict_successful_exit_without_current_results_publishes_failure(self):
        self.output.mkdir()
        report_path = self.output / "native-results.json"
        report_path.write_text('{"passed":true}')
        with self.assertRaisesRegex(RuntimeError, "without test-result evidence"):
            self.run_native(produce_evidence=False)
        self.assertFalse(json.loads(report_path.read_text())["passed"])
        self.assertFalse((self.root / "conf/local/local.json").exists())

    def test_dirty_preflight_does_not_modify_foreign_log_symlink_target(self):
        self.output.mkdir()
        foreign = self.base / "foreign.log"
        original = "foreign test-only private log"
        foreign.write_text(original)
        (self.output / "native.log").symlink_to(foreign)
        (self.root / "source.php").write_text("local source\n")
        status, report = self.run_native()
        self.assertEqual(1, status)
        self.assertFalse(report["passed"])
        self.assertEqual(0, self.native_calls)
        self.assertEqual(original, foreign.read_text())
        self.assertTrue((self.output / "native.log").is_symlink())

    def test_clean_preflight_refuses_symlink_log_before_child_write(self):
        self.output.mkdir()
        foreign = self.base / "foreign.log"
        foreign.write_text("private content")
        (self.output / "native.log").symlink_to(foreign)
        with self.assertRaisesRegex(ValueError, "symlinked native log"):
            self.run_native()
        self.assertEqual("private content", foreign.read_text())
        self.assertEqual(0, self.native_calls)
        self.assertFalse(json.loads((self.output / "native-results.json").read_text())["passed"])

    def test_symlink_report_refused_without_modifying_its_target(self):
        self.output.mkdir()
        foreign = self.base / "foreign.json"
        original = '{"passed":true,"owner":"another run"}'
        foreign.write_text(original)
        (self.output / "native-results.json").symlink_to(foreign)
        with self.assertRaisesRegex(ValueError, "symlinked native result"):
            self.run_native()
        self.assertEqual(original, foreign.read_text())
        self.assertTrue((self.output / "native-results.json").is_symlink())
        self.assertEqual(0, self.native_calls)

    def test_replaced_owned_log_is_preserved_and_cannot_publish_success(self):
        foreign = self.base / "foreign.log"
        foreign.write_text("private test-only content")

        def replace_log():
            (self.output / "native.log").unlink()
            (self.output / "native.log").symlink_to(foreign)

        with self.assertRaisesRegex(RuntimeError, "Native log evidence was replaced"):
            self.run_native(mutation=replace_log)
        self.assertEqual("private test-only content", foreign.read_text())
        self.assertTrue((self.output / "native.log").is_symlink())
        self.assertFalse(json.loads((self.output / "native-results.json").read_text())["passed"])
        self.assertFalse((self.root / "conf/local/local.json").exists())

    def test_during_run_report_symlink_cannot_overwrite_foreign_target(self):
        foreign = self.base / "foreign.json"
        original = '{"owner":"another run"}'
        foreign.write_text(original)

        def replace_report():
            (self.output / "native-results.json").symlink_to(foreign)

        with self.assertRaisesRegex(RuntimeError, "Result evidence was replaced"):
            self.run_native(mutation=replace_report)
        self.assertEqual(original, foreign.read_text())
        self.assertTrue((self.output / "native-results.json").is_symlink())
        self.assertFalse((self.root / "conf/local/local.json").exists())
        self.assertEqual([], list(self.output.glob(".atomic-report-*")))

    def test_during_run_report_file_is_not_overwritten(self):
        report_path = self.output / "native-results.json"
        original = '{"owner":"another run"}'
        with self.assertRaisesRegex(RuntimeError, "Result evidence was replaced"):
            self.run_native(mutation=lambda: report_path.write_text(original))
        self.assertEqual(original, report_path.read_text())
        self.assertFalse((self.root / "conf/local/local.json").exists())

    def test_shared_http_report_publication_refuses_existing_symlink(self):
        self.output.mkdir()
        foreign = self.base / "foreign.json"
        original = '{"owner":"another run"}'
        foreign.write_text(original)
        report_path = self.output / "http-results.json"
        report_path.symlink_to(foreign)
        with self.assertRaisesRegex(RuntimeError, "Result evidence was replaced"):
            HTTP.GATE.publish_report(report_path, {"passed": True})
        self.assertEqual(original, foreign.read_text())

    def test_tracked_change_during_successful_native_run_fails(self):
        status, report = self.run_native(
            mutation=lambda: (self.root / "source.php").write_text("changed during test\n"))
        self.assertEqual(1, status)
        self.assertFalse(report["passed"])
        self.assertFalse(report["sourceProvenance"]["before"]["worktreeDirty"])
        self.assertTrue(report["sourceProvenance"]["after"]["worktreeDirty"])
        self.assertTrue(any("after testing" in error for error in report["sourceErrors"]))

    def test_untracked_change_during_successful_native_run_fails(self):
        status, report = self.run_native(
            mutation=lambda: (self.root / "new-source.php").write_text("added during test\n"))
        self.assertEqual(1, status)
        self.assertFalse(report["passed"])
        self.assertIn("?? new-source.php", report["sourceProvenance"]["after"]["worktreeStatus"])

    def test_clean_head_change_during_successful_native_run_fails(self):
        status, report = self.run_native(
            mutation=lambda: self.git("commit", "--quiet", "--allow-empty", "-m", "Changed HEAD"))
        self.assertEqual(1, status)
        self.assertFalse(report["passed"])
        self.assertFalse(report["worktreeDirty"])
        self.assertNotEqual(report["sourceProvenance"]["before"]["gitHead"], report["gitHead"])
        self.assertIn("Phorge HEAD changed while testing.", report["sourceErrors"])

    def test_local_debug_mode_keeps_dirty_results_explicit_without_ci_claim(self):
        (self.root / "source.php").write_text("local TDD change\n")
        status, report = self.run_native(strict=False)
        self.assertEqual(0, status)
        self.assertTrue(report["passed"])
        self.assertTrue(report["worktreeDirty"])
        self.assertFalse(report["sourceProvenance"]["requireCleanWorktree"])

    def test_http_dirty_preflight_fails_before_configuration_or_storage(self):
        (self.root / "source.php").write_text("local source\n")
        with mock.patch.object(HTTP, "ROOT", self.root), \
                mock.patch.object(HTTP.GATE, "configuration") as configuration:
            status = HTTP.main(["--output", str(self.output), "--exit-after-http",
                                "--require-clean-worktree"])
        configuration.assert_not_called()
        report = json.loads((self.output / "http-results.json").read_text())
        self.assertEqual(1, status)
        self.assertFalse(report["passed"])
        self.assertEqual([], report["checks"])
        self.assertTrue(report["worktreeDirty"])

    def test_http_rejects_existing_output_without_overwriting_foreign_evidence(self):
        self.output.mkdir()
        existing = self.output / "http-results.json"
        original = '{"passed":true,"owner":"another run"}'
        existing.write_text(original)
        with mock.patch.object(HTTP, "ROOT", self.root), self.assertRaises(FileExistsError):
            HTTP.main(["--output", str(self.output), "--require-clean-worktree"])
        self.assertEqual(original, existing.read_text())


if __name__ == "__main__":
    unittest.main()
