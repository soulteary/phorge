"""Explicit Project-native-parent source admission via genuine current Task scope.
Historical Git is immutable DATA only. No old module/root or global substitution.
"""
import hashlib,os,subprocess
from pathlib import Path
import source_operation_snapshot as source
RUNTIME=('src','resources','bin','scripts','externals','webroot','support/runtime','support/startup','support/phame','support/preamble.proxy-https.php','conf')
OFFLINE=frozenset(('scripts/governance/check_recovery_oauth_authorization_producer_v1.py','scripts/governance/project_content_grant_source_delta.py','scripts/governance/project_content_grant_ci_source_delta.py','scripts/governance/project_description_admission_delta.py','scripts/governance/recovery_oauth_authorization_source_delta.py','scripts/governance/check_directory_transports_v1.py'))
def need(ok):
 if ok is not True:raise ValueError('Task_CI_native_parent_source_refused')
def selected(path):return any(path==p or path.startswith(p+'/')for p in RUNTIME)and path not in OFFLINE
def verify(root,expected_checkpoint,target):
 root=Path(root)
 with source.open_task_source_operation(root,expected_checkpoint=expected_checkpoint)as operation:
  source.require_operation(operation,root);source.require_task_operation(operation,root);context=operation._task_contexts[root]
  env={'PATH':'/usr/bin:/bin','GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_GLOBAL':'/dev/null','GIT_NO_REPLACE_OBJECTS':'1','GIT_TERMINAL_PROMPT':'0'}
  raw=subprocess.check_output(['/usr/bin/git','--no-replace-objects','-C',str(operation.code_root),'ls-tree','-rz',target,'--',*RUNTIME],env=env,timeout=10);names=set()
  for item in raw.split(b'\0'):
   if not item:continue
   header,name=item.split(b'\t',1);mode,kind,oid=header.decode().split();relative=name.decode()
   if relative in OFFLINE:continue
   need(kind=='blob'and relative not in names);names.add(relative);pin=context.parent_pins[relative]
   if pin['kind']=='symlink':need(mode=='120000'and context.entries[relative]==pin);body=pin['target'].encode()
   else:need(mode==('100755'if pin['mode']==0o755 else'100644'));body=operation._task_parent_bytes(root/relative)
   need(hashlib.sha1(b'blob '+str(len(body)).encode()+b'\0'+body).hexdigest()==oid)
  need(names=={p for p in context.parent_pins if selected(p)})
 return expected_checkpoint
