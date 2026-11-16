"""Actual parser/loader and private DATA controls. No native/source-positive proof."""
import argparse,ast,contextlib,copy,hashlib,importlib.util,io,json,os,sys,tempfile,types,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2];REFERENCE=Path(os.environ.get('N11_TEST_SOURCE_ROOT',str(ROOT))).resolve();sys.path[:0]=[str(ROOT/'scripts/governance'),str(REFERENCE/'scripts/governance'),str(REFERENCE/'tests/governance/private_mail_ci')]
def load(path):
 s=importlib.util.spec_from_file_location('N11_runtime_DATA_'+path.stem,path);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
entry=load(ROOT/'scripts/governance/run_n11_task_current_v2.py');adapter=entry.adapter;original=load(REFERENCE/adapter.PARENT)
class RuntimeEntryDATA(unittest.TestCase):
 def namespace(self,argv,config=None,config_path='config',worker=None):
  namespace=dict(original.__dict__);exec(compile(adapter.parent_functions((REFERENCE/adapter.PARENT).read_bytes()),str(REFERENCE/adapter.PARENT),'exec'),namespace)
  if config is None:config={'source_root':str(REFERENCE),'source_revision':'a'*40}
  main=entry.bind_runtime_arguments(namespace,original,argv,config_path,config,REFERENCE,config['source_revision'],worker or {'DATA':True});return namespace,main
 def parser(self,argv):
  namespace,main=self.namespace(tuple(argv));node=next(n for n in ast.parse((REFERENCE/adapter.PARENT).read_bytes()).body if isinstance(n,ast.FunctionDef)and n.name=='main');prefix=[]
  for statement in node.body:
   if isinstance(statement,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='a'for t in statement.targets):break
   prefix.append(copy.deepcopy(statement))
  exec(compile(ast.fix_missing_locations(ast.Module(prefix,[])),'ORIGINAL-parser-declarations-DATA','exec'),namespace);return namespace['parser']
 def minimal_argv(self):return ['--config','config','--task-source-manifest','manifest','--worker-manifest','worker','--output','output']
 def test_real_original_parser_required_choices_types_and_defaults(self):
  p=self.parser(self.minimal_argv()+['--case','allow']);self.assertIsInstance(p,argparse.ArgumentParser);value=p.parse_args();self.assertIs(type(value),argparse.Namespace);self.assertEqual(value.browser_seconds,300)
  self.assertEqual(self.parser(self.minimal_argv()+['--case','allow','--browser-seconds','600']).parse_args().browser_seconds,600)
  for argv in [[],self.minimal_argv()+['--case','unknown'],self.minimal_argv()+['--browser-seconds','not-an-int'],self.minimal_argv()+['--unknown','PRIVATE_SECRET']]:
   stderr=io.StringIO()
   with contextlib.redirect_stderr(stderr),self.assertRaises(SystemExit)as error:self.parser(argv).parse_args()
   self.assertEqual(error.exception.code,2);self.assertEqual(stderr.getvalue(),'N11 runtime invocation unavailable\n')
 def test_bound_parser_rejects_alternate_args_or_namespace(self):
  p=self.parser(self.minimal_argv()+['--case','allow'])
  with self.assertRaises(ValueError):p.parse_args([])
  with self.assertRaises(ValueError):p.parse_args(namespace=argparse.Namespace())
 def test_main_run_core_and_global_modules_not_replaced(self):
  before_argv=list(sys.argv);before_parser=argparse.ArgumentParser;before_task=sys.modules.get('task_source');before_run=sys.modules.get('run_task')
  ns,main=self.namespace(tuple(self.minimal_argv()+['--case','allow']))
  expected=compile(adapter.parent_functions((REFERENCE/adapter.PARENT).read_bytes()),str(REFERENCE/adapter.PARENT),'exec');codes={v.co_name:v for v in expected.co_consts if isinstance(v,type(main.__code__))};self.assertEqual(main.__code__,codes['main']);self.assertEqual(ns['run_task'].__code__,codes['run_task']);self.assertIs(ns['_run_task_with_source_operation'],original._run_task_with_source_operation);self.assertIs(main.__globals__,ns);self.assertIs(ns['run_task'].__globals__,ns)
  self.assertEqual(sys.argv,before_argv);self.assertIs(argparse.ArgumentParser,before_parser);self.assertIs(sys.modules.get('task_source'),before_task);self.assertIs(sys.modules.get('run_task'),before_run)
 def test_private_main_or_core_replacement_refused(self):
  for changed in ('main','run_task','_run_task_with_source_operation'):
   namespace=dict(original.__dict__);exec(compile(adapter.parent_functions((REFERENCE/adapter.PARENT).read_bytes()),str(REFERENCE/adapter.PARENT),'exec'),namespace)
   namespace[changed]=lambda *args,**kwargs:None
   with self.assertRaises(ValueError):entry.bind_runtime_arguments(namespace,original,tuple(self.minimal_argv()),'config',{'source_root':str(REFERENCE),'source_revision':'a'*40},REFERENCE,'a'*40,{'DATA':True})
 def config_DATA(self,temp):
  # Deliberately non-executed binary DATA: validates the original config reader,
  # not build provenance, PHP runtime or native eligibility.
  root=Path(temp).resolve();binary=root/'nonexecuted-data';binary.write_bytes(b'DATA-not-executable-runtime');binary.chmod(0o700);sha=hashlib.sha256(binary.read_bytes()).hexdigest()
  config={'schema':1,'engine':'linux-local','source_root':str(REFERENCE),'source_revision':'a'*40,'source_sha256':{name:hashlib.sha256((REFERENCE/name).read_bytes()).hexdigest()for name in original.validate_config.__globals__['SOURCE_FILES']},'php':str(binary),'binaries':{role:{'path':str(binary),'sha256':sha,'source_revision':'a'*40 if role=='observer'else'b'*40}for role in original.validate_config.__globals__['ROLES']},'gorge_revision':'b'*40,'database':{'host':'127.0.0.1','port':3306,'database':'governance_fixture_bootstrap','user':'unit_data_only','password_env':'NOT_READ_OR_SET'},'expected_pure_methods':1}
  cfg=root/'config.json';cfg.write_text(json.dumps(config));cfg.chmod(0o600);worker=root/'worker.json';worker.write_text(json.dumps({'path':str(binary),'sha256':sha,'source_revision':'b'*40}));worker.chmod(0o600);return config,cfg,worker
 def test_finalize_config_drift_refused_before_validation_preflight_or_exec(self):
  with tempfile.TemporaryDirectory()as temp,patch.dict(os.environ,{'ATOMIC_TEST_DISPOSABLE':'1'}):
   cfg,path,worker=self.config_DATA(temp);argv=entry.closed_runtime_argv(path,'unused-manifest',worker,Path(temp)/'out',finalize_browser='unused-control',owner_file='unused-owner')
   ns,main=self.namespace(argv,cfg,str(path));called=[];ns['validate_task_source']=lambda *a,**k:called.append('source-validation');ns['preflight']=lambda *a,**k:called.append('preflight');ns['admitted_source']=lambda *a,**k:called.append('owner')
   changed=copy.deepcopy(cfg);changed['php']=str(Path(temp)/'different-not-executed');Path(changed['php']).write_bytes(b'DATA');Path(changed['php']).chmod(0o700);path.write_text(json.dumps(changed))
   with self.assertRaises(ValueError):main()
   self.assertEqual(called,[]);self.assertFalse((Path(temp)/'out').exists())
 def test_guarded_loader_is_actual_fresh_original_load_not_cached_config(self):
  with tempfile.TemporaryDirectory()as temp,patch.dict(os.environ,{'ATOMIC_TEST_DISPOSABLE':'1'}):
   cfg,path,worker=self.config_DATA(temp);ns,main=self.namespace(tuple(self.minimal_argv()),cfg,str(path));first=ns['load_config'](str(path));self.assertEqual(first,cfg);self.assertIsNot(first,cfg)
   path.write_text('{}')
   with self.assertRaises(ValueError):ns['load_config'](str(path))
 def test_second_worker_manifest_drift_refused_by_real_validator(self):
  with tempfile.TemporaryDirectory()as temp,patch.dict(os.environ,{'ATOMIC_TEST_DISPOSABLE':'1'}):
   cfg,path,worker_path=self.config_DATA(temp);worker=json.loads(worker_path.read_text());argv=entry.closed_runtime_argv(path,'unused-manifest',worker_path,Path(temp)/'out',finalize_browser='unused-control',owner_file='unused-owner');ns,main=self.namespace(argv,cfg,str(path),worker);called=[];ns['preflight']=lambda *a:called.append('preflight');ns['admitted_source']=lambda *a:called.append('owner')
   replacement=Path(temp)/'replacement-not-executed';replacement.write_bytes(b'DATA-second-worker');replacement.chmod(0o700)
   changed=dict(worker,path=str(replacement),sha256=hashlib.sha256(replacement.read_bytes()).hexdigest());worker_path.write_text(json.dumps(changed))
   with self.assertRaises(ValueError):main()
   self.assertEqual(called,[]);self.assertFalse((Path(temp)/'out').exists());self.assertEqual(ns['validate_worker'](worker,cfg),worker)
 def test_original_finalize_case_conflict_still_actual_parser_error(self):
  with tempfile.TemporaryDirectory()as temp,patch.dict(os.environ,{'ATOMIC_TEST_DISPOSABLE':'1'}):
   cfg,path,worker=self.config_DATA(temp);argv=entry.closed_runtime_argv(path,'unused-manifest',worker,Path(temp)/'out',case='allow',finalize_browser='unused-control',owner_file='unused-owner');ns,main=self.namespace(argv,cfg,str(path),json.loads(worker.read_text()));called=[];ns['preflight']=lambda *a:called.append(True)
   with contextlib.redirect_stderr(io.StringIO()),self.assertRaises(SystemExit)as error:main()
   self.assertEqual(error.exception.code,2);self.assertEqual(called,[]);self.assertFalse((Path(temp)/'out').exists())
 def test_source_only_rejects_explicit_runtime_flags_even_default_values(self):
  base=['program','--root',str(entry.ROOT),'--expected-checkpoint','a'*40,'--config','unused','--task-source-manifest','unused','--output','unused']
  for extra in [['--browser-seconds','300'],['--browser-seconds=300'],['--case','allow'],['--worker-manifest','unused'],['--finalize-browser','unused'],['--owner-file','unused']]:
   with patch.object(sys,'argv',base+extra),patch.object(adapter,'modules',side_effect=AssertionError('must not load runtime'))as loader,self.assertRaises(ValueError):entry.main()
   loader.assert_not_called()
 def test_outer_abbreviated_runtime_flags_refused_before_module_load(self):
  base=['program','--root',str(entry.ROOT),'--expected-checkpoint','a'*40,'--config','unused','--task-source-manifest','unused','--output','unused']
  for extra in [['--cas','allow'],['--browser-sec=300'],['--worker-m','unused']]:
   with patch.object(sys,'argv',base+extra),patch.object(adapter,'modules',side_effect=AssertionError('must not load runtime'))as loader,self.assertRaises(SystemExit):entry.main()
   loader.assert_not_called()
 def test_closed_argv_immutable_preserves_original_unset_defaults(self):
  args=entry.closed_runtime_argv('cfg','manifest','worker','out',case='allow');self.assertIs(type(args),tuple);self.assertNotIn('--browser-seconds',args);self.assertEqual(args[args.index('--case')+1],'allow')
  with self.assertRaises(ValueError):entry.closed_runtime_argv('cfg','m','w','out',browser_seconds=True)
 def test_output_and_ready_roles_refuse_source_and_foreign_aliases(self):
  with self.assertRaises(ValueError):entry.output_role(REFERENCE/'uncreated-unit-output',REFERENCE)
  with tempfile.TemporaryDirectory()as temp:
   root=Path(temp).resolve();output=root/'output';self.assertEqual(entry.output_role(output,REFERENCE),output);self.assertFalse(output.exists());output.write_bytes(b'foreign')
   with self.assertRaises(ValueError):entry.output_role(output,REFERENCE)
   self.assertEqual(output.read_bytes(),b'foreign');alias=root/'alias';alias.symlink_to(root,target_is_directory=True)
   with self.assertRaises(ValueError):entry.output_role(alias/'uncreated',REFERENCE)
 def test_help_and_runtime_mode_never_wrap_returncode_as_acceptance(self):
  base=['program','--root',str(entry.ROOT),'--expected-checkpoint','a'*40,'--config','unused','--task-source-manifest','unused','--dependency-sidecar','unused','--output','unused','--worker-manifest','unused','--mode','runtime']
  with patch.object(sys,'argv',base),patch.object(entry,'runtime_dispatch',return_value=1)as dispatch:self.assertEqual(entry.main(),1)
  dispatch.assert_called_once();self.assertNotIn('runtime_qualified',str(dispatch.call_args))
if __name__=='__main__':unittest.main()
