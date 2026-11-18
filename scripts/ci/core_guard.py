"""Required core CI policy and runner. Full optional suites stay release-only."""
import argparse,hashlib,json,os,re,subprocess,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
def need(ok,label):
 if ok is not True:raise ValueError('core_ci_refused:'+label)
def plan(event,sha,pr_head='',called_head=''):
 need(event in ('pull_request','push','workflow_dispatch'),'event')
 for value in (sha,)+( (pr_head,)if event=='pull_request'else()):need(type(value)is str and re.fullmatch('[0-9a-f]{40}',value)is not None,'head')
 need(type(called_head)is str,'called_head_type')
 if called_head:
  need(re.fullmatch('[0-9a-f]{40}',called_head)is not None and called_head==sha and event!='pull_request','called_exact_head')
  return {'mode':'full','head':called_head,'reason':'release_workflow_call'}
 return {'mode':'full'if event=='workflow_dispatch'else'core','head':pr_head if event=='pull_request'else sha,'reason':'manual_full'if event=='workflow_dispatch'else'required_core'}
def aggregate(mode,results):
 need(mode in ('core','full'),'mode')
 need(set(results)=={'plan','core','native','unit0','unit1','tooling'},'results_keys')
 need(results['plan']=='success'and results['core']=='success','mandatory_core')
 expected='success'if mode=='full'else'skipped'
 need(all(results[k]==expected for k in ('native','unit0','unit1','tooling')),'tier_results')
 return True
def strict_unittest(directory,pattern):
 suite=unittest.TestLoader().discover(str(directory),pattern=pattern)
 count=suite.countTestCases();need(count>0,'empty_unit_selection')
 result=unittest.TextTestRunner(verbosity=2).run(suite)
 need(result.wasSuccessful()and result.testsRun==count and not result.skipped and not result.expectedFailures and not result.unexpectedSuccesses,'unit_failed_skipped_or_incomplete')
 return count
def git(*args):return subprocess.check_output(['git','--no-replace-objects',*args],cwd=ROOT,text=True).strip()
def run_core(expected_head,output):
 need(re.fullmatch('[0-9a-f]{40}',expected_head)is not None and git('rev-parse','HEAD')==expected_head,'actual_checkout')
 need(output.is_absolute()and not output.resolve().is_relative_to(ROOT)and not output.exists(),'fresh_output_outside_source')
 before=git('status','--porcelain=v1','--untracked-files=all');env=dict(os.environ,PYTHONDONTWRITEBYTECODE='1');commands=[['php','tests/atomic/test_gate.php'],['php','tests/atomic/test_timeout.php'],['php','scripts/ci/core_guard.php'],[sys.executable,'-B','scripts/governance/check_contracts.py']]
 # Explicit existing core isolation/source-pair checks; no discovery of optional
 # feature suites and no Docker, database, service startup or fixture producer.
 for name in ('test_pair.py','test_current_pair_consumers.py','test_isolation.py'):
  commands.append([sys.executable,'-B','scripts/ci/core_guard.py','unit','--directory','tests/atomic','--pattern',name])
 commands.append([sys.executable,'-B','scripts/ci/core_guard.py','unit','--directory','tests/governance','--pattern','test_task_ci_native_adopter_v2.py'])
 commands.append([sys.executable,'-B','scripts/ci/core_guard.py','unit','--directory','tests/release','--pattern','test_*.py'])
 commands.append([sys.executable,'-B','scripts/ci/core_guard.py','unit','--directory','tests/ci','--pattern','test_core*.py'])
 rows=[]
 for command in commands:
  done=subprocess.run(command,cwd=ROOT,env=env,capture_output=True,timeout=180);sys.stdout.buffer.write(done.stdout);sys.stderr.buffer.write(done.stderr)
  rows.append({'command':command,'returncode':done.returncode,'stdout_sha256':hashlib.sha256(done.stdout).hexdigest(),'stderr_sha256':hashlib.sha256(done.stderr).hexdigest()})
  need(done.returncode==0,'command_failed')
  if command[-1]=='scripts/ci/core_guard.php':need(json.loads(done.stdout)['passed']is True,'native_summary')
 need(git('status','--porcelain=v1','--untracked-files=all')==before,'no_source_side_effects')
 summary={'schema':'atomic-core-results-v1','source_revision':expected_head,'source_tree':git('rev-parse','HEAD^{tree}'),'mode':'core','passed':True,'commands':rows,'optional_full_qualified':False,'source_worktree_unchanged':True}
 output.parent.mkdir(parents=True,exist_ok=True);output.write_text(json.dumps(summary,indent=2)+'\n');output.chmod(0o600);return summary
def main():
 parser=argparse.ArgumentParser(allow_abbrev=False);sub=parser.add_subparsers(dest='action',required=True)
 p=sub.add_parser('plan');p.add_argument('--event',required=True);p.add_argument('--sha',required=True);p.add_argument('--pr-head',default='');p.add_argument('--called-head',default='')
 p=sub.add_parser('run');p.add_argument('--expected-head',required=True);p.add_argument('--output',type=Path,required=True)
 p=sub.add_parser('aggregate');p.add_argument('--mode',required=True)
 p=sub.add_parser('unit');p.add_argument('--directory',type=Path,required=True);p.add_argument('--pattern',required=True)
 args=parser.parse_args()
 if args.action=='plan':
  value=plan(args.event,args.sha,args.pr_head,args.called_head);need(git('rev-parse','HEAD')==value['head'],'plan_actual_checkout')
  for key,value in value.items():print(key+'='+value)
 elif args.action=='unit':strict_unittest(ROOT/args.directory,args.pattern)
 elif args.action=='aggregate':aggregate(args.mode,{k:os.environ.get('CORE_RESULT_'+k.upper(),'')for k in ('plan','core','native','unit0','unit1','tooling')})
 else:run_core(args.expected_head,args.output)
if __name__=='__main__':main()
