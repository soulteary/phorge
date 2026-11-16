"""Explicit genuine Task source test API. No authority from fixture data alone."""
import os,re,json
from pathlib import Path
import source_operation_snapshot as source
def checkpoint():
 v=os.environ.get('PHORGE_TASK_TEST_CHECKPOINT')
 if type(v)is not str or re.fullmatch('[a-f0-9]{40}',v)is None or v=='0'*40:raise ValueError('external_Task_checkpoint_required')
 return v
def assert_recipe(root):
 root=Path(root)
 with source.open_task_source_operation(root,expected_checkpoint=checkpoint())as op:
  source.require_task_operation(op,root);result=op._assert_current_recipe(root)
 return result
def directory_source(root,revision=None):
 root=Path(root);expected=checkpoint()
 if revision is not None and revision!=expected:raise ValueError('unexpected_test_revision')
 import check_directory_transports_task_source_v2 as directory
 with source.open_task_source_operation(root,expected_checkpoint=expected)as op:
  directory.verify_in_operation(op,root)
  result=json.loads(op._task_contexts[root].parent('tests/governance/native-directory-transports-source-v1.json'))
 return result
def expected_manifest(root):
 root=Path(root)
 with source.open_task_source_operation(root,expected_checkpoint=checkpoint())as op:result=op._modules['project_description_admission_delta'].expected_manifest(root)
 return result
