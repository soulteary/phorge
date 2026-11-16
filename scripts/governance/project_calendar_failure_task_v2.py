"""Failure-only CURRENT Calendar projection; no receipt, SQL or cleanup authority."""
import argparse,ast,copy,hashlib,types
from pathlib import Path
import source_operation_snapshot as source
from check_full_native_task_schema_receipt_v2 import captured
ROOT=Path(__file__).resolve().parents[2]
ORIGINAL='scripts/governance/project_calendar_failure.py'
ORIGINAL_SHA='ff75e100560301ed9b424a8a01ea24a5f446a4bbf7ac74b2dd910646909f420a'
RAW_DIRECTORY='task-calendar-reminders-v2-private'
RAW_FILE='capture-native-body.private.json'
def need(value):
 if value is not True:raise ValueError('Calendar_Task_failure_projection_refused')
def body(raw,original,verify_sources,receipt,output):
 """Exact current main/role inverse; original private reader and writer unchanged."""
 need(type(raw)is bytes and hashlib.sha256(raw).hexdigest()==ORIGINAL_SHA and callable(verify_sources))
 tree=ast.parse(raw);nodes=[copy.deepcopy(n)for n in tree.body if isinstance(n,ast.FunctionDef)and n.name in{'roles','main'}];need({n.name for n in nodes}=={'roles','main'});before=copy.deepcopy(nodes)
 old_role=ast.parse("runner / RECEIPT_DIRECTORY / 'integration.json'",mode='eval').body
 new_role=ast.parse("runner / 'task-calendar-reminders-v2-private' / 'capture-native-body.private.json'",mode='eval').body
 old_args=ast.parse('parser.parse_args()',mode='eval').body
 class Forward(ast.NodeTransformer):
  def __init__(self):self.roles=0;self.args=0
  def visit_BinOp(self,node):
   if ast.dump(node,include_attributes=False)==ast.dump(old_role,include_attributes=False):self.roles+=1;return copy.deepcopy(new_role)
   return self.generic_visit(node)
  def visit_Call(self,node):
   if ast.dump(node,include_attributes=False)==ast.dump(old_args,include_attributes=False):self.args+=1;return ast.Name('_task_arguments',ast.Load())
   return self.generic_visit(node)
 forward=Forward();nodes=[forward.visit(n)for n in nodes];need(forward.roles==1 and forward.args==1)
 class Inverse(ast.NodeTransformer):
  def visit_BinOp(self,node):
   if ast.dump(node,include_attributes=False)==ast.dump(new_role,include_attributes=False):return copy.deepcopy(old_role)
   return self.generic_visit(node)
  def visit_Name(self,node):
   return copy.deepcopy(old_args)if node.id=='_task_arguments'else node
 inverse=[Inverse().visit(copy.deepcopy(n))for n in nodes];need(ast.dump(ast.Module(inverse,[]),include_attributes=False)==ast.dump(ast.Module(before,[]),include_attributes=False))
 namespace=dict(original.__dict__);calendar=original.calendar
 # This private facade changes only the source admission entry. Projection
 # obligation/field tables and bounded original read/write functions stay exact.
 namespace['calendar']=types.SimpleNamespace(**{**vars(calendar),'sources':verify_sources})
 namespace['_task_arguments']=types.SimpleNamespace(receipt=Path(receipt),output=Path(output))
 exec(compile(ast.fix_missing_locations(ast.Module(nodes,[])),str(ROOT/ORIGINAL),'exec'),namespace)
 return namespace['main']
def project(root,checkpoint,receipt,output):
 root=Path(root);need(root==ROOT and source.ROOT==ROOT)
 with source.open_task_source_operation(root,expected_checkpoint=checkpoint)as operation:
  source.require_task_operation(operation,root);need(operation.code_root==root and operation.producer_root==root)
  context=operation._task_contexts[root]
  module=captured(operation,root,ORIGINAL);raw=context.current(ORIGINAL)
  full=captured(operation,root,'scripts/governance/full_native_task_strict_body_v2.py').build_in_operation(operation,root)
  restore=full['native_validate_restore'];deps=restore.__globals__;native=types.SimpleNamespace(FIELDS=deps['FIELDS'],BLOCKERS=deps['BLOCKERS'])
  adapter=captured(operation,root,'scripts/governance/calendar_task_strict_body_v2.py')
  model=adapter.model(operation,root,captured,restore,deps['completion'],native)
  def verify():
   source.require_task_operation(operation,root);result=model['verify_sources']();source.require_task_operation(operation,root);return result
  # Failure receipts may have incomplete restore/MAC/cleanup. Never run success
  # receipt acceptance; original failure-only projection handles unavailable DATA.
  main=body(raw,module,verify,receipt,output);main();source.require_task_operation(operation,root)
 return True
class SafeParser(argparse.ArgumentParser):
 def error(self,message):raise SystemExit('Calendar diagnostic invocation unavailable')
if __name__=='__main__':
 p=SafeParser();p.add_argument('--root',required=True);p.add_argument('--expected-checkpoint',required=True);p.add_argument('--receipt',required=True);p.add_argument('--output',required=True);a=p.parse_args()
 try:project(a.root,a.expected_checkpoint,a.receipt,a.output)
 except BaseException:
  # No exception messages, raw receipt, SQL, paths or success claims are emitted.
  raise SystemExit(1)from None
