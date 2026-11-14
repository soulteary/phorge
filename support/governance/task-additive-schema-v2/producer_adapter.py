"""Closed three-role deterministic adapter. Original tracked producers stay unchanged."""
import hashlib,json
MARKER=b'const N05_TASK_DIRECTORY_RUN_PROFILE_SHA256=null;'
def need(ok):
 if ok is not True:raise ValueError('Task_native_role_adapter_refused')
def role_manifest(raw,role):
 data=json.loads(raw);need(type(data)is dict and set(data)=={'schema','roles'}and data['schema']=='Task-native-role-adapter-v2')
 need(set(data['roles'])=={'directory','oauth-authorization','oauth-client-lifecycle'}and role in data['roles'])
 value=data['roles'][role];need(set(value)=={'source','source_sha256','argument','native_call_anchor','mode'});return value

def adapt(raw,prefix,role):
 need(type(raw)is bytes and hashlib.sha256(raw).hexdigest()==role['source_sha256']and raw.startswith(b'<?php'))
 need(type(prefix)is bytes and prefix.startswith(b'<?php')and prefix.count(MARKER)==1)
 anchor=role['native_call_anchor'].encode();need(raw.count(anchor)==1)
 # All __DIR__ references retain their original directory semantics in the
 # private copy; the prefix binds its actual source directory before bootstrap.
 body=raw[len(b'<?php'):].replace(b'__DIR__',b'$native_directory')
 before=b"$task_binding=PhorgeTaskDirectoryRunV2::prepare($fixture,$task_run,isset($restore_progress)&&is_array($restore_progress)?$restore_progress:array());$task_before=PhorgeTaskAdditiveSchemaCaptureV2::capture($fixture,$task_binding['profile'],'before');\n"
 after=b"\n$task_after=PhorgeTaskAdditiveSchemaCaptureV2::capture($fixture,$task_binding['profile'],'after');"
 body=body.replace(anchor,before+anchor+after,1)
 return prefix+b'\n'+body
