"""Full-only focused routing and metadata binding; never SQL/signature proof."""
import ast,base64,copy,hashlib,importlib.util,json,pathlib,subprocess,sys,unittest,zlib
from pathlib import Path
from unittest.mock import patch
import yaml
ROOT=pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'tests/governance'))
spec=importlib.util.spec_from_file_location('focused_workflows',ROOT/'scripts/ci/focused_workflows.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
BASE='7b23c47e329f32e4fe083d0efa23fd82d6eefe6f'
def parse(raw):return yaml.load(raw,Loader=yaml.BaseLoader)
def security_version(value):
 # A fixed security-version DATA projection; no other job field may change.
 if isinstance(value,str):return value.replace('8.5.12','8.5.13').replace('8\\.5\\.12','8\\.5\\.13')
 if isinstance(value,list):return [security_version(x)for x in value]
 if isinstance(value,dict):return {k:security_version(v)for k,v in value.items()}
 return value
def media_build_step_inverse(step):
 # Read only two fixed AST string DATA literals; never import the media tests.
 tree=ast.parse((ROOT/'support/governance/media-session-ci/test_ci.py').read_bytes())
 pins={'CURRENT_BUILD_BLOCK':(784,'f8479312988839e4fcc813d468b1b82c380c23e7974cfa1ca5b5e377b74f87d1'),
       'PRIOR_PULL_BLOCK':(764,'0565e85acc03ff56252a5c53b3fcfe217bcbbc31c3090c3447dfd1d4694e286f')}
 values={}
 for node in tree.body:
  if isinstance(node,ast.Assign)and len(node.targets)==1 and isinstance(node.targets[0],ast.Name)and node.targets[0].id in pins:
   name=node.targets[0].id
   if name in values or not isinstance(node.value,ast.Constant)or type(node.value.value)is not str:raise ValueError('unreviewed_media_build_literal')
   raw=node.value.value.encode();size,digest=pins[name]
   if len(raw)!=size or hashlib.sha256(raw).hexdigest()!=digest:raise ValueError('unreviewed_media_build_literal')
   values[name]=parse(raw)
 if set(values)!=set(pins)or any(type(v)is not list or len(v)!=1 for v in values.values()):raise ValueError('unreviewed_media_build_literal')
 if step!=values['CURRENT_BUILD_BLOCK'][0]:raise ValueError('unreviewed_media_build_step')
 return copy.deepcopy(values['PRIOR_PULL_BLOCK'][0])

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
     if name=='native-media-session-positive-sql.yml':
      matches=[i for i,step in enumerate(job['steps'])if step.get('name')=='Build current locked PHP runtime and pull fixed MySQL index']
      self.assertEqual(len(matches),1)
      index=matches[0];job['steps'][index]=media_build_step_inverse(job['steps'][index])
     if name=='native-rr-owner-build.yml':
      for key,value in {'NATIVE_RR_EXPECTED_HEAD':'${{ inputs.expected_head }}','NATIVE_RR_WORKFLOW_SHA':'${{ github.workflow_sha }}','NATIVE_RR_RELEASE_TAG':'${{ github.event.inputs.release_tag }}'}.items():
       self.assertEqual(job['env'].pop(key),value)
     if name in m.PROOF_FOCUSED:
      admission=next(s for s in job['steps']if 'check_task_ci_head_v1.py --root' in s.get('run',''))
      flag=' --external-proof-tag "source-proof/$EXPECTED_SOURCE_CHECKPOINT"'
      self.assertEqual(admission['run'].count(flag),1)
      self.assertEqual(admission['run'].count(m.PROOF_ENV),1)
      admission['run']=admission['run'].replace(flag,'').replace(m.PROOF_ENV+'\n','')
    self.assertEqual(current['jobs'],security_version(original['jobs']))
    for key in set(original)-{'on','jobs'}:self.assertEqual(current[key],original[key])
 def test_media_build_continuation_refuses_unknown_step_fields_and_commands(self):
  workflow=self.workflow('native-media-session-positive-sql.yml')
  job=next(iter(workflow['jobs'].values()))
  step=next(s for s in job['steps']if s.get('name')=='Build current locked PHP runtime and pull fixed MySQL index')
  old=media_build_step_inverse(step)
  self.assertEqual(old['name'],'Pull only the two fixed public runtime indexes')
  for mutation in ('name','skip','push','lock','iid','extra'):
   changed=copy.deepcopy(step)
   if mutation=='name':changed['name']='unknown'
   elif mutation=='skip':changed['if']='false'
   elif mutation=='push':changed['run']+='docker push unknown\n'
   elif mutation=='lock':changed['run']=changed['run'].replace('--build-root','--unknown-root',1)
   elif mutation=='iid':changed['run']=changed['run'].replace('"$php_image_id"','latest',1)
   else:changed['continue-on-error']='true'
   with self.subTest(mutation=mutation),self.assertRaises(ValueError):media_build_step_inverse(changed)

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
   'ls-files','--cached','--others','--exclude-standard','-z'],timeout=10).split(b'\0')if v}
  # Candidate names only: no SourceOwner/dirty-checkout admission is claimed.
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
            'tests/contract/runtime/media-isolation.php',
            'docs/operations/2026.11.19-r3-components.json',
            'docs/operations/2026.11.19-upgrade-acceptance.json',
            'docs/operations/upgrade-and-rollback.md',
            'scripts/operations/upgrade_check.py',
            'tests/operations/test_upgrade_check.py',
            'scripts/operations/README.upgrade-check.md',
            'tests/operations/webserver_selfcheck_fixture.php',
            'tests/operations/test_webserver_selfcheck.py'}
  self.assertTrue(reviewed<=module.CI_V2_NEW_PATHS)
  for rows in [current|{'tests/contract/runtime/unreviewed.php'}]+[current-{p}for p in reviewed]+[current|{p}for p in module.WIKI_RETIRED_PATHS]:
   with self.assertRaises(ValueError):check(rows)

class ReleaseHistoryLiteralData(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  path=ROOT/'scripts/governance/task_source_operation_context.py'
  spec=importlib.util.spec_from_file_location('release_history_DATA',path)
  cls.scope=importlib.util.module_from_spec(spec);spec.loader.exec_module(cls.scope)
  cls.raw=(ROOT/cls.scope.RELEASE_HISTORY).read_bytes();cls.doc=cls.scope.load_release_history(cls.raw)
  cls.php86_predecessor=cls.scope.php86_compatibility_cohort({path:(ROOT/path).read_bytes() for path in cls.scope.PHP86_COMPATIBILITY_PATHS},(ROOT/cls.scope.PHP86_COMPATIBILITY_HISTORY).read_bytes())
  cls.directory_predecessor=cls.scope.directory_product_cohort({path:cls.php86_predecessor.get(path,(ROOT/path).read_bytes()) for path in cls.scope.DIRECTORY_PRODUCT_PATHS},(ROOT/cls.scope.DIRECTORY_PRODUCT_HISTORY).read_bytes())
  cls.proxy_predecessor=cls.scope.proxy_identity_security_cohort({path:(ROOT/path).read_bytes() for path in cls.scope.PROXY_IDENTITY_SECURITY_PATHS})
  cls.sink_path=cls.scope.HEADER_EMISSION_SECURITY_PATH;cls.sink_current=(ROOT/cls.sink_path).read_bytes()
  cls.sink_predecessor=cls.scope.header_emission_security_inverse(cls.sink_path,cls.sink_current)
  path=ROOT/'scripts/governance/task_content_grant_source_delta.py'
  spec=importlib.util.spec_from_file_location('unchanged_Task_reducer_DATA',path)
  cls.delta=importlib.util.module_from_spec(spec);spec.loader.exec_module(cls.delta)
 def test_all_21_current_payloads_roundtrip_and_original_parent_pins_still_match(self):
  # Retain the original 21 targets and the reviewed caller and self-check inputs.
  self.assertEqual(len(self.doc['files']),29+len(self.scope.WIKI_RETIREMENT_HISTORY_PATHS)+len(self.scope.PHP_SECURITY_HISTORY_PATHS))
  extra={'support/governance/recovery/native-writer-rr-ci-profile.php', 'tests/governance/test_native_rr_ci_anchor.py', 'src/applications/config/check/PhabricatorWebServerSetupCheck.php', 'src/applications/config/check/__tests__/PhabricatorSetupCheckTestCase.php'}
  extra.update(['src/applications/almanac/controller/AlmanacBindingEditController.php', 'src/applications/almanac/query/AlmanacDeviceSearchEngine.php', 'src/applications/almanac/query/AlmanacServiceSearchEngine.php', 'src/infrastructure/internationalization/translation/PhabricatorChineseTranslation.php'])
  extra |= self.scope.WIKI_RETIREMENT_HISTORY_PATHS | self.scope.PHP_SECURITY_HISTORY_PATHS
  self.assertTrue(extra <= set(self.doc['files']))
  self.assertEqual(hashlib.sha256(json.dumps(sorted(set(self.doc['files'])-extra),separators=(',',':')).encode()).hexdigest(),'2fc0c75cbf1640a24ee30a9c241d2636d555f9b64fa891df2df9cc2bfd1be38c')
  parent=json.loads((ROOT/self.scope.BASELINE).read_bytes())['entries']
  for path in self.doc['files']:
   with self.subTest(path=path):
    current=(ROOT/path).read_bytes();historical=self.proxy_predecessor.get(path,self.directory_predecessor.get(path,self.php86_predecessor.get(path,current)))
    target=self.scope.release_history_inverse(path,historical,self.doc)
    self.assertEqual(self.scope.release_history_roundtrip(path,target,self.doc),historical)
    if path in self.proxy_predecessor:
     self.assertEqual(self.scope.proxy_identity_security_roundtrip(path,historical),current)
    if path==self.scope.TASK_PREUPGRADE_FIXTURE:
     target=self.scope.task_preupgrade_fixture_inverse(path,target,(ROOT/self.scope.TASK_PREUPGRADE_RECIPE).read_bytes())
    restored=self.delta.reduce_selected(path,target);old=parent[path]
    self.assertEqual(len(restored),old['bytes']);self.assertEqual(hashlib.sha256(restored).hexdigest(),old['sha256'])
 def test_entire_existing_parent_payload_loop_has_no_remaining_mismatch(self):
  parent=json.loads((ROOT/self.scope.BASELINE).read_bytes())['entries']
  ci=json.loads((ROOT/self.scope.CI_INVERSE).read_bytes())['files'];api=json.loads((ROOT/self.scope.INVERSE).read_bytes())
  checked=0
  for path,pin in parent.items():
   if pin['kind']!='file':continue
   if path in self.scope.WIKI_RETIRED_PATHS:
    self.assertFalse((ROOT/path).exists())
    removed=json.loads((ROOT/self.scope.WIKI_RETIREMENT_MANIFEST).read_bytes())['removed_files']
    hashes={row['path']:row['sha256'] for row in removed}
    self.assertEqual(hashes[path],pin['sha256'])
    checked+=1
    continue
   raw=(ROOT/path).read_bytes()
   if path in self.php86_predecessor:raw=self.php86_predecessor[path]
   if path in self.directory_predecessor:raw=self.directory_predecessor[path]
   if path in ci or path in api:
    entry=(ci if path in ci else api)[path];decoder=zlib.decompressobj()
    raw=decoder.decompress(base64.b64decode(entry['zlib_base64'],validate=True),entry['bytes']+1)
    self.assertTrue(decoder.eof and not decoder.unused_data and not decoder.unconsumed_tail)
    self.assertEqual(len(raw),entry['bytes']);self.assertEqual(hashlib.sha256(raw).hexdigest(),entry['sha256'])
   else:
    if path==self.sink_path:raw=self.sink_predecessor
    if path in self.proxy_predecessor:raw=self.proxy_predecessor[path]
    if path in self.scope.RELEASE_HISTORY_PATHS:raw=self.scope.release_history_inverse(path,raw,self.doc)
    if path==self.scope.TASK_PREUPGRADE_FIXTURE:
     raw=self.scope.task_preupgrade_fixture_inverse(path,raw,(ROOT/self.scope.TASK_PREUPGRADE_RECIPE).read_bytes())
    raw=self.delta.reduce_selected(path,raw)
   self.assertEqual((len(raw),hashlib.sha256(raw).hexdigest()),(pin['bytes'],pin['sha256']),path);checked+=1
  self.assertEqual(checked,9289)
 def test_library_map_targets_old_Task_intermediate_before_original_reducer(self):
  path='src/__phutil_library_map__.php';target=self.scope.release_history_inverse(path,(ROOT/path).read_bytes(),self.doc)
  pin=self.delta.CURRENT_SHARED[path]
  self.assertEqual((len(target),hashlib.sha256(target).hexdigest()),(pin['current_bytes'],pin['current_sha256']))
  self.assertEqual(pin['current_sha256'],'081e5cf740a2aa893274774885a949f3d90fd233370517ce08f7f6ab007765a2')
 def test_unknown_current_bytes_length_type_and_path_refuse(self):
  self.assertEqual(self.scope.header_emission_security_roundtrip(self.sink_path,self.sink_predecessor),self.sink_current)
  for changed in (self.sink_predecessor,self.sink_current+b' ',b'!'+self.sink_current[1:],bytearray(self.sink_current)):
   with self.subTest(path=self.sink_path),self.assertRaises(ValueError):self.scope.header_emission_security_inverse(self.sink_path,changed)
  for path in self.doc['files']:
   raw=(ROOT/path).read_bytes()
   for changed in (raw+b' ',b'!'+raw[1:],bytearray(raw)):
    with self.subTest(path=path),self.assertRaises(ValueError):self.scope.release_history_inverse(path,changed,self.doc)
  with self.assertRaises(ValueError):self.scope.release_history_inverse('src/unreviewed.php',b'unknown',self.doc)
 def test_wrong_hash_target_path_offset_fragment_and_duplicate_edits_refuse(self):
  path=max(self.doc['files'],key=lambda p:len(self.doc['files'][p]['edits']));raw=(ROOT/path).read_bytes()
  for mutation in ('current_hash','target_hash','target_size','missing_path','unknown_path','negative_offset',
                   'bool_offset','out_of_bounds','current_fragment','parent_fragment','duplicate_edit','reverse_edits'):
   doc=copy.deepcopy(self.doc);row=doc['files'][path]
   if mutation=='current_hash':row['current_sha256']='0'*64
   elif mutation=='target_hash':row['target_sha256']='0'*64
   elif mutation=='target_size':row['target_bytes']+=1
   elif mutation=='missing_path':del doc['files'][next(p for p in doc['files']if p!=path)]
   elif mutation=='unknown_path':doc['files']['src/unreviewed.php']=doc['files'].pop(next(p for p in doc['files']if p!=path))
   elif mutation=='negative_offset':row['edits'][0]['offset']=-1
   elif mutation=='bool_offset':row['edits'][0]['offset']=True
   elif mutation=='out_of_bounds':row['edits'][0]['offset']=row['current_bytes']+1
   elif mutation=='current_fragment':row['edits'][0]['current']='!'+row['edits'][0]['current']
   elif mutation=='parent_fragment':row['edits'][0]['parent']='!'+row['edits'][0]['parent']
   elif mutation=='duplicate_edit':row['edits'].insert(1,copy.deepcopy(row['edits'][0]))
   else:row['edits'].reverse()
   with self.subTest(mutation=mutation),self.assertRaises(ValueError):self.scope.release_history_inverse(path,raw,doc)
 def test_fixed_raw_recipe_pin_duplicate_JSON_and_failed_roundtrip_refuse(self):
  for raw in (self.raw+b' ',self.raw.replace(b'literal-inverse-v1',b'literal-inverse-v2'),bytearray(self.raw)):
   with self.assertRaises(ValueError):self.scope.load_release_history(raw)
  path=next(iter(self.doc['files']));row=self.doc['files'][path]
  duplicate=json.dumps(self.doc).replace('"files": {','"files": {'+json.dumps(path)+':'+json.dumps(row)+',',1)
  with self.assertRaises(ValueError):self.scope.decode(duplicate)
  # Negative DATA fault injection only; no real Source graph/owner is patched.
  with patch.object(self.scope,'release_history_roundtrip',return_value=b'wrong'):
   with self.assertRaises(ValueError):self.scope.release_history_inverse(path,(ROOT/path).read_bytes(),self.doc)
  target=self.scope.release_history_inverse(path,(ROOT/path).read_bytes(),self.doc)
  with self.assertRaises(ValueError):self.scope.release_history_roundtrip(path,target+b' ',self.doc)

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
class KnownDirtyClassificationPure(unittest.TestCase):
 def test_exact_legacy_and_hardened_diff1_only_is_a_dirty_refusal(self):
  from task_description_consumer_test_api_v2 import known_dirty_rejection
  root=Path('/reviewed/source');checkpoint='a'*40
  prefixes=(['git','-C',str(root),'diff'],
   ['git','--no-replace-objects','-c','core.hooksPath=/dev/null','-c','core.fsmonitor=false',
    '-c','gpg.format=openpgp','-c','gpg.program=/usr/bin/gpg','-c','gpg.openpgp.program=/usr/bin/gpg',
    '-C',str(root),'diff'])
  for prefix in prefixes:
   for tail in (['--quiet',checkpoint,'--'],['--cached','--quiet',checkpoint,'--']):
    command=prefix+tail
    error=subprocess.CalledProcessError(1,command,output=b'',stderr=b'')
    self.assertTrue(known_dirty_rejection(error,root,checkpoint))
    for rc in (0,2,128,-9,True):
     with self.subTest(command=command,rc=rc):
      self.assertFalse(known_dirty_rejection(subprocess.CalledProcessError(rc,command,output=b'',stderr=b''),root,checkpoint))
    for stdout,stderr in ((b'diff',b''),(b'',b'fatal'),('',b''),(b'',''),(None,b''),(b'',None)):
     with self.subTest(stdout=stdout,stderr=stderr):
      self.assertFalse(known_dirty_rejection(subprocess.CalledProcessError(1,command,output=stdout,stderr=stderr),root,checkpoint))
    for wrongroot,wrongcp in ((root/'other',checkpoint),(Path('relative'),checkpoint),(str(root),checkpoint),
                              (root,'b'*40),(root,'HEAD'),(root,'0'*40)):
     self.assertFalse(known_dirty_rejection(error,wrongroot,wrongcp))
    for argv in (tuple(command),command+['src'],command[:-1],['/usr/bin/git']+command[1:],
                 [word.replace('/usr/bin/gpg','/tmp/foreign-gpg') for word in command]if len(prefix)>4 else command+['--exit-code']):
     self.assertFalse(known_dirty_rejection(subprocess.CalledProcessError(1,argv,output=b'',stderr=b''),root,checkpoint))
  self.assertFalse(known_dirty_rejection(ValueError('not a Git terminal'),root,checkpoint))

if __name__=='__main__':unittest.main()
