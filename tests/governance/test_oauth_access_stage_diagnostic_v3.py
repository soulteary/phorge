"""Pure AST and synthetic shell controls; no workflow, source operation or SQL."""
import ast,json,pathlib,subprocess,tempfile,unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
class StageControls(unittest.TestCase):
 def workflow(self):return (ROOT/'.github/workflows/oauth-access-producer-focused.yml').read_text()
 def helper(self):
  text=self.workflow();a=text.index('          access_phase=source-preflight');b=text.index('          test "$(git rev-parse HEAD)"',a)
  return '\n'.join(x[10:]for x in text[a:b].splitlines())+'\n'
 def test_original_status_retained_for_all_phases_and_diagnostic_failures(self):
  for phase in ('source-preflight','probe-before','native-command','probe-after','receipt-check','result-publication'):
   for code in (1,37,127):
    for fail_publication in (False,True):
     with self.subTest(phase=phase,code=code,fail=fail_publication),tempfile.TemporaryDirectory()as td:
      script='set -euo pipefail\numask 077\n'+self.helper()
      if fail_publication:script+='mkdir() { return 73; }\n'
      script+='access_phase='+phase+'\nexit '+str(code)+'\n'
      env=__import__('os').environ.copy();env.update(RUNNER_TEMP=td,GITHUB_OUTPUT=td+'/output')
      p=subprocess.run(['bash'],input=script,text=True,capture_output=True,env=env,timeout=5)
      self.assertEqual(p.returncode,code);row=json.loads(p.stderr.strip());self.assertEqual(row['phase'],phase);self.assertEqual(row['actual_exit'],code);self.assertFalse(row['runtime_authority']);self.assertFalse(row['SQL_qualified']);self.assertEqual(row['cleanup'],'unknown')
      if fail_publication:self.assertFalse(pathlib.Path(td+'/output').exists())
      else:self.assertEqual(json.loads(pathlib.Path(td+'/oauth-access-stage-failure/diagnostic.json').read_text()),row)
 def test_success_has_no_failure_or_artifact(self):
  with tempfile.TemporaryDirectory()as td:
   env=__import__('os').environ.copy();env['RUNNER_TEMP']=td
   p=subprocess.run(['bash'],input='set -euo pipefail\n'+self.helper()+'exit 0\n',text=True,capture_output=True,env=env,timeout=5);self.assertEqual(p.returncode,0);self.assertEqual(p.stderr,'');self.assertEqual(list(pathlib.Path(td).iterdir()),[])
 def test_all_workflow_shell_blocks_parse_only(self):
  lines=self.workflow().splitlines();count=0
  for i,line in enumerate(lines):
   if line!='        run: |':continue
   body=[]
   for x in lines[i+1:]:
    if not x.startswith('          '):break
    body.append(x[10:])
   p=subprocess.run(['bash','-n'],input='\n'.join(body)+'\n',text=True,capture_output=True,timeout=5);self.assertEqual(p.returncode,0,p.stderr);count+=1
  self.assertGreater(count,5)
 def test_original_native_handler_and_owned_artifacts_retained(self):
  text=self.workflow();self.assertIn('exit "$native_status"',text);self.assertIn('scripts/governance/project_oauth_access_failure.py',text);self.assertIn("steps.oauth-access-owner.outputs.diagnostic_owned == 'true'",text);self.assertIn("steps.oauth-access-owner.outputs.stage_owned == 'true'",text);self.assertIn('oauth-access-stage-failure/diagnostic.json',text)
if __name__=='__main__':unittest.main()
