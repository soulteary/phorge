import pathlib, unittest, yaml
ROOT=pathlib.Path(__file__).resolve().parents[2]
class WorkflowContract(unittest.TestCase):
 def workflow(self):return yaml.safe_load((ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text())
 def test_call_requires_exact_head_without_core_downgrade_input(self):
  w=self.workflow();events=w.get('on',w.get(True));call=events['workflow_call'];self.assertEqual(set(call['inputs']),{'expected_head'});self.assertEqual(call['inputs']['expected_head']['required'],True);self.assertEqual(call['inputs']['expected_head']['type'],'string');self.assertEqual(events['workflow_dispatch'],None)
 def test_every_heavy_job_runs_full_only_and_core_is_unconditional(self):
  jobs=self.workflow()['jobs'];core=jobs['core-boundaries'];self.assertNotIn('if',core);self.assertNotIn('services',core);self.assertEqual(core['needs'],'ci-plan')
  for name in ('native-packages','governance-unit-tests-0','governance-unit-tests-1','governance-tooling-tests'):
   self.assertEqual(jobs[name]['needs'],'ci-plan');self.assertEqual(jobs[name]['if'],"${{ needs.ci-plan.outputs.mode == 'full' }}")
 def test_required_context_failclosed_and_union_remains_full(self):
  gate=self.workflow()['jobs']['native-packages-gate'];self.assertEqual(gate['name'],'Native packages (PHP 8.5.13)');self.assertEqual(gate['if'],'${{ always() }}');self.assertEqual(set(gate['needs']),{'ci-plan','core-boundaries','native-packages','governance-unit-tests-0','governance-unit-tests-1','governance-tooling-tests'})
  checks=[s for s in gate['steps']if s.get('name','').startswith('Gate /')and 'tier policy'not in s['name']]
  self.assertTrue(checks)
  for step in checks:self.assertEqual(step['if'],"${{ needs.ci-plan.outputs.mode == 'full' }}")
  self.assertTrue(any('exact disjoint successful union'in s.get('name','')for s in checks));self.assertEqual(gate['steps'][-1]['id'],'full-qualified')
 def test_core_admits_original_signed_source_before_any_real_tests(self):
  steps=self.workflow()['jobs']['core-boundaries']['steps'];signature=next(x for x in steps if x.get('name')=='Admit the exact externally bound Root-signed source');self.assertNotIn('if',signature)
  code=signature['run'];self.assertNotIn('HOME=',code)
  for value in ['check_task_ci_head_v1.py --prepare-public-trust','support/governance/source-operation/ci-source-verifier.asc','--event-file "$GITHUB_EVENT_PATH" --event-name "$GITHUB_EVENT_NAME"','--expected-head "$CORE_EXPECTED_HEAD" --github-sha "$GITHUB_SHA" --trust-home "$trust_home"']:self.assertIn(value,code)
  self.assertEqual(signature['env']['CORE_EXPECTED_HEAD'],'${{ needs.ci-plan.outputs.head }}');self.assertLess(steps.index(signature),next(i for i,x in enumerate(steps)if x.get('name')=='Run actual fast OFF and native core checks'))
 def test_core_executes_real_guard_with_pinned_yaml_and_source_head(self):
  core=self.workflow()['jobs']['core-boundaries'];text=str(core);self.assertIn('PyYAML==6.0.4',text);self.assertIn('scripts/ci/core_guard.py run --expected-head',text);self.assertIn('${{ needs.ci-plan.outputs.head }}',text)
if __name__=='__main__':unittest.main()
