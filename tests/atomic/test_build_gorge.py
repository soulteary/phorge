import importlib.util
import json
from pathlib import Path
import subprocess
from types import SimpleNamespace
import unittest
from unittest import mock

spec = importlib.util.spec_from_file_location(
    "atomic_gorge_builder", Path(__file__).with_name("build_gorge.py"))
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


class PairedBuildTest(unittest.TestCase):
    revision = "a" * 40
    images = {"go": "docker.io/library/golang@sha256:" + "b" * 64,
              "alpine": "docker.io/library/alpine@sha256:" + "c" * 64}

    def commands(self, *, actual=None, status="", lock=None, missing=False):
        calls = []

        def run(command, **_options):
            calls.append(command)
            if command[0] == "git":
                if command[3:] == ["rev-parse", "HEAD"]:
                    output = actual or self.revision
                elif command[3] == "status":
                    output = status
                elif command[3] == "show":
                    if missing:
                        raise subprocess.CalledProcessError(128, command)
                    output = json.dumps({"images": self.images} if lock is None else lock)
                else:
                    raise AssertionError(command)
                return SimpleNamespace(stdout=output, returncode=0)
            self.assertEqual(command[:2], ["docker", "build"])
            return SimpleNamespace(returncode=0)

        return run, calls

    def test_paired_commit_lock_drives_every_fixture_build(self):
        run, calls = self.commands()
        with mock.patch.object(builder, "inspect_bases", return_value={}) as inspect:
            evidence = builder.build("/paired-gorge", self.revision, runner=run)
        inspect.assert_called_once_with(Path("/paired-gorge"), {"images": self.images}, run)
        builds = [command for command in calls if command[0] == "docker"]
        self.assertEqual(5, len(builds))
        self.assertEqual({"SERVICE=gorge-file-storage", "SERVICE=gorge-image",
                          "SERVICE=gorge-render", "SERVICE=gorge-search", "SERVICE=gorge-taskqueue"},
                         {value for command in builds for value in command if value.startswith("SERVICE=")})
        for command in builds:
            self.assertIn("GO_BASE_IMAGE=" + self.images["go"], command)
            self.assertIn("RUNTIME_BASE_IMAGE=" + self.images["alpine"], command)
            self.assertFalse(any(value.startswith("GO_VERSION=") for value in command))
        self.assertIn(["git", "-C", "/paired-gorge", "show",
                       self.revision + ":deploy/release/build-bases.json"], calls)
        self.assertEqual(self.revision, evidence["pairedGorgeRevision"])
        self.assertEqual(64, len(evidence["buildBasesSHA256"]))

    def test_wrong_or_modified_source_never_builds(self):
        for arguments in ({"actual": "b" * 40}, {"status": " M go/Dockerfile"}):
            run, calls = self.commands(**arguments)
            with self.subTest(arguments=arguments), self.assertRaises(ValueError):
                builder.build("/paired-gorge", self.revision, runner=run)
            self.assertFalse(any(command[0] == "docker" for command in calls))

    def test_missing_or_invalid_paired_lock_has_no_production_fallback(self):
        for arguments, error in (({"missing": True}, subprocess.CalledProcessError),
                                 ({"lock": {"images": {"go": self.images["go"]}}}, ValueError)):
            run, calls = self.commands(**arguments)
            with self.subTest(arguments=arguments), self.assertRaises(error):
                builder.build("/paired-gorge", self.revision, runner=run)
            self.assertFalse(any(command[0] == "docker" for command in calls))


if __name__ == "__main__":
    unittest.main()
