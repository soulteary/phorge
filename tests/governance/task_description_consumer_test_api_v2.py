"""Actual CURRENT issuer API; no reader/key/root mutation or SQL."""
import os,re,types,subprocess,sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import source_operation_snapshot as source
def known_dirty_rejection(error,root,expected_checkpoint):
 # Git diff --quiet uses 1 for observed differences. No other subprocess
 # failure is an authorization refusal: preserve diagnostics by re-raising it.
 if type(error)is not subprocess.CalledProcessError or type(error.returncode)is not int or error.returncode!=1:return False
 if type(expected_checkpoint)is not str or re.fullmatch('[a-f0-9]{40}',expected_checkpoint)is None or expected_checkpoint=='0'*40:return False
 if not isinstance(root,Path)or not root.is_absolute()or type(error.cmd)is not list:return False
 if type(error.stdout)is not bytes or type(error.stderr)is not bytes or error.stdout!=b''or error.stderr!=b'':return False
 prefix=['git','-C',str(root),'diff']
 return error.cmd in(prefix+['--quiet',expected_checkpoint,'--'],prefix+['--cached','--quiet',expected_checkpoint,'--'])
def checkpoint():
 value=os.environ.get('PHORGE_TASK_TEST_CHECKPOINT')
 if type(value)is not str or re.fullmatch('[a-f0-9]{40}',value)is None or value=='0'*40:raise ValueError('external_Task_checkpoint_required')
 return value
def assert_recipe(root):
 with source.open_task_source_operation(Path(root),expected_checkpoint=checkpoint())as op:
  source.require_task_operation(op,Path(root));result=op._assert_current_recipe(Path(root))
 return result
def snapshot_prior(root):
 with source.open_task_source_operation(root,expected_checkpoint=checkpoint())as op:
  module=op._modules['project_description_admission_delta'].description
  snapshot=module.current_snapshot(root);prior=module.reconstruct_main(snapshot)
 return snapshot,prior
def pure_audit(root):
 with source.open_task_source_operation(root,expected_checkpoint=checkpoint())as op:result=op._modules['project_description_admission_delta'].description.audit(root)
 return result
def auth_in_operation(op,root):
 import oauth_task_strict_body_v2 as adapter
 import check_oauth_task_schema_receipt_v2 as consumer
 context=op._task_contexts[root];raws={name:context.current('scripts/governance/'+name+'.py')for name in adapter.PINS};model=adapter.model(raws,'oauth-authorization');model['adapter']=adapter
 return consumer.source_obligations(op,root,model,'oauth-authorization')[0]
def auth_obligations(root):
 root=Path(root)
 with source.open_task_source_operation(root,expected_checkpoint=checkpoint())as op:result=auth_in_operation(op,root)
 return result
CHILD=r'''import json,sys,os,subprocess
from pathlib import Path
root=Path(sys.argv[1]).resolve();sys.path.insert(0,str(root/'scripts/governance'));sys.path.insert(0,str(root/'tests/governance'));os.environ['PHORGE_TASK_TEST_CHECKPOINT']=sys.argv[2]
from task_description_consumer_test_api_v2 import auth_in_operation,known_dirty_rejection
import source_operation_snapshot as source
import check_recovery_assessment_credentials_v2 as assessment
out={};names=('cohort','authorization','assessment','auth_producer')
try:
 with source.open_task_source_operation(root,expected_checkpoint=sys.argv[2])as op:
  context=op._task_contexts[root];n05=op._modules['project_description_admission_delta'];authorization=op._modules['recovery_oauth_authorization_source_delta']
  for name,call in [('cohort',lambda:(op._assert_current_recipe(root),set(n05.description.CURRENT_PINS))[1]),('authorization',lambda:authorization.reduce_if_selected('src/__phutil_library_map__.php',context.parent('src/__phutil_library_map__.php'))),('assessment',lambda:assessment.SQL_obligations(root,_source_operation=op)),('auth_producer',lambda:auth_in_operation(op,root))]:
   try:value=call();out[name]={'accepted':True,'count':len(value)}
   except(ValueError,OSError):out[name]={'accepted':False}
   except subprocess.CalledProcessError as error:
    if not known_dirty_rejection(error,root,sys.argv[2]):raise
    out[name]={'accepted':False}
except(ValueError,OSError):out={name:{'accepted':False}for name in names}
except subprocess.CalledProcessError as error:
 if not known_dirty_rejection(error,root,sys.argv[2]):raise
 out={name:{'accepted':False}for name in names}
print(json.dumps(out,sort_keys=True))
'''
