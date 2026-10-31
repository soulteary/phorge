import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from types import SimpleNamespace
from unittest import mock

spec = importlib.util.spec_from_file_location("atomic_runner", Path(__file__).with_name("run.py"))
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class IsolationPolicyTest(unittest.TestCase):
    def environment(self):
        return {"ATOMIC_TEST_DISPOSABLE": "1", "GORGE_CONTRACT_REF": "a" * 40,
                "GORGE_TEST_MYSQL_PORT": "3307", "GORGE_TEST_MYSQL_PASSWORD": "test-only",
                "GORGE_TEST_FILE_URL": "http://127.0.0.1:18100",
                "GORGE_TEST_FILE_TOKEN": "test-file",
                "GORGE_TEST_IMAGE_URL": "http://127.0.0.1:18190",
                "GORGE_TEST_IMAGE_TOKEN": "test-image",
                "GORGE_TEST_RENDER_URL": "http://127.0.0.1:18140",
                "GORGE_TEST_RENDER_TOKEN": "test-render"}

    def test_explicit_disposable_loopback_configuration(self):
        config = runner.configuration(self.environment())
        self.assertEqual(config["mysql.host"], "127.0.0.1")
        self.assertEqual(config["gorge.service-policy"], "required")
        self.assertTrue(config["storage.default-namespace"].startswith("atomic_gate_"))
        self.assertNotIn("storage.local-disk.path", config)

    def test_missing_dependencies_and_nonimmutable_revision_fail(self):
        for key in self.environment():
            environment = self.environment()
            environment.pop(key)
            with self.subTest(key=key), self.assertRaises(ValueError):
                runner.configuration(environment)
        environment = self.environment()
        environment["GORGE_CONTRACT_REF"] = "main"
        with self.assertRaises(ValueError):
            runner.configuration(environment)

    def test_production_destinations_and_instance_environment_fail(self):
        for uri in ("https://files.example.com:443", "http://user@localhost:8100",
                    "http://127.0.0.1:8100/rpc", "http://127.0.0.1:8100?q=1"):
            environment = self.environment()
            environment["GORGE_TEST_FILE_URL"] = uri
            with self.subTest(uri=uri), self.assertRaises(ValueError):
                runner.configuration(environment)
        environment = self.environment()
        environment["PHABRICATOR_ENV"] = "production"
        with self.assertRaises(ValueError):
            runner.configuration(environment)

    def test_existing_instance_configuration_is_not_overwritten(self):
        for name in ("local.json", "deployment.json", "ENVIRONMENT"):
            with self.subTest(name=name), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                directory = root / "conf/local"
                directory.mkdir(parents=True)
                existing = directory / name
                existing.write_text("existing instance data")
                with self.assertRaises(ValueError):
                    runner.reserve_configuration(root, {})
                self.assertEqual(existing.read_text(), "existing instance data")

    def test_owned_configuration_is_private_and_removed(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            path, digest = runner.reserve_configuration(root, {"test": "value"})
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            self.assertEqual(json.loads(path.read_text()), {"test": "value"})
            runner.remove_configuration(path, digest)
            self.assertFalse(path.exists())

    def test_symlinked_instance_directory_is_not_used(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "checkout"
            root.mkdir()
            elsewhere = Path(temporary) / "elsewhere"
            elsewhere.mkdir()
            (root / "conf").symlink_to(elsewhere, target_is_directory=True)
            with self.assertRaises(ValueError):
                runner.reserve_configuration(root, {})
            self.assertEqual(list(elsewhere.iterdir()), [])

    def test_user_changes_are_preserved_instead_of_cleanup(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            path, digest = runner.reserve_configuration(root, {})
            path.write_text("changed by user")
            with self.assertRaises(RuntimeError):
                runner.remove_configuration(path, digest)
            self.assertEqual(path.read_text(), "changed by user")

    def test_early_successful_exit_cannot_reuse_old_passed_evidence(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            output = root / "evidence"
            output.mkdir()
            report = output / "native-results.json"
            report.write_text(json.dumps({"passed": True, "results": [
                {"id": "PreviousCheckout::testOld", "result": "pass"}]}))
            with mock.patch.object(runner, "__file__", str(root / "tests/atomic/run.py")), \
                    mock.patch.dict(runner.os.environ, self.environment(), clear=True), \
                    mock.patch.object(runner.subprocess, "run", return_value=SimpleNamespace(returncode=0)), \
                    mock.patch.object(runner.subprocess, "check_output", return_value="b" * 40):
                with self.assertRaisesRegex(RuntimeError, "without test-result evidence"):
                    runner.run(["--package", "D01", "--output", str(output)])
            self.assertFalse((root / "conf/local/local.json").exists())
            self.assertFalse(report.exists())

    def test_only_current_native_evidence_receives_checkout_identity(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            output = root / "evidence"
            output.mkdir()
            report = output / "native-results.json"
            report.write_text(json.dumps({"passed": True, "results": [
                {"id": "PreviousCheckout::testOld", "result": "pass"}]}))

            def native(command, **_options):
                current_report = Path(command[command.index("--report") + 1])
                current_report.write_text(json.dumps({"passed": True, "results": [
                    {"id": "CurrentCheckout::testCurrent", "result": "pass"}]}))
                return SimpleNamespace(returncode=0)

            def git(command, **_options):
                return "b" * 40 if command[-1] == "HEAD" else ""

            with mock.patch.object(runner, "__file__", str(root / "tests/atomic/run.py")), \
                    mock.patch.dict(runner.os.environ, self.environment(), clear=True), \
                    mock.patch.object(runner.subprocess, "run", side_effect=native), \
                    mock.patch.object(runner.subprocess, "check_output", side_effect=git):
                self.assertEqual(0, runner.run(["--package", "D01", "--output", str(output)]))
            evidence = json.loads(report.read_text())
            self.assertEqual([{"id": "CurrentCheckout::testCurrent", "result": "pass"}],
                             evidence["results"])
            self.assertEqual("b" * 40, evidence["gitHead"])
            self.assertFalse((root / "conf/local/local.json").exists())


if __name__ == "__main__":
    unittest.main()
