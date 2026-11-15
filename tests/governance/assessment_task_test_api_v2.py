"""Explicit test-only genuine Task APIs. No production default replacement."""
import os,re,sys,subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import source_operation_snapshot as source
import check_recovery_assessment_credentials_v2 as assessment
from task_description_consumer_test_api_v2 import known_dirty_rejection

def checkpoint():
 value=os.environ.get('PHORGE_TASK_TEST_CHECKPOINT')
 if type(value)is not str or re.fullmatch('[a-f0-9]{40}',value)is None or value=='0'*40:raise ValueError('external_Task_checkpoint_required')
 return value

def opened(root=ROOT,*,expected_checkpoint=None):
 expected=checkpoint()if expected_checkpoint is None else expected_checkpoint
 if type(expected)is not str or re.fullmatch('[a-f0-9]{40}',expected)is None or expected=='0'*40:raise ValueError('external_Task_checkpoint_required')
 return source.open_task_source_operation(root,expected_checkpoint=expected)
def call(action,root=ROOT,*,revision=None,issued=None):
 if type(action)is not str or action not in('SQL_obligations','obligations','summary'):raise ValueError('closed_Assessment_Task_action')
 if issued is not None and type(issued)is not list:raise ValueError('actual_owner_collection_only')
 expected=checkpoint()
 try:
  with opened(root,expected_checkpoint=expected)as operation:
   source.require_task_operation(operation,root)
   if issued is not None:issued.append(operation)
   if action=='SQL_obligations':result=assessment.SQL_obligations(root,_source_operation=operation)
   elif action=='obligations':result=assessment.obligations(root,_source_operation=operation)
   else:
    if type(revision)is not str or re.fullmatch('[a-f0-9]{40}',revision)is None:raise ValueError('explicit_summary_label')
    result=assessment.summary(revision,root,_source_operation=operation)
 except subprocess.CalledProcessError as error:
  normalize_git_refusal(error,ROOT,expected)
 return result

def normalize_git_refusal(error,root,expected_checkpoint):
 # Exact observed clean-check diff1 only; infrastructure errors stay original.
 if known_dirty_rejection(error,Path(root),expected_checkpoint):
  raise ValueError('Task_current_source_dirty_refused')from error
 raise error

def parent_inputs(paths):
 """Actual authorized reconstruction DATA only; never import/execute these bytes."""
 if type(paths)not in(set,frozenset)or paths!=set(assessment.SOURCE_INPUTS)|set(assessment.ADAPTER_SOURCE_INPUTS):raise ValueError('source_paths')
 with opened(ROOT)as operation:
  source.require_task_operation(operation,ROOT);context=operation._task_contexts[ROOT];result={}
  for path in sorted(paths):
   context.current(path);value=context.parent(path)
   if type(value)is not bytes:raise ValueError('missing_actual_parent_DATA')
   result[path]=value
 return result
