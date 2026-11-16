"""Closed fifteen-role deterministic adapter. Original tracked producers stay unchanged."""
import hashlib,json
MARKER=b'const N05_TASK_DIRECTORY_RUN_PROFILE_SHA256=null;'
def need(ok):
 if ok is not True:raise ValueError('Task_native_role_adapter_refused')
def role_manifest(raw,role):
 data=json.loads(raw);need(type(data)is dict and set(data)=={'schema','roles'}and data['schema']=='Task-native-role-adapter-v2')
 need(set(data['roles'])=={'directory','oauth-authorization','oauth-client-lifecycle','conduit','oauth-access','oauth-code','assessment-credential','api-credential-concurrency','application-config-facts','project-source-facts','spaces-source-facts','recovery-partial-composition','task-owner-source-facts','file-source-facts','file-parent-policy','session-producer-lease','session-borrowed-owner','organization-approval-workbench','calendar-reminders','full-native'}and role in data['roles'])
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
 argument=role['argument']
 if role['mode']=='full-native-Task-OFF':
  need(argument is None and role['source']=='support/governance/native-organization/test-native-organization-mysql.php'and role['native_call_anchor']=='$native=governance_native_organization_cases($fixture,$check);')
  guard=b'if(array_slice($argv??array(),1)!==array()){fwrite(STDERR,"Task_native_role_argument_refused\\n");exit(1); }\n'
 else:
  need(type(argument)is str and argument.startswith('--')and all(c in 'abcdefghijklmnopqrstuvwxyz0123456789-'for c in argument))
  guard=("if(array_slice($argv??array(),1)!==array('"+argument+"')){fwrite(STDERR,\"Task_native_role_argument_refused\\n\");exit(1);}"+'\n').encode()
 # Every retained producer uses the same bootstrap buffer reset.
 # Require one exact bootstrap seam for every admitted fixed registry role.
 need(role['mode'] in {'directory-Task-OFF','oauth-authorization-Task-OFF',
  'oauth-client-lifecycle-Task-OFF','conduit-Task-OFF','oauth-access-Task-OFF',
  'oauth-code-Task-OFF','assessment-credential-Task-OFF','api-credential-concurrency-Task-OFF','application-config-facts-Task-OFF','project-source-facts-Task-OFF','spaces-source-facts-Task-OFF','recovery-partial-composition-Task-OFF','task-owner-source-facts-Task-OFF','file-source-facts-Task-OFF','file-parent-policy-Task-OFF','session-producer-lease-Task-OFF','session-borrowed-owner-Task-OFF','organization-approval-workbench-Task-OFF','calendar-reminders-Task-OFF','full-native-Task-OFF'})
 # This producer clears all buffers in bootstrap; move only the wrapper's
 # capture registration immediately after its original bootstrap, before its
 # original inner capture, including dedicated directory and OAuth templates.
 capture_marker=b'// The original producer owns its inner native-output capture and all cleanup.'
 need(prefix.count(capture_marker)==1)
 prefix,capture=prefix.split(capture_marker,1);capture=capture_marker+capture
 bootstrap=b"require_once $root.'/support/runtime/bootstrap-cli.php';"
 need(body.count(bootstrap)==1)
 body=body.replace(bootstrap,bootstrap+b'\n'+capture+b'\n',1)
 return prefix+b'\n'+guard+body
