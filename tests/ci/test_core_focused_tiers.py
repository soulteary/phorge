"""Full-only focused routing and metadata binding; never SQL/signature proof."""
import ast,copy,importlib.util,json,pathlib,subprocess,unittest
import yaml
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('focused_workflows',ROOT/'scripts/ci/focused_workflows.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
BASE='7b23c47e329f32e4fe083d0efa23fd82d6eefe6f'
def parse(raw):return yaml.load(raw,Loader=yaml.BaseLoader)
class FocusedInventory(unittest.TestCase):
 def workflow(self,name):return parse((ROOT/'.github/workflows'/name).read_text())
 def baseline(self):return json.loads((ROOT/'docs/ci/support-baseline.json').read_text())
 def test_actual_twelve_full_only_and_thirteen_fanout(self):
  self.assertEqual(m.inventory(ROOT)['focused_full_workflows'],12);self.assertEqual(len(m.FULL),13)
 def test_every_original_runtime_job_body_preserved(self):
  # The reviewed pre-tier bodies are DATA regression oracles; CURRENT bodies
  # still execute. No old workflow is executed or substituted as authority.
  for name in m.FOCUSED:
   with self.subTest(workflow=name):
    raw=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show',BASE+':.github/workflows/'+name],timeout=10)
    original=parse(raw);current=self.workflow(name)
    self.assertEqual(set(original['jobs']),set(current['jobs']))
    for job in current['jobs'].values():
     job['steps']=[s for s in job['steps']if s.get('name')!=m.BIND_STEP]
     if name=='native-rr-owner-build.yml':
      for key,value in {'NATIVE_RR_EXPECTED_HEAD':'${{ inputs.expected_head }}','NATIVE_RR_WORKFLOW_SHA':'${{ github.workflow_sha }}','NATIVE_RR_RELEASE_TAG':'${{ github.event.inputs.release_tag }}'}.items():
       self.assertEqual(job['env'].pop(key),value)
     if name in m.PROOF_FOCUSED:
      admission=next(s for s in job['steps']if 'check_task_ci_head_v1.py --root' in s.get('run',''))
      flag=' --external-proof-tag "source-proof/$EXPECTED_SOURCE_CHECKPOINT"'
      self.assertEqual(admission['run'].count(flag),1)
      self.assertEqual(admission['run'].count(m.PROOF_ENV),1)
      admission['run']=admission['run'].replace(flag,'').replace(m.PROOF_ENV+'\n','')
    self.assertEqual(current['jobs'],original['jobs'])
    for key in set(original)-{'on','jobs'}:self.assertEqual(current[key],original[key])
 def test_all_ten_source_workflows_bind_exact_proof_and_owner_environment(self):
  self.assertEqual(len(m.PROOF_FOCUSED),10)
  for name in m.PROOF_FOCUSED:
   original=self.workflow(name);m.focused_contract(original,name)
   for mutation in ('missing_flag','github_sha','ancestor','missing_env','wrong_env','early_env','duplicate_flag','duplicate_env','wrong_checkpoint','late','skip','missing_step'):
    d=copy.deepcopy(original);job=next(iter(d['jobs'].values()))
    step=next(s for s in job['steps']if 'check_task_ci_head_v1.py --root' in s.get('run',''))
    flag='--external-proof-tag "source-proof/$EXPECTED_SOURCE_CHECKPOINT"'
    if mutation=='missing_flag':step['run']=step['run'].replace(flag,'')
    elif mutation=='github_sha':step['run']=step['run'].replace(flag,'--external-proof-tag "source-proof/$GITHUB_SHA"')
    elif mutation=='ancestor':step['run']=step['run'].replace(flag,'--external-proof-tag "source-proof/ancestor"')
    elif mutation=='missing_env':step['run']=step['run'].replace(m.PROOF_ENV,'')
    elif mutation=='wrong_env':step['run']=step['run'].replace(m.PROOF_ENV,m.PROOF_ENV.replace('$EXPECTED_SOURCE_CHECKPOINT','$GITHUB_SHA'))
    elif mutation=='early_env':step['run']=m.PROOF_ENV+'\n'+step['run'].replace(m.PROOF_ENV+'\n','')
    elif mutation=='duplicate_flag':step['run']=step['run'].replace(flag,flag+' '+flag)
    elif mutation=='duplicate_env':step['run']+=m.PROOF_ENV+'\n'
    elif mutation=='wrong_checkpoint':job['env']['EXPECTED_SOURCE_CHECKPOINT']='${{ inputs.expected_head }}'
    elif mutation=='late':job['steps'].append(job['steps'].pop(job['steps'].index(step)))
    elif mutation=='skip':step['if']='false'
    else:job['steps'].remove(step)
    with self.subTest(workflow=name,mutation=mutation),self.assertRaises(ValueError):m.focused_contract(d,name)
 def test_PR_push_trigger_or_missing_required_checkpoint_refused(self):
  original=self.workflow(m.FOCUSED[0])
  for mutation in ('PR','push','optional','wrongtype','extra'):
   d=copy.deepcopy(original)
   if mutation=='PR':d['on']['pull_request']={}
   elif mutation=='push':d['on']['push']={}
   elif mutation=='optional':d['on']['workflow_call']['inputs']['expected_head']['required']='false'
   elif mutation=='wrongtype':d['on']['workflow_dispatch']['inputs']['expected_head']['type']='boolean'
   else:d['on']['workflow_call']['inputs']['skip']={'type':'boolean'}
   with self.subTest(mutation=mutation),self.assertRaises(ValueError):m.focused_contract(d)
 def test_skipped_binding_changed_source_or_runtime_before_binding_refused(self):
  original=self.workflow(m.FOCUSED[0])
  for mode in ('skipjob','skipstep','wronghead','late','missing','extra'):
   d=copy.deepcopy(original);j=next(iter(d['jobs'].values()));steps=j['steps'];i=next(i for i,s in enumerate(steps)if s.get('name')==m.BIND_STEP)
   if mode=='skipjob':j['if']='false'
   elif mode=='skipstep':steps[i]['if']='false'
   elif mode=='wronghead':steps[i]['env']['FOCUSED_EXPECTED_HEAD']='${{ github.sha }}'
   elif mode=='late':steps.insert(i+2,steps.pop(i))
   elif mode=='missing':steps.pop(i)
   else:steps.append(copy.deepcopy(steps[i]))
   with self.subTest(mode=mode),self.assertRaises(ValueError):m.focused_contract(d)
 def test_missing_duplicate_remote_or_different_head_fanout_refused(self):
  original=self.workflow('release-full.yml');baseline=self.baseline();ids=[k for k,j in original['jobs'].items()if 'uses'in j]
  for mode in ('missing','duplicate','remote','head','skip','continuing','needs'):
   d=copy.deepcopy(original);jobs=d['jobs'];first=jobs[ids[0]]
   if mode=='missing':del jobs[ids[-1]]
   elif mode=='duplicate':jobs[ids[-1]]['uses']=first['uses']
   elif mode=='remote':first['uses']='soulteary/phorge/.github/workflows/atomic-feature-regressions.yml@main'
   elif mode=='head':first['with']['expected_head']='${{ github.sha }}'
   elif mode=='skip':first['if']='false'
   elif mode=='continuing':jobs['qualified']['continue-on-error']='true'
   else:jobs['qualified']['needs'].pop()
   with self.subTest(mode=mode),self.assertRaises(ValueError):m.fanout_contract(d,baseline)
 def test_documented_inventory_cannot_silently_drop_suite(self):
  b=self.baseline();b['releaseFullWorkflows'].pop()
  with self.assertRaises(ValueError):m.fanout_contract(self.workflow('release-full.yml'),b)
class DeclaredSourceInventory(unittest.TestCase):
 def test_actual_exact_union_accepts_current_and_refuses_unknown_or_missing_path(self):
  # Evaluate the actual production predicate as DATA, never enter/fake an owner.
  path=ROOT/'scripts/governance/task_source_operation_context.py'
  spec=importlib.util.spec_from_file_location('task_inventory_DATA',path)
  module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
  parent=json.loads((ROOT/module.BASELINE).read_bytes())['entries']
  current={v.decode()for v in subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),
   'ls-tree','-rz','--name-only','HEAD'],timeout=10).split(b'\0')if v}
  tree=ast.parse(path.read_bytes());cls=next(n for n in tree.body if isinstance(n,ast.ClassDef)and n.name=='TaskContext')
  init=next(n for n in cls.body if isinstance(n,ast.FunctionDef)and n.name=='__init__')
  predicate=next(n.args[0]for n in ast.walk(init)if isinstance(n,ast.Call)and isinstance(n.func,ast.Name)
   and n.func.id=='need'and n.args and isinstance(n.args[0],ast.Compare)
   and isinstance(n.args[0].left,ast.Call)and isinstance(n.args[0].left.func,ast.Name)
   and n.args[0].left.func.id=='set'and isinstance(n.args[0].left.args[0],ast.Name)
   and n.args[0].left.args[0].id=='entries')
  code=compile(ast.Expression(predicate),'<actual-inventory-predicate-DATA>','eval')
  scope=dict(vars(module),self=type('DATA',(),{'parent_pins':parent})())
  def check(entries):module.need(eval(code,dict(scope,entries=entries)))
  check(current)
  reviewed={'src/applications/auth/__tests__/PhabricatorTraefikSessionIdentityTestCase.php',
            'tests/contract/runtime/media-isolation.php'}
  self.assertTrue(reviewed<=module.CI_V2_NEW_PATHS)
  for rows in [current|{'tests/contract/runtime/unreviewed.php'}]+[current-{p}for p in reviewed]:
   with self.assertRaises(ValueError):check(rows)

class CallerBinding(unittest.TestCase):
 def event(self):return {'repository':{'full_name':'soulteary/phorge'},'after':'a'*40,'deleted':False}
 def test_actual_dispatch_or_push_metadata_same_checkout(self):
  for event in ('workflow_dispatch','push'):
   result=m.bind_head('a'*40,event,'a'*40,self.event(),'a'*40);self.assertEqual(result['tier'],'full');self.assertFalse(result['Root_signature_or_SQL_qualified'])
 def test_PR_fake_workflow_call_wrong_repo_deleted_or_head_refused(self):
  for event in ('pull_request','workflow_call','schedule'):
   with self.assertRaises(ValueError):m.bind_head('a'*40,event,'a'*40,self.event(),'a'*40)
  for expected,sha,checkout in [(True,'a'*40,'a'*40),('0'*40,'0'*40,'0'*40),('a'*40,'b'*40,'a'*40),('a'*40,'a'*40,'b'*40)]:
   with self.assertRaises(ValueError):m.bind_head(expected,'workflow_dispatch',sha,self.event(),checkout)
  for key,value in [('repository',{'full_name':'foreign/repo'}),('deleted',True),('after','b'*40)]:
   e=self.event();e[key]=value
   with self.assertRaises(ValueError):m.bind_head('a'*40,'push','a'*40,e,'a'*40)
if __name__=='__main__':unittest.main()
