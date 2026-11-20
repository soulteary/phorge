#!/usr/bin/env python3
"""Explicit Task current graph + unchanged BASE freshness; no BASE re-pinning."""
import argparse,builtins,hashlib,json,sys,types
from pathlib import Path
import source_operation_snapshot as source
ROOT=Path(__file__).resolve().parents[2]
EXPECTED_BASE='3f716e3d66dbe8645ad2041e261c3f389f6a319e'
EXPECTED_TREE='275a9fc1e0092de2b98a02bc063e566b0f99744d'
def need(v):
 if v is not True:raise ValueError('Task_media_source_v2_refused')
def verify_in_operation(operation,root):
 root=Path(root);source.require_operation(operation,root);source.require_task_operation(operation,root)
 need(operation.code_root==ROOT and source.ROOT==ROOT);context=operation._task_contexts[root]
 helper='support/governance/media-session-docker/snapshot.py'
 snapshot=types.ModuleType('Task_media_v2_captured_CURRENT_snapshot');snapshot.__file__=str(operation.code_root/helper)
 exec(compile(context.current(helper),snapshot.__file__,'exec'),snapshot.__dict__)
 relative='support/governance/media-session-ci/freshness.py';raw=context.current(relative)
 freshness=types.ModuleType('Task_media_v2_actual_CURRENT_freshness');freshness.__file__=str(operation.code_root/relative)
 original_import=builtins.__import__
 def captured_import(name,globals=None,locals=None,fromlist=(),level=0):
  need(level==0)
  if name=='snapshot':return snapshot
  need(name.split('.')[0]in{'hashlib','json','os','re','stat','subprocess','sys','pathlib'})
  return original_import(name,globals,locals,fromlist,level)
 freshness.__dict__['__builtins__']={**vars(builtins),'__import__':captured_import}
 exec(compile(raw,freshness.__file__,'exec'),freshness.__dict__)
 # Freshness imports actual CURRENT snapshot helpers. They are whole-current
 # captured sources, never executable modules taken from BASE objects.
 need(freshness.snapshot.BASE==EXPECTED_BASE and freshness.snapshot.ROOT_TREE==EXPECTED_TREE)
 helper='support/governance/media-session-docker/snapshot.py'
 need(Path(freshness.snapshot.__file__).resolve()==operation.code_root/helper)
 need(hashlib.sha256(context.current(helper)).hexdigest()==hashlib.sha256((operation.code_root/helper).read_bytes()).hexdigest())
 base,proof=freshness.snapshot.base_entries(operation.code_root);expected={p:v for p,v in base.items()if freshness.protected(p)}
 parent_names={p for p in context.parent_pins if freshness.protected(p)};need(parent_names==set(expected)and len(expected)==8419)
 for relative,value in expected.items():
  pin=context.parent_pins[relative]
  if pin['kind']=='symlink':
   need(context.entries[relative]==pin);projected=('symlink',pin['target'],0o777)
  else:
   from check_directory_transports_task_source_v2 import parent_DATA
   projected=('file',parent_DATA(operation,root,relative),pin['mode'])
  need(projected==value)
 # Preserve the original no-runtime-diff boundary: approved prepared packages
 # are not silently treated as a clean media source checkout.
 current={p:pin for p,pin in context.entries.items()if freshness.protected(p)};seen=freshness.actual(root)
 need(set(seen)==set(current))
 for relative,pin in current.items():
  if pin['kind']=='symlink':need(seen[relative]==('symlink',pin['target'],0o777))
  else:
   need(seen[relative]==('file',None,pin['mode']));context.current(relative)
 need(len(freshness.snapshot.OVERLAY)==8)
 for relative,pin in freshness.snapshot.OVERLAY.items():need(hashlib.sha256(context.current(relative)).hexdigest()==pin)
 return {'schema':'media-Task-positive-protected-source-freshness-v2','source_only_passed':True,'exact_current_checkpoint':operation._task_expected_checkpoint,'current_signed_leaf_count':len(context.entries),'current_protected_graph_bound':True,'current_protected_leaf_count':len(current),'parent_protected_graph_equal_to_original_BASE':True,'original_BASE':EXPECTED_BASE,'original_BASE_tree':EXPECTED_TREE,'original_BASE_leaf_count':len(expected),'original_reviewed_overlay_count':8,'CURRENT_typed_SourceOperation_issued':True,'source_operation_exit_verified':False,'runtime_diff_allowed':False,'old_BASE_or_overlay_pins_modified':False,'original_v1_freshness_executed':False,'runtime_SQL_or_business_qualified':False,'SQL_executed':False}
def verify(root,expected_checkpoint):
 root=Path(root)
 with source.open_task_source_operation(root,expected_checkpoint=expected_checkpoint)as operation:result=verify_in_operation(operation,root)
 result['source_operation_exit_verified']=True;return result
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--root',default=str(ROOT));p.add_argument('--expected-checkpoint',required=True);a=p.parse_args()
 try:print(json.dumps(verify(a.root,a.expected_checkpoint),sort_keys=True))
 except Exception:print('{"schema":"media-Task-positive-protected-source-freshness-v2","source_only_passed":false,"SQL_executed":false}');raise SystemExit(1)
