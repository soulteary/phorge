"""Typed CURRENT source owner, parent DATA only; never execution approval."""
import os,stat
from pathlib import Path
from contextlib import contextmanager
import source_operation_snapshot as source
from task_admission_test_api_v2 import checkpoint
@contextmanager
def owner(root):
 root=Path(root)
 with source.open_task_source_operation(root,expected_checkpoint=checkpoint())as op:
  source.require_task_operation(op,root);yield op
def parent_in(op,root,relative):
 root=Path(root);source.require_task_operation(op,root);context=op._task_contexts[root]
 if type(relative)is not str or relative not in context.entries:raise ValueError('unknown_parent_data')
 entry=context.entries[relative]
 if entry['kind']=='symlink':
  path=root/relative
  if source.task_admission.stamp(path.lstat())!=context.files[relative]or os.readlink(path)!=entry['target']:raise ValueError('parent_alias_drift')
  return context.parent_pins[relative]['target'].encode()
 return context.parent(relative)
def parent_bytes(root,relative):
 with owner(root)as op:result=parent_in(op,root,relative)
 if type(result)is not bytes:raise ValueError('parent_data_missing')
 return result
def snapshot(root):
 with owner(root)as op:result=op._modules['project_description_admission_delta'].description.current_snapshot(root)
 return result
def audit(root):
 with owner(root)as op:result=op._modules['project_description_admission_delta'].description.audit(root)
 return result
def normalize(root,path,raw):
 with owner(root)as op:result=op._modules['project_description_admission_delta']._normalize_production(path,raw)
 return result
@contextmanager
def src_rows(root):
 root=Path(root)
 with owner(root)as op:
  context=op._task_contexts[root];rows={}
  for path in sorted(context.entries):
   if not path.startswith('src/'):continue
   raw=parent_in(op,root,path)
   if raw is None:continue
   pin=context.parent_pins[path];mode='120000'if pin['kind']=='symlink'else'100755'if pin['mode']==0o755 else'100644';rows[path]=(mode,raw)
  yield rows
def recipe_data(root,op,current):
 root=Path(root);paths=set(current.CURRENT_METADATA)|set(current.description.CURRENT_PINS)|{current.MANIFEST,current.BRIDGE,current.PREPARATION,current.description.LEGACY_REDUCER['path'],'scripts/governance/project_content_grant_ci_source_delta.py'}
 for name in paths:
  path=root/name;path.parent.mkdir(parents=True,exist_ok=True);raw=parent_in(op,op.producer_root,name)
  if type(raw)is not bytes:raise ValueError('recipe_data_missing')
  path.write_bytes(raw);path.chmod(op._task_contexts[op.producer_root].parent_pins[name]['mode'])
