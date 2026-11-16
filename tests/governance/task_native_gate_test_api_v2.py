"""Explicit CURRENT test-only strict receipt bodies; never physical SQL proof.

No default source factory is changed. The live owner supplies the original typed
source argument; the only body seam is the same captured-source restore read
seam used by the independently versioned native Task consumers.
"""
import argparse,ast,copy,hashlib,json,os,re,sys,types
from contextlib import contextmanager
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
from task_inverse_test_api_v2 import owner
from task_admission_test_api_v2 import checkpoint
import source_operation_snapshot as source
FAMILIES={
 'session':('check_recovery_session_lease',19,6,16),
 'borrowed':('check_recovery_borrowed_session',30,6,16),
 'conduit':('check_recovery_conduit_producer',37,6,16),
 'oauth-access':('check_recovery_oauth_access_producer_v3',50,8,18),
 'assessment-credential':('check_recovery_assessment_credentials_v2',220,8,18),
 'fileparent':('check_recovery_file_parent_policy',12,6,16),
 'oauth-code':('check_recovery_oauth_code_producer_v1',51,8,18),
}
CAPTURE=('scripts/governance/check_completion.py','scripts/governance/check_native_organization.py',
 'scripts/governance/check_recovery_partial_composition.py','scripts/governance/oauth_task_strict_body_v2.py')
def need(value):
 if value is not True:raise ValueError('Task_native_gate_test_API_refused')
class RestoreCall(ast.NodeTransformer):
 def __init__(self):self.count=0
 def visit_Call(self,node):
  node=self.generic_visit(node)
  if ast.unparse(node.func)=='native.validate_restore':
   self.count+=1;node.func=ast.Name(id='_strict_restore',ctx=ast.Load());node.args.append(ast.Name(id='_source_operation',ctx=ast.Load()))
  return node
class NativeGate:
 def __init__(self,operation,root,family):
  need(family in FAMILIES);self.op=operation;self.root=Path(root);self.family=family;self.guard()
  self.context=operation._task_contexts[self.root];name,count,sql,total=FAMILIES[family]
  self.profile=FAMILIES[family];self.relative='scripts/governance/'+name+'.py'
  extra=('scripts/governance/oauth_code_task_strict_body_v2.py','scripts/governance/check_oauth_code_task_schema_receipt_v2.py')if family=='oauth-code'else()
  self.raws={p:self.context.current(p)for p in (*CAPTURE,self.relative,*extra)}
  self.checker=self.capture_module(self.relative)
  need(type(self.checker.SOURCE_INPUTS)is dict and len(self.checker.SOURCE_INPUTS)==count)
  self.common=self.capture_module('scripts/governance/oauth_task_strict_body_v2.py')
  if family=='oauth-code':
   adapter=self.capture_module('scripts/governance/oauth_code_task_strict_body_v2.py')
   self.code_model=adapter.model({name:self.raws['scripts/governance/'+name+'.py']for name in adapter.PINS},self.common)
   self.code_consumer=self.capture_module('scripts/governance/check_oauth_code_task_schema_receipt_v2.py')
   self._validator=self.code_model['validate']
  else:self._validator=self.compile_body()
  self.SOURCE_INPUTS=dict(self.checker.SOURCE_INPUTS)
  if family=='fileparent':self.SQL=set(self.checker.SQL);self.NATIVE_DRIVER_PATH=self.checker.NATIVE_DRIVER_PATH
  need(len(self.SQL_obligations())==sql and len(self.obligations())==total)
 def guard(self):
  source.require_task_operation(self.op,self.root)
  need(self.root==ROOT and self.op.code_root==ROOT and self.op.producer_root==ROOT and source.ROOT==ROOT)
 def capture_module(self,relative):
  self.guard();need(relative in self.raws)
  raw=self.raws[relative];need(raw==self.context.current(relative))
  module=types.ModuleType('CURRENT_test_only_'+Path(relative).stem);module.__file__=str(self.root/relative)
  exec(compile(raw,module.__file__,'exec'),module.__dict__);self.guard();return module
 def capture_bytes(self,relative,raw):
  self.guard();need(relative in self.raws and type(raw)is bytes and raw==self.raws[relative]==self.context.current(relative));return raw
 def compile_body(self):
  if self.family=='fileparent':return self.compile_fileparent_body()
  common=self.common;raw=self.raws['scripts/governance/check_native_organization.py'];tree=ast.parse(raw)
  native_ns={};common.compile_functions(common.functions(tree,{'exact_tree'}),native_ns)
  restore=common.functions(tree,{'validate_restore'})[0];restore.name='_strict_restore';restore.args.args.append(ast.arg(arg='operation'))
  reads=common.RestoreReads();restore=reads.visit(restore);need(reads.count==2)
  restore_ns={'hashlib':hashlib,'completion':self.checker.completion,'exact_tree':native_ns['exact_tree'],'_captured':common._captured}
  common.compile_functions([restore],restore_ns)
  original=common.functions(ast.parse(self.raws[self.relative]),{'_validate_with_source_operation'})[0]
  modified=copy.deepcopy(original);seam=RestoreCall();modified=seam.visit(modified)
  need(seam.count==1 and len(modified.body)==len(original.body))
  need([n.value for n in ast.walk(modified)if isinstance(n,ast.Constant)]==[n.value for n in ast.walk(original)if isinstance(n,ast.Constant)])
  namespace=dict(self.checker.__dict__);namespace['_strict_restore']=restore_ns['_strict_restore'];common.compile_functions([modified],namespace)
  return namespace['_validate_with_source_operation']
 def fileparent_read(self,path):
  self.guard();need(self.family=='fileparent');path=Path(path)
  need(path.is_absolute()and path.is_relative_to(self.root))
  relative=str(path.relative_to(self.root));need(relative in self.checker.SOURCE_INPUTS)
  result=self.context.current(relative)if relative==self.checker.NATIVE_DRIVER_PATH else self.context.parent(relative)
  need(type(result)is bytes);self.guard();return result
 def compile_fileparent_body(self):
  common=self.common;native_tree=ast.parse(self.raws['scripts/governance/check_native_organization.py'])
  native_ns={};common.compile_functions(common.functions(native_tree,{'exact_tree'}),native_ns)
  restore=common.functions(native_tree,{'validate_restore'})[0];restore.name='_strict_restore';restore.args.args.append(ast.arg(arg='operation'))
  reads=common.RestoreReads();restore=reads.visit(restore);need(reads.count==2)
  namespace={'hashlib':hashlib,'completion':self.checker.completion,'exact_tree':native_ns['exact_tree'],'_captured':common._captured}
  common.compile_functions([restore],namespace)
  nodes=common.functions(ast.parse(self.raws[self.relative]),{'source_obligations','summary','validate'})
  class AdmissionImport(ast.NodeTransformer):
   def __init__(self):self.count=0
   def visit_Import(self,node):
    if ast.unparse(node)=='import project_description_admission_delta as current':
     self.count+=1;return ast.Assign([ast.Name('current',ast.Store())],ast.Name('_current_admission',ast.Load()))
    return node
  original=copy.deepcopy(nodes);admission=AdmissionImport();seam=RestoreCall()
  nodes=[seam.visit(admission.visit(n))for n in nodes]
  need(admission.count==1 and seam.count==1)
  for before,after in zip(original,nodes):
   need(len(before.body)==len(after.body)and[n.value for n in ast.walk(before)if isinstance(n,ast.Constant)]==[n.value for n in ast.walk(after)if isinstance(n,ast.Constant)])
   if after.name=='validate':after.args.kwonlyargs.append(ast.arg(arg='_source_operation'));after.args.kw_defaults.append(ast.Constant(None))
  def driver_prior(root,raw):
   self.guard();need(Path(root)==self.root);return self.driver_prior(raw)
  body=dict(self.checker.__dict__);body.update(read_source_checked=self.fileparent_read,
   admitted_native_driver_source=driver_prior,_strict_restore=namespace['_strict_restore'],
   _current_admission=self.op._modules['project_description_admission_delta'])
  common.compile_functions(nodes,body);self._fileparent_body=body
  return body['validate']
 def source_obligations(self,root=ROOT):
  self.guard();need(self.family=='fileparent'and Path(root)==self.root)
  result=self._fileparent_body['source_obligations'](self.root);self.guard();return result
 def SQL_obligations(self,root=ROOT):
  self.guard();need(Path(root)==self.root)
  result=self.code_consumer.source_obligations(self.op,self.root,self.code_model)[0]if self.family=='oauth-code'else set(self.checker.SQL)if self.family=='fileparent'else self.checker.SQL_obligations(self.root,_source_operation=self.op);self.guard();return result
 def obligations(self,root=ROOT):
  self.guard();need(Path(root)==self.root)
  result=self.code_consumer.source_obligations(self.op,self.root,self.code_model)[1]if self.family=='oauth-code'else self.source_obligations(self.root)if self.family=='fileparent'else self.checker.obligations(self.root,_source_operation=self.op);self.guard();return result
 def summary(self,revision,root=ROOT):
  self.guard();need(Path(root)==self.root)
  result=self._validator.__globals__['summary'](revision,sql_obligations=self.SQL_obligations())if self.family=='oauth-code'else self._fileparent_body['summary'](revision,self.root)if self.family=='fileparent'else self.checker.summary(revision,self.root,_source_operation=self.op);self.guard();return result
 def validate(self,data,root=ROOT,*,source_revision,file_digest):
  self.guard();need(Path(root)==self.root)
  if self.family=='oauth-code':
   sql,expected=self.code_consumer.source_obligations(self.op,self.root,self.code_model)
   result=self._validator(data,self.root,source_revision=source_revision,file_digest=file_digest,sql_obligations=sql,expected_obligations=expected,operation=self.op)
  else:result=self._validator(data,self.root,source_revision=source_revision,file_digest=file_digest,_source_operation=self.op)
  self.guard();return result
 def artifact_read(self,path,*,binary=False):
  self.guard();reader=self.checker.shared.read_checked
  result=reader(path,binary=binary)if binary else reader(path);self.guard();return result
 def driver_prior(self,raw):
  self.guard()
  from task_source_candidate_test_api_v2 import CandidateDATA
  result=CandidateDATA(self.op,self.root).driver_prior(raw);self.guard();return result
 def atomic_history(self,path='.github/workflows/atomic-feature-regressions.yml',raw=None):
  """CURRENT helper body on authenticated parent DATA; original producer role.
  Unknown paths/current bytes and patched producer identity remain refused.
  """
  self.guard();need(self.family=='borrowed'and path=='.github/workflows/atomic-feature-regressions.yml')
  if raw is not None:need(type(raw)is bytes and raw==self.context.current(path))
  import test_recovery_borrowed_wiring as original
  relative='tests/governance/test_recovery_borrowed_wiring.py'
  nodes=self.common.functions(ast.parse(self.context.current(relative)),{'historical_atomic_source'})
  class AdmissionImport(ast.NodeTransformer):
   def __init__(self):self.count=0
   def visit_Import(self,node):
    if ast.unparse(node)=='import project_description_admission_delta as current':
     self.count+=1;return ast.Assign([ast.Name('current',ast.Store())],ast.Name('_current_admission',ast.Load()))
    return node
  before=copy.deepcopy(nodes[0]);seam=AdmissionImport();after=seam.visit(nodes[0])
  need(seam.count==1 and len(before.body)==len(after.body)and[n.value for n in ast.walk(before)if isinstance(n,ast.Constant)]==[n.value for n in ast.walk(after)if isinstance(n,ast.Constant)])
  namespace=dict(original.__dict__);namespace['_current_admission']=self.op._modules['project_description_admission_delta']
  self.common.compile_functions([after],namespace)
  result=namespace['historical_atomic_source'](path,None if raw is None else self.context.parent(path))
  self.guard();return result
 def fixture_DATA(self,destination):
  """A registered parent-DATA fixture, never a producer or source owner."""
  self.guard();need(self.family in {'session','borrowed','conduit','assessment-credential'})
  if not hasattr(self,'_fixture_session'):
   from task_historical_views_test_api_v2 import in_operation
   legacy,_=in_operation(self.op,self.root);self._fixture_session=legacy._session
  paths=set(self.checker.SOURCE_INPUTS)
  # Every original complete_source_cohort prerequisite is mandatory, even when
  # it is not part of the selected producer's final SOURCE_INPUTS map.
  paths|=set(self.op._modules['recovery_oauth_code_source_delta'].SOURCE_INPUTS)
  paths|=set(self.op._modules['recovery_partial_current_schema_delta'].SOURCE_INPUTS)
  paths|=set(self.op._modules['recovery_oauth_authorization_source_delta'].PRODUCTION_PATHS)
  if self.family=='assessment-credential':
   need(type(self.checker.ADAPTER_SOURCE_INPUTS)is dict and len(self.checker.ADAPTER_SOURCE_INPUTS)==2)
   paths|=set(self.checker.ADAPTER_SOURCE_INPUTS)
  result=self._fixture_session.fixture_DATA(Path(destination),paths=paths)
  self.guard();return result
 def fixture_SQL_obligations(self,destination):
  """Original CURRENT label body on explicitly registered historical DATA.
  It cannot issue an operation, alter a source root or qualify a native run.
  """
  self.guard();destination=Path(destination)
  need(self.family in {'session','borrowed','conduit','assessment-credential'}and hasattr(self,'_fixture_session')and
   destination!=self.root and destination in self._fixture_session.fixtures)
  session=self._fixture_session;session.require_DATA(destination)
  def functions(name,bindings):
   raw=self.context.current('scripts/governance/'+name+'.py')
   nodes=[copy.deepcopy(n)for n in ast.parse(raw).body if isinstance(n,ast.FunctionDef)]
   need(bool(nodes));namespace=dict(self.op._modules[name].__dict__);namespace.update(bindings)
   self.common.compile_functions(nodes,namespace)
   return types.SimpleNamespace(**namespace)
  # CURRENT dependencies, with the single explicit DATA-cohort guard role.
  # Neither the actual private graph nor the original default APIs are mutated.
  auth=functions('recovery_oauth_authorization_source_delta',{'n05_delta':session.admission_DATA})
  partial=functions('recovery_partial_current_schema_delta',{'authorization_delta':auth})
  code=functions('recovery_oauth_code_source_delta',{'authorization_delta':auth})
  ci=functions('recovery_oauth_code_ci_delta',{'authorization_delta':auth})
  coordinator=functions('recovery_oauth_code_coordinator_delta',{
   'authorization_delta':auth,'partial_delta':partial,'code_delta':code,'ci_delta':ci})
  namespace=dict(self.checker.__dict__);namespace.update(code_coord=coordinator,read_source_checked=session.data_bytes)
  if self.family=='assessment-credential':namespace.update(worker_terminal=self.op.worker_terminal,calendar_delta=self.op.calendar_delta)
  names={'SQL_obligations','source_view'}if self.family=='assessment-credential'else{'SQL_obligations'}
  nodes=self.common.functions(ast.parse(self.raws[self.relative]),names)
  self.common.compile_functions(nodes,namespace)
  self._fixture_coordinator=coordinator
  result=namespace['SQL_obligations'](destination)
  session.require_DATA(destination);self.guard();return result
 def template(self,digest='b'*64):
  self.guard()
  if self.family=='session':
   import test_recovery_partial_composition_gate as previous
   path='tests/governance/test_recovery_session_lease_gate.py'
   nodes=self.common.functions(ast.parse(self.context.current(path)),{'template'})
   namespace={'previous':previous,'gate':self};self.common.compile_functions(nodes,namespace)
   result=namespace['template'](digest)
  elif self.family=='fileparent':
   import test_recovery_partial_composition_gate as predecessor
   nodes=self.common.functions(ast.parse(self.context.current('tests/governance/test_recovery_file_parent_gate.py')),{'template'})
   namespace={'predecessor':predecessor,'gate':self,'ROOT':self.root};self.common.compile_functions(nodes,namespace)
   result=namespace['template'](digest)
  elif self.family in {'oauth-access','assessment-credential','oauth-code'}:
   result=NativeGate(self.op,self.root,'session').template(digest)
   labels=sorted(self.obligations())
   batch,scope=(('native-oauth-access-producer-owner','owned-native-oauth-access-producer-owner-no-permit')if self.family=='oauth-access'else('native-assessment-credential-readset','owned-native-assessment-credential-readset-no-permit'))
   if self.family=='oauth-code':batch,scope='native-oauth-code-producer-owner','owned-native-oauth-code-producer-owner-no-permit'
   result.update(batch=batch,scope=scope,checks=labels,check_count=len(labels),native=self.summary('a'*40))
   if self.family=='assessment-credential':result['worker_cleanup']=dict.fromkeys(result['worker_cleanup'],0)
  else:
   module='test_recovery_borrowed_wiring'if self.family=='borrowed'else'test_recovery_conduit_wiring'
   name='BorrowedWiring'if self.family=='borrowed'else'ConduitWiring'
   path='tests/governance/'+module+'.py';tree=ast.parse(self.context.current(path))
   classes=[n for n in tree.body if isinstance(n,ast.ClassDef)and n.name==name];need(len(classes)==1)
   nodes=[copy.deepcopy(n)for n in classes[0].body if isinstance(n,ast.FunctionDef)and n.name=='template'];need(len(nodes)==1)
   session=NativeGate(self.op,self.root,'session')
   namespace={'previous':types.SimpleNamespace(template=session.template),'gate':self};self.common.compile_functions(nodes,namespace)
   result=namespace['template'](None,digest)
  self.guard();return result
@contextmanager
def current_native_gate(root,family):
 need(family in FAMILIES)
 with owner(Path(root))as operation:
  gate=NativeGate(operation,root,family);yield gate;gate.guard()
def main():
 parser=argparse.ArgumentParser();parser.add_argument('--family',choices=sorted(FAMILIES),required=True)
 parser.add_argument('--expected-checkpoint',required=True)
 for name in ('receipt','source-revision','file-binary','file-binary-sha256'):parser.add_argument('--'+name,required=True)
 args=parser.parse_args();need(args.expected_checkpoint==checkpoint())
 with current_native_gate(ROOT,args.family)as gate:
  need(re.fullmatch('[a-f0-9]{64}',args.file_binary_sha256)is not None)
  need(gate.artifact_read(Path(args.file_binary),binary=True)==args.file_binary_sha256)
  raw=gate.artifact_read(Path(args.receipt))
  def nonfinite(value):raise ValueError('nonfinite refused')
  data=json.loads(raw,object_pairs_hook=gate.checker.completion.strict_object,parse_constant=nonfinite)
  result=gate.validate(data,source_revision=args.source_revision,file_digest=args.file_binary_sha256)
 # Synthetic unit data is explicitly distinct from the genuine source-window.
 print(json.dumps({'schema':'Task-native-gate-test-only-v2','CURRENT_source_exit_verified':True,'CURRENT_source_checkpoint':args.expected_checkpoint,'physical_SQL_executed':False,'runtime_qualified':False,'strict_body':result},sort_keys=True))
if __name__=='__main__':main()
