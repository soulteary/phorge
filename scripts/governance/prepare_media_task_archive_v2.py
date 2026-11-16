"""Actual current source/archive component, never worker/SQL/cleanup authority.
A future real runtime driver must call prepare_in_operation in its SAME owner.
"""
import argparse,hashlib,json,types
from pathlib import Path
import source_operation_snapshot as source
ROOT=Path(__file__).resolve().parents[2]
SELF='scripts/governance/prepare_media_task_archive_v2.py'
ARCHIVE='scripts/governance/media_task_archive_v2.py'
FRESHNESS='scripts/governance/check_media_freshness_task_source_v2.py'
def need(value):
 if value is not True:raise ValueError('media_Task_archive_owner_refused')
def current_module(operation,root,relative):
 source.require_task_operation(operation,root);raw=operation._task_contexts[root].current(relative)
 need(relative in{ARCHIVE,FRESHNESS})
 module=types.ModuleType('Media_CURRENT_'+Path(relative).stem);module.__file__=str(root/relative)
 exec(compile(raw,module.__file__,'exec'),module.__dict__);return module

def prepare_in_operation(operation,root,output):
 root=Path(root);output=Path(output)
 source.require_operation(operation,root);source.require_task_operation(operation,root)
 need(root==ROOT and source.ROOT==root and operation.code_root==root and operation.producer_root==root)
 context=operation._task_contexts[root];need(context.operation is operation and context.current(SELF)==Path(__file__).read_bytes())
 freshness=current_module(operation,root,FRESHNESS)
 source_data=freshness.verify_in_operation(operation,root)
 need(source_data['exact_current_checkpoint']==operation._task_expected_checkpoint and source_data['source_operation_exit_verified']is False and source_data['runtime_SQL_or_business_qualified']is False and source_data['SQL_executed']is False)
 archive=current_module(operation,root,ARCHIVE)
 archive_path,archive_manifest=archive.build_current(operation,root,output)
 need(archive_path==output/'current-source.tar'and archive_manifest['actual_checkpoint']==operation._task_expected_checkpoint)
 need(all(archive_manifest[k]is False for k in('source_operation_exit_verified','runtime_authority','runtime_SQL_qualified','cleanup_proved')))
 archive_data={'archive_path':str(archive_path),'inventory_path':str(output/'current-source-inventory.private.json'),'manifest':archive_manifest}
 source.require_task_operation(operation,root)
 return {'schema':'media-current-archive-owner-component-v2','checkpoint':operation._task_expected_checkpoint,'current_source_only':source_data,'archive_DATA':archive_data,'archive_component_sha256':hashlib.sha256(context.current(ARCHIVE)).hexdigest(),'controller_component_sha256':hashlib.sha256(context.current(SELF)).hexdigest(),'source_only_operation_exit_verified':False,'archive_is_SourceOperation_issuer':False,'archive_is_runtime_authorization':False,'worker_or_SQL_executed':False,'worker_readback_proved':False,'cleanup_proved':False,'runtime_acceptance':False,'runtime_driver_same_owner_still_required':True}

def prepare(root,checkpoint,output):
 root=Path(root)
 with source.open_task_source_operation(root,expected_checkpoint=checkpoint)as operation:
  result=prepare_in_operation(operation,root,output)
 # This proves only the archive-producing source window; it is not a handoff
 # granting a future independent process/source window runtime authority.
 result['source_only_operation_exit_verified']=True
 result['current_source_only']['source_operation_exit_verified']=True
 return result
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--root',default=str(ROOT));p.add_argument('--expected-checkpoint',required=True);p.add_argument('--output',required=True);a=p.parse_args()
 try:print(json.dumps(prepare(a.root,a.expected_checkpoint,a.output),sort_keys=True))
 except Exception:
  try:print('{"source_only_operation_exit_verified":false,"runtime_acceptance":false,"worker_or_SQL_executed":false,"cleanup_proved":false}')
  finally:raise SystemExit(1)
