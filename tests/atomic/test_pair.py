import unittest
from check_pair import validate, resolve


class PairContractTest(unittest.TestCase):
    def test_reviewed_pair(self):
        validate("a" * 40, "a" * 40)

    def test_missing_moving_malformed_and_different_pairs_fail(self):
        for value in (None, "", "main", "a" * 39, "A" * 40, "b" * 40):
            with self.subTest(value=value), self.assertRaises(ValueError):
                validate(value, "a" * 40)

    def test_source_pair_is_used_for_push_and_pull_request(self):
        for event in ("push", "pull_request", "workflow_dispatch"):
            self.assertEqual(resolve(event, "", "a" * 40), "a" * 40)

    def test_override_is_dispatch_only_and_must_equal_source(self):
        self.assertEqual(resolve("workflow_dispatch", "a" * 40, "a" * 40), "a" * 40)
        for event, value in (("push", "a" * 40), ("pull_request", "a" * 40),
                             ("workflow_dispatch", "b" * 40), ("workflow_dispatch", "main")):
            with self.subTest(event=event, value=value), self.assertRaises(ValueError):
                resolve(event, value, "a" * 40)

    def test_workflows_resolve_checked_in_source_not_repository_variable(self):
        from pathlib import Path
        root = Path(__file__).resolve().parents[2]
        for name in ("atomic-feature-regressions.yml", "gorge-runtime-contracts.yml"):
            text = (root / ".github/workflows" / name).read_text()
            self.assertNotIn("vars.GORGE_CONTRACT_REF", text)
            self.assertEqual(text.count("check_pair.py --print-revision"), 2 if name == "gorge-runtime-contracts.yml" else 1)
            self.assertIn('echo "GORGE_CONTRACT_REF=$revision" >> "$GITHUB_ENV"', text)
            self.assertIn("ref: ${{ env.GORGE_CONTRACT_REF }}", text)
        text = (root / ".github/workflows/gorge-runtime-contracts.yml").read_text()
        self.assertIn("github.event_name == 'workflow_dispatch' && inputs.gorge_ref", text)

    def test_actual_cli_ignores_stale_variable_and_guards_dispatch_override(self):
        from pathlib import Path
        import json, os, subprocess, sys
        script = Path(__file__).with_name("check_pair.py")
        expected = json.loads(script.with_name("paired-gorge.json").read_text())["revision"]
        for event, override, passed in (("push", "", True), ("pull_request", "", True),
                                        ("workflow_dispatch", expected, True),
                                        ("workflow_dispatch", "0" * 40, False)):
            env = dict(os.environ, GITHUB_EVENT_NAME=event, GORGE_CONTRACT_REF="55f8ba8e57b179a7dee92ad43c77038ca322f61c", GORGE_REF=override)
            result = subprocess.run([sys.executable, "-B", str(script), "--print-revision"], env=env, capture_output=True, text=True)
            with self.subTest(event=event, override=override):
                if passed:
                    self.assertEqual(result.returncode, 0)
                    self.assertEqual(result.stdout, expected + "\n")
                    self.assertEqual(result.stderr, "")
                else:
                    self.assertNotEqual(result.returncode, 0)
                    self.assertEqual(result.stdout, "")
