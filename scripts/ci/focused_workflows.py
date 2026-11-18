"""Closed full-tier workflow inventory and exact caller-head binding.
This does not replace Root signature, source admission, SQL or receipt checks.
"""
import argparse,json,os,re,shlex,subprocess,sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
FOCUSED=tuple(name+'.yml'for name in (
 'assessment-credential-focused','conduit-producer-focused',
 'oauth-access-producer-focused','oauth-authorization-producer-focused',
 'oauth-code-producer-focused','oauth-client-lifecycle-focused',
 'native-directory-transports-v1','native-media-session-positive-sql',
 'native-rr-owner-build','private-mail-wire',
 'project-content-grants-focused','project-description-focused'))
FULL=('atomic-feature-regressions.yml',)+FOCUSED
PROOF_FOCUSED=tuple(n for n in FOCUSED if n not in ('native-rr-owner-build.yml','private-mail-wire.yml'))
PROOF_ENV='echo "PHORGE_CI_MERGE_SOURCE_PROOF=source-proof/$EXPECTED_SOURCE_CHECKPOINT" >> "$GITHUB_ENV"'
PROOF_ARGV=['python3','-B','scripts/governance/check_task_ci_head_v1.py','--root','$GITHUB_WORKSPACE',
 '--event-file','$GITHUB_EVENT_PATH','--event-name','$GITHUB_EVENT_NAME','--expected-head','$EXPECTED_SOURCE_CHECKPOINT',
 '--github-sha','$GITHUB_SHA','--trust-home','$trust_home','--external-proof-tag','source-proof/$EXPECTED_SOURCE_CHECKPOINT']
BIND_STEP='Require exact full-tier caller checkpoint'
BIND_RUN='python3 -B scripts/ci/focused_workflows.py bind-head --expected-head "$FOCUSED_EXPECTED_HEAD"\n'
def need(ok,label):
 if ok is not True:raise ValueError('focused_full_ci_refused:'+label)
def bind_head(expected,event_name,github_sha,event,checkout):
 need(event_name in ('push','workflow_dispatch'),'full_event')
 need(type(expected)is str and re.fullmatch('[a-f0-9]{40}',expected)is not None and expected!='0'*40,'expected_head')
 need(expected==github_sha==checkout,'exact_caller_checkout')
 # Reuse the existing event binding rather than inventing workflow_call events.
 sys.path.insert(0,str(ROOT/'scripts/governance'))
 from check_task_ci_head_v1 import bind_event
 result=bind_event(event_name,event,expected,github_sha)
 return {**result,'tier':'full','Root_signature_or_SQL_qualified':False}
def focused_contract(document,workflow=None):
 need(set(document['on'])=={'workflow_call','workflow_dispatch'},'full_only_triggers')
 for trigger in ('workflow_call','workflow_dispatch'):
  spec=document['on'][trigger]['inputs'];need(set(spec)=={'expected_head'},'closed_inputs')
  need(spec['expected_head']['required']=='true'and spec['expected_head']['type']=='string','required_exact_head')
 need(document['permissions']=={'contents':'read'},'read_only_permissions')
 for job in document['jobs'].values():
  need('if'not in job and 'continue-on-error'not in job,'no_silent_job_skip')
  steps=job['steps'];binds=[s for s in steps if s.get('name')==BIND_STEP];need(len(binds)==1,'one_binding')
  bind=binds[0];need(bind.get('env')=={'FOCUSED_EXPECTED_HEAD':'${{ inputs.expected_head }}'}and bind.get('run')==BIND_RUN and 'if'not in bind and 'continue-on-error'not in bind,'exact_binding')
  checkout=next(i for i,s in enumerate(steps)if s.get('uses','').startswith('actions/checkout@'))
  need(steps.index(bind)==checkout+1,'binding_before_runtime')
  admissions=[s for s in steps if 'check_task_ci_head_v1.py --root' in s.get('run','')]
  if workflow in PROOF_FOCUSED or admissions:
   need(len(admissions)==1,'one_source_proof_admission')
   admission=admissions[0];need('if'not in admission and 'continue-on-error'not in admission,'proof_not_skipped')
   need(job.get('env',{}).get('EXPECTED_SOURCE_CHECKPOINT')=='${{ github.event.pull_request.head.sha || github.sha }}','proof_exact_checkpoint')
   lines=[line.strip()for line in admission['run'].replace('\\\n','').splitlines()]
   commands=[(i,shlex.split(line))for i,line in enumerate(lines)if 'check_task_ci_head_v1.py --root' in line]
   need(len(commands)==1 and commands[0][1]==PROOF_ARGV,'exact_merge_proof_request')
   need(lines.count(PROOF_ENV)==1 and lines.index(PROOF_ENV)>commands[0][0],'source_owner_proof_environment')
   need(steps.index(admission)==steps.index(bind)+1,'proof_before_runtime')
 return True
def fanout_contract(document,baseline):
 need(tuple(baseline['releaseFullWorkflows'])==FULL,'documented_full_inventory')
 need(set(document['on'])=={'workflow_call'},'full_is_reusable')
 jobs=document['jobs'];calls={k:j for k,j in jobs.items()if 'uses'in j};expected={'./.github/workflows/'+n for n in FULL}
 need(len(calls)==len(FULL)and {j['uses']for j in calls.values()}==expected,'complete_disjoint_fanout')
 for job in calls.values():
  need(set(job)=={'uses','with'}and job['with']=={'expected_head':'${{ inputs.expected_head }}'},'exact_same_commit_calls')
 need(set(jobs)==set(calls)|{'qualified'},'closed_full_jobs')
 gate=jobs['qualified'];need(set(gate['needs'])==set(calls)and len(gate['needs'])==len(calls)and gate.get('if')=='always()','all_results_required')
 need('continue-on-error'not in gate,'no_gate_waiver')
 return True
def inventory(root=ROOT):
 import yaml
 need(yaml.__version__=='6.0.3','yaml_version')
 load=lambda p:yaml.load(p.read_text(),Loader=yaml.BaseLoader)
 workflows=root/'.github/workflows'
 for name in FOCUSED:focused_contract(load(workflows/name),name)
 fanout_contract(load(workflows/'release-full.yml'),json.loads((root/'docs/ci/support-baseline.json').read_text()))
 return {'focused_full_workflows':len(FOCUSED),'release_full_workflows':len(FULL),'full_fanout_complete':True,'runtime_SQL_executed':False}
def main():
 parser=argparse.ArgumentParser(allow_abbrev=False);sub=parser.add_subparsers(dest='action',required=True)
 p=sub.add_parser('bind-head');p.add_argument('--expected-head',required=True);sub.add_parser('inventory');args=parser.parse_args()
 if args.action=='inventory':result=inventory()
 else:
  sys.path.insert(0,str(ROOT/'scripts/governance'))
  from check_task_ci_head_v1 import read_event,decode
  event=decode(read_event(os.environ['GITHUB_EVENT_PATH']));env={k:v for k,v in os.environ.items()if not k.startswith('GIT_')};env.update(GIT_NO_LAZY_FETCH='1',GIT_TERMINAL_PROMPT='0')
  checkout=subprocess.check_output(['git','--no-replace-objects','-c','core.fsmonitor=false','-c','core.hooksPath=/dev/null','-C',str(ROOT),'rev-parse','HEAD'],text=True,env=env).strip()
  result=bind_head(args.expected_head,os.environ['GITHUB_EVENT_NAME'],os.environ['GITHUB_SHA'],event,checkout)
 print(json.dumps(result,sort_keys=True))
if __name__=='__main__':main()
