#!/usr/bin/env python3
"""Explicit Task source-v2 consumer; never changes or impersonates v1 results."""
import argparse,hashlib,json,subprocess,sys,types
from pathlib import Path
import source_operation_snapshot as source
ROOT=Path(__file__).resolve().parents[2]
PARENT='5903d98b14926401f0be49cb0a9d6168026a2642'
def need(v):
 if v is not True:raise ValueError('Task_directory_source_v2_refused')
def sha(raw):return hashlib.sha256(raw).hexdigest()
def verify_in_operation(operation,root):
 root=Path(root);source.require_operation(operation,root);source.require_task_operation(operation,root)
 need(operation.code_root==ROOT and source.ROOT==ROOT)
 context=operation._task_contexts[root]
 relative='scripts/governance/check_directory_transports_v1.py';raw=context.current(relative)
 checker=types.ModuleType('Task_source_v2_actual_CURRENT_v1_checker');checker.__file__=str(operation.code_root/relative)
 exec(compile(raw,checker.__file__,'exec'),checker.__dict__)
 actual_manifest=context.current(checker.MANIFEST);manifest=json.loads(actual_manifest)
 need(type(manifest)is dict and set(manifest)=={'schema','base_revision','production_git_tree_inventory_sha256','pins'})
 need(manifest['schema']=='native-directory-transports-source-v1'and manifest['base_revision']=='149f315b4c29cdc5a9f3b2d10a92c6eab0403e1f')
 need(type(manifest['pins'])is dict and set(manifest['pins'])==checker.SOURCE_FILES and len(manifest['pins'])==76)
 for relative,pin in manifest['pins'].items():
  need(type(relative)is str and not relative.startswith('/')and'..'not in relative.split('/'))
  parent=operation._task_parent_bytes(root/relative)
  need(type(parent)is bytes and len(parent)==pin['bytes']and sha(parent)==pin['sha256'])
 # Historical Git objects are DATA only. No old module is executed and the
 # actual code/producer roots never switch to a historical checkout.
 env={'PATH':'/usr/bin:/bin','GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_GLOBAL':'/dev/null','GIT_NO_REPLACE_OBJECTS':'1','GIT_TERMINAL_PROMPT':'0','LANG':'C'}
 tree=subprocess.check_output(['git','--no-replace-objects','-C',str(operation.code_root),'ls-tree','-r',PARENT,'--','src','resources'],env=env,timeout=10)
 need(sha(tree)==manifest['production_git_tree_inventory_sha256']);names=set()
 for row in tree.splitlines():
  header,name=row.split(b'\t',1);mode,kind,oid=header.decode().split();relative=name.decode()
  need(kind=='blob'and relative not in names);names.add(relative);pin=context.parent_pins[relative]
  if pin['kind']=='symlink':
   need(mode=='120000'and context.entries[relative]==pin);parent=pin['target'].encode()
  else:
   need(mode==('100755'if pin['mode']==0o755 else'100644'));parent=operation._task_parent_bytes(root/relative)
  need(hashlib.sha1(b'blob '+str(len(parent)).encode()+b'\0'+parent).hexdigest()==oid)
 need(names=={p for p in context.parent_pins if p.startswith(('src/','resources/'))})
 return {'schema':'native-directory-transports-Task-source-v2','source_only_passed':True,'exact_current_checkpoint':operation._task_expected_checkpoint,'current_signed_leaf_count':len(context.entries),'original_manifest_sha256':sha(actual_manifest),'original76_pins_verified_against_exact_parent_projection':True,'complete_src_resources_parent_tree_verified':True,'parent_git_tree_inventory_sha256':sha(tree),'CURRENT_typed_SourceOperation_issued':True,'source_operation_exit_verified':False,'original_v1_CLI_or_76_test_suite_executed':False,'runtime_SQL_or_business_qualified':False,'SQL_executed':False,'old_manifest_or_pins_modified':False}
def verify(root,expected_checkpoint):
 root=Path(root)
 with source.open_task_source_operation(root,expected_checkpoint=expected_checkpoint)as operation:result=verify_in_operation(operation,root)
 result['source_operation_exit_verified']=True;return result
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--root',default=str(ROOT));p.add_argument('--expected-checkpoint',required=True);a=p.parse_args()
 try:print(json.dumps(verify(a.root,a.expected_checkpoint),sort_keys=True))
 except Exception:print('{"schema":"native-directory-transports-Task-source-v2","source_only_passed":false,"SQL_executed":false}');raise SystemExit(1)
