"""Genuine current Task source returns authenticated parent DATA only. No SQL."""
import os,re,sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import source_operation_snapshot as source
WORKFLOWS=frozenset({'.github/workflows/assessment-credential-focused.yml','.github/workflows/oauth-code-producer-focused.yml','.github/workflows/oauth-access-producer-focused.yml','.github/workflows/oauth-authorization-producer-focused.yml','.github/workflows/oauth-client-lifecycle-focused.yml','.github/workflows/conduit-producer-focused.yml','.github/workflows/native-directory-transports-v1.yml','.github/workflows/project-content-grants-focused.yml','.github/workflows/project-description-focused.yml','.github/workflows/atomic-feature-regressions.yml','.github/workflows/native-media-session-positive-sql.yml'})
def workflow_data_many(root,paths,raws=None,*,expected_checkpoint=None):
 root=Path(root)
 if not root.is_absolute()or root.resolve()!=root or root!=ROOT:raise ValueError('unknown_workflow_actor_root')
 if type(paths)not in(list,tuple)or not paths or any(type(path)is not str or path not in WORKFLOWS for path in paths)or len(set(paths))!=len(paths):raise ValueError('unknown_current_workflow_test_paths')
 if raws is not None and(type(raws)is not dict or set(raws)!=set(paths)or any(type(raw)is not bytes for raw in raws.values())):raise ValueError('unknown_current_workflow_test_raws')
 checkpoint=os.environ.get('PHORGE_TASK_TEST_CHECKPOINT')if expected_checkpoint is None else expected_checkpoint
 if type(checkpoint)is not str or re.fullmatch('[a-f0-9]{40}',checkpoint)is None or checkpoint=='0'*40:raise ValueError('external_Task_checkpoint_required')
 with source.open_task_source_operation(root,expected_checkpoint=checkpoint)as operation:
  source.require_task_operation(operation,root)
  if operation.code_root!=ROOT or operation.producer_root!=root or source.ROOT!=ROOT:raise ValueError('unknown_workflow_source_owner')
  context=operation._task_contexts[root];rows={}
  for path in paths:
   current=context.current(path);parent=context.parent(path)
   if raws is not None and raws[path]!=current:raise ValueError('unknown_current_workflow_test_bytes')
   if type(current)is not bytes or type(parent)is not bytes:raise ValueError('unbound_workflow_parent_DATA')
   rows[path]={'current':current,'parent':parent}
  result={'workflows':rows,'checkpoint':checkpoint,'source_operation_exit_verified':False}
 result['source_operation_exit_verified']=True;return result
def workflow_data(root,path,raw=None,*,expected_checkpoint=None):
 result=workflow_data_many(root,[path],None if raw is None else{path:raw},expected_checkpoint=expected_checkpoint)
 return dict(result['workflows'][path],checkpoint=result['checkpoint'],source_operation_exit_verified=result['source_operation_exit_verified'])
