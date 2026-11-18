import importlib.util
import json
import os
import re
import subprocess
import tempfile
import textwrap
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'deploy/release'))
spec = importlib.util.spec_from_file_location('ci_policy', ROOT / 'deploy/release/ci_policy.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class ReleaseCIPolicyTests(unittest.TestCase):
    def values(self):
        sha = 'a' * 40
        ref = 'refs/tags/2026.11.18-r1'
        return ['soulteary/phorge', '2026.11.18-r1', sha, ref,
                'soulteary/phorge/.github/workflows/container-image.yml@' + ref,
                sha, sha, sha]

    def test_exact_same_tag_workflow_and_source(self):
        self.assertEqual(m.require_release_identity(*self.values())['sourceRevision'], 'a' * 40)

    def test_branch_dispatch_cannot_select_tag_checkout(self):
        values = self.values()
        values[3] = 'refs/heads/main'
        values[4] = 'soulteary/phorge/.github/workflows/container-image.yml@refs/heads/main'
        with self.assertRaises(ValueError):
            m.require_release_identity(*values)

    def test_workflow_checkout_and_retargeted_tag_drift_each_refuse(self):
        for index, value in [(4, 'soulteary/phorge/.github/workflows/other.yml@' + self.values()[3]),
                             (5, 'b' * 40), (6, 'b' * 40), (7, 'b' * 40)]:
            values = self.values()
            values[index] = value
            with self.subTest(index=index), self.assertRaises(ValueError):
                m.require_release_identity(*values)

    def test_full_success_is_not_interchangeable_with_core_or_another_commit(self):
        m.require_full_result('a' * 40, 'a' * 40, 'success')
        for result in ['skipped', 'cancelled', 'failure', '', None]:
            with self.subTest(result=result), self.assertRaises(ValueError):
                m.require_full_result('a' * 40, 'a' * 40, result)
        for head in ['', None, 'b' * 40]:
            with self.subTest(head=head), self.assertRaises(ValueError):
                m.require_full_result('a' * 40, head, 'success')

    def test_actual_full_aggregate_refuses_any_missing_or_unsuccessful_job(self):
        policy = json.loads((ROOT / 'docs/ci/support-baseline.json').read_text())
        keys = [name.removesuffix('.yml').replace('-', '_') for name in policy['releaseFullWorkflows']]
        workflow = (ROOT / '.github/workflows/release-full.yml').read_text()
        script = textwrap.dedent(re.search(r"python3 - <<'PY'\n(.*?)\n          PY", workflow, re.S).group(1))
        good = {key: {'result': 'success'} for key in keys}
        good['atomic_feature_regressions']['outputs'] = {'qualified_head': 'a' * 40}
        cases = [(good, 'a' * 40, True)]
        for status in ('skipped', 'cancelled', 'failure'):
            changed = dict(good)
            changed[keys[-1]] = {'result': status}
            cases.append((changed, 'a' * 40, False))
        core_only = dict(good)
        core_only['atomic_feature_regressions'] = {'result': 'success', 'outputs': {}}
        cases.append((core_only, 'a' * 40, False))
        missing = dict(good)
        missing.pop(keys[-1])
        cases.extend([(missing, 'a' * 40, False), (good, 'b' * 40, False)])
        for results, source, accepted in cases:
            with tempfile.TemporaryDirectory() as temporary:
                output = Path(temporary) / 'output'
                env = dict(os.environ, EXPECTED_HEAD='a' * 40, SOURCE_HEAD=source,
                           RESULTS=json.dumps(results), GITHUB_OUTPUT=str(output))
                result = subprocess.run([sys.executable, '-I', '-c', script], env=env,
                                        capture_output=True, timeout=5)
                self.assertEqual(result.returncode == 0, accepted)
                if accepted:
                    self.assertEqual(output.read_text(), 'head=' + 'a' * 40 + '\n')
                else:
                    self.assertFalse(output.exists())

    def test_support_policy_keeps_historical_schema_fixture_without_expanding_support(self):
        policy = json.loads((ROOT / 'docs/ci/support-baseline.json').read_text())
        self.assertEqual(policy['productionPHP'], '8.5.12')
        self.assertEqual(policy['historicalSchemaWorkflow'], 'gorge-runtime-contracts.yml')
        self.assertTrue((ROOT / '.github/workflows' / policy['historicalSchemaWorkflow']).is_file())
        self.assertFalse(policy['engineUpgradeQualifiedByHistoricalFixture'])
        self.assertFalse(policy['arbitraryStartingReleaseUpgradeSupport'])
        self.assertFalse(policy['nativeTrialRequiresN09'])
        self.assertFalse(policy['runtimeFixtureIsFormalPairQualification'])
        self.assertEqual(len(policy['releaseFullWorkflows']), len(set(policy['releaseFullWorkflows'])))
        for workflow in policy['releaseFullWorkflows']:
            self.assertTrue((ROOT / '.github/workflows' / workflow).is_file())
        self.assertTrue(policy['retirementRequires'])


if __name__ == '__main__':
    unittest.main()
