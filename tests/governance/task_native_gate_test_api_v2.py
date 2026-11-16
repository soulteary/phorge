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
  self.raws={p:self.context.current(p)for p in (*CAPTURE,self.relative)}
  self.checker=self.capture_module(self.relative)
  need(type(self.checker.SOURCE_INPUTS)is dict and len(self.checker.SOURCE_INPUTS)==count)
  self.common=self.capture_module('scripts/governance/oauth_task_strict_body_v2.py')
  self._validator=self.compile_body();self.SOURCE_INPUTS=dict(self.checker.SOURCE_INPUTS)
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
 def SQL_obligations(self,root=ROOT):
  self.guard();need(Path(root)==self.root)
  result=self.checker.SQL_obligations(self.root,_source_operation=self.op);self.guard();return result
 def obligations(self,root=ROOT):
  self.guard();need(Path(root)==self.root)
  result=self.checker.obligations(self.root,_source_operation=self.op);self.guard();return result
 def summary(self,revision,root=ROOT):
  self.guard();need(Path(root)==self.root)
  result=self.checker.summary(revision,self.root,_source_operation=self.op);self.guard();return result
 def validate(self,data,root=ROOT,*,source_revision,file_digest):
  self.guard();need(Path(root)==self.root)
  result=self._validator(data,self.root,source_revision=source_revision,file_digest=file_digest,_source_operation=self.op)
  self.guard();return result
 def artifact_read(self,path,*,binary=False):
  self.guard();reader=self.checker.shared.read_checked
  result=reader(path,binary=binary)if binary else reader(path);self.guard();return result
 def template(self,digest='b'*64):
  self.guard()
  if self.family=='session':
   import test_recovery_partial_composition_gate as previous
   path='tests/governance/test_recovery_session_lease_gate.py'
   nodes=self.common.functions(ast.parse(self.context.current(path)),{'template'})
   namespace={'previous':previous,'gate':self};self.common.compile_functions(nodes,namespace)
   result=namespace['template'](digest)
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
