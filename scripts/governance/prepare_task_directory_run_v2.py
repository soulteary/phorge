#!/usr/bin/env python3
"""Explicit source binding; standalone preparation never executes PHP or SQL."""
import argparse,hashlib,json,os,secrets,types
from pathlib import Path
import source_operation_snapshot as source
COMMON=("resources/sql/autopatches/20261112.task.contentgrants.sql","src/infrastructure/governance/content/PhorgeTaskContentGrantSchemaSpec.php","support/governance/completion/fixture.php","resources/sql/quickstart.sql","support/governance/task-additive-schema-v2/capture.php","support/governance/task-additive-schema-v2/run.php","support/governance/task-additive-schema-v2/producer-roles-v2.json","support/governance/task-additive-schema-v2/producer-prefix.php")
ADAPTER='support/governance/task-additive-schema-v2/producer_adapter.py'
MARKER=b"const N05_TASK_DIRECTORY_RUN_PROFILE_SHA256=null;"
def need(ok):
 if ok is not True:raise ValueError("Task_directory_run_prepare_refused")
def put(path,raw):
 fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
 with os.fdopen(fd,"wb")as f:f.write(raw);f.flush();os.fsync(f.fileno())
def capture(operation,root,role):
 source.require_task_operation(operation,root);context=operation._task_contexts[root]
 adapter=types.ModuleType('Task_CURRENT_closed_role_adapter');adapter.__file__=str(root/ADAPTER)
 exec(compile(context.current(ADAPTER),adapter.__file__,'exec'),adapter.__dict__)
 captures={p:context.current(p)for p in COMMON};spec=adapter.role_manifest(captures[COMMON[-2]],role)
 captures[spec['source']]=context.current(spec['source'])
 template=adapter.adapt(captures[spec['source']],captures[COMMON[-1]],spec)
 return captures,template

def prepare(root,expected_checkpoint,directory,role='directory'):
 root=Path(root);directory=Path(directory)
 need(root.resolve(strict=True)==root and directory.parent.resolve(strict=True)==directory.parent and not directory.exists()and not directory.is_symlink())
 with source.open_task_source_operation(root,expected_checkpoint=expected_checkpoint)as operation:captures,template=capture(operation,root,role)
 # No runnable private source is published on failed scope exit.
 result=publish(root,expected_checkpoint,directory,captures,template,role);result['source_scope_exit_verified']=True;return result

def publish(root,expected_checkpoint,directory,captures,template,role):
 need(role in{'directory','oauth-authorization','oauth-client-lifecycle','conduit','oauth-access','oauth-code','assessment-credential','api-credential-concurrency','application-config-facts','project-source-facts','spaces-source-facts','recovery-partial-composition','task-owner-source-facts','file-source-facts','file-parent-policy','session-producer-lease','session-borrowed-owner','full-native'}and template.count(MARKER)==1)
 directory.mkdir(mode=0o700)
 pins={p:hashlib.sha256(raw).hexdigest()for p,raw in captures.items()}
 profile={"schema":"Task-directory-run-binding-v2","runtime_authorized":True,"source_root":str(root),"source_revision":expected_checkpoint,"run_id":secrets.token_hex(16),"hmac_key":secrets.token_hex(32),"source_pins":pins,"output":str(directory/"capture"),'native_role':role,'producer_template_sha256':hashlib.sha256(template).hexdigest()}
 raw=(json.dumps(profile,sort_keys=True,separators=(",",":"),ensure_ascii=False)+"\n").encode();pin=hashlib.sha256(raw).hexdigest();put(directory/"run-profile.private.json",raw)
 replacement=("const N05_TASK_DIRECTORY_RUN_PROFILE_SHA256='"+pin+"';").encode();put(directory/"producer-bound.php",template.replace(MARKER,replacement))
 return {"schema":"Task-directory-run-prepared-v2","profile_sha256":pin,"source_revision":expected_checkpoint,"source_scope_exit_verified":False,"PHP_executed":False,"SQL_executed":False}
if __name__=="__main__":
 p=argparse.ArgumentParser();p.add_argument("--root",required=True);p.add_argument("--expected-checkpoint",required=True);p.add_argument("--directory",required=True);p.add_argument('--role',default='directory',choices=['directory','oauth-authorization','oauth-client-lifecycle','conduit','oauth-access','oauth-code','assessment-credential','api-credential-concurrency','application-config-facts','project-source-facts','spaces-source-facts','recovery-partial-composition','task-owner-source-facts','file-source-facts','file-parent-policy','session-producer-lease','session-borrowed-owner','full-native']);a=p.parse_args();print(json.dumps(prepare(a.root,a.expected_checkpoint,a.directory,a.role),sort_keys=True))
