#!/usr/bin/env python3
"""Explicit Task-OFF OAuth receipts. No SQL, legacy fallback, or producer authority."""
import argparse,ast,hashlib,json,re,types
from types import MappingProxyType
from pathlib import Path
import source_operation_snapshot as source
import check_directory_transports_task_source_v2 as current_source
import check_directory_receipt_task_v2 as file_source
ROOT=Path(__file__).resolve().parents[2]
ROLES={'oauth-authorization','oauth-client-lifecycle'}
def need(v):
 if v is not True:raise ValueError('Task_OAuth_native_consumer_refused')
def captured_module(operation,root,relative):
 source.require_operation(operation,root);source.require_task_operation(operation,root)
 raw=operation._task_contexts[root].current(relative)
 module=types.ModuleType('Task_CURRENT_'+Path(relative).stem);module.__file__=str(operation.code_root/relative)
 exec(compile(raw,module.__file__,'exec'),module.__dict__);return module
def driver_parent(operation,root,model,n05):
 """Same old registry role/normalization checks, actual typed captured DATA only."""
 context=operation._task_contexts[root]
 tree=model['parsed']['check_recovery_file_parent_policy']
 pure=model['adapter'];path=pure.literal(tree,'NATIVE_DRIVER_PATH');origin=pure.literal(tree,'NATIVE_DRIVER_TARGET_ORIGIN');target=pure.literal(tree,'NATIVE_DRIVER_TARGET_PIN')
 raw=context.parent(path);registry_name='support/governance/source-operation/registry-v1.json';registry_raw=context.parent(registry_name)
 need(type(raw)is bytes and type(registry_raw)is bytes and registry_name in n05.CURRENT_METADATA)
 n05._exact(registry_raw,n05.CURRENT_METADATA[registry_name]);registry=json.loads(registry_raw,object_pairs_hook=model['completion'].strict_object)
 need(registry.get('schema')=='source-operation-registry-v1'and registry.get('source_revision')==origin['revision']and registry.get('source_tree')==origin['tree'])
 need(type(registry.get('entries'))is dict and type(registry.get('successor_entries'))is dict)
 pin=registry['successor_entries'][path]['current']if path in registry['successor_entries']else registry['entries'][path]
 need(type(pin)is dict and set(pin)=={'bytes','sha256'}and type(pin['bytes'])is int and type(pin['sha256'])is str);n05._exact(raw,pin)
 if hashlib.sha256(raw).hexdigest()!=target['sha256']:
  row=n05.CURRENT_METADATA.get(path);need(type(row)in(dict,MappingProxyType) and row.get('target_bytes')==target['bytes']and row.get('target_sha256')==target['sha256']);raw=n05._normalize_metadata(path,raw)
 need(type(raw)is bytes and len(raw)==target['bytes']and hashlib.sha256(raw).hexdigest()==target['sha256'])
 return path,raw
def source_obligations(operation,root,model,role):
 source.require_operation(operation,root);source.require_task_operation(operation,root);operation._assert_current_recipe(root)
 context=operation._task_contexts[root];n05=operation._modules['project_description_admission_delta'];lifecycle=operation._modules['recovery_oauth_client_lifecycle_delta']
 # Internal reader is the genuine issuer's bound typed parent view, never a
 # caller callback or a replacement for its reader/key/owner implementation.
 reader=n05.native_c4_reader(root,operation._read_task_parent_producer)
 prior={}
 if role=='oauth-authorization':
  snapshot=n05.description.current_snapshot(root);prior=n05.description.reconstruct_main(snapshot)
  prior[n05.description.NATIVE_EDITOR_BASELINE['path']]=n05.description.reconstruct_native_editor(snapshot)
  path,raw=driver_parent(operation,root,model,n05);prior[path]=raw
  lifecycle.assert_cohort(root,reader)
 captured={}
 for relative,pin in model['source_inputs'].items():
  raw=context.parent(relative)if role=='oauth-authorization'else reader(root/relative)
  need(type(raw)is bytes)
  if role=='oauth-authorization':
   raw=prior.get(relative,raw);raw=lifecycle.reduce_if_selected(relative,raw)
   if relative=='support/governance/native-organization/test-native-organization-mysql.php'and hashlib.sha256(raw).hexdigest()!=pin:
    raw=operation._modules['recovery_conduit_source_delta'].reduce_policy_cleanup_driver(raw)
  need(hashlib.sha256(raw).hexdigest()==pin);captured[relative]=raw
 if role=='oauth-authorization':
  text=captured['support/governance/recovery/oauth-authorization-cases.php'].decode('utf-8')
  prefixes={p for p in re.findall("'(borrowed-oauth-authorization-[A-Za-z0-9-]+-)'",text)if p.endswith(('owner-','resumes-','binding-','InnoDB-'))}
  durable=set(re.findall("'(oauth-authorization-[A-Za-z0-9-]+)'",text));need(len(prefixes)==4 and len(durable)==4)
  sql={p+operation_name+'-'+ending for p in prefixes for operation_name in('insert','update')for ending in('commit','rollback')}|durable;need(len(sql)==20)
 else:
  sql={f'oauth-client-lifecycle-{op}-{end}-{kind}'for op in model['operations']for end in('commit','rollback')for kind in('client-first-and-granted-record','native-writer-exact-record-wait','owner-terminal-writer-native-postcondition','next-store-api-binding-changed')}|{'oauth-client-lifecycle-durable-submit-five-sources','oauth-client-lifecycle-durable-replay-review-blocked','oauth-client-lifecycle-native-trust-save-invalidates-review','oauth-client-lifecycle-execute-requires-complete-sources'};need(len(sql)==52)
 operation._assert_current_recipe(root);return sql,model['bootstrap']|sql
def accept(root,expected_checkpoint,role,envelope_path,profile_path,profile_sha,runtime_before,runtime_after,file_binary,file_sha):
 root=Path(root);need(role in ROLES and type(expected_checkpoint)is str and re.fullmatch('[a-f0-9]{40}',expected_checkpoint)is not None and expected_checkpoint!='0'*40)
 with source.open_task_source_operation(root,expected_checkpoint=expected_checkpoint)as operation:
  proof=current_source.verify_in_operation(operation,root);source.require_task_operation(operation,root)
  need(operation.code_root==ROOT and source.ROOT==ROOT)
  context=operation._task_contexts[root]
  validator=captured_module(operation,root,'support/governance/task-additive-schema-v2/validate.py')
  envelope_raw=validator.private(envelope_path);envelope=validator.decode(envelope_raw)
  projected,delta=validator.validate_envelope(envelope,profile_path,profile_sha,expected_mode=role+'-Task-OFF')
  need(delta['source_revision']==expected_checkpoint and delta['source_root']==str(root)and delta['native_profile_mode']==role+'-Task-OFF')
  for relative,pin in delta['source_pins'].items():need(hashlib.sha256(context.current(relative)).hexdigest()==pin)
  private_profile=validator.profile(profile_path,profile_sha)
  adapter=captured_module(operation,root,'scripts/governance/oauth_task_strict_body_v2.py')
  raws={name:context.current('scripts/governance/'+name+'.py')for name in adapter.PINS};model=adapter.model(raws,role);model['adapter']=adapter
  sql,expected=source_obligations(operation,root,model,role)
  checker=file_source.checker_for_operation(operation,root);actual_file=checker.file_binary_digest(file_binary,file_sha)
  need(model['read_checked'](file_binary,binary=True)==actual_file)
  before_raw=model['read_checked'](runtime_before);after_raw=model['read_checked'](runtime_after)
  before=validator.decode(before_raw);after=validator.decode(after_raw)
  runtime=model['runtime'](before,after,expected_checkpoint)
  expected_uuid=hashlib.sha256(private_profile['server_uuid'].encode()).hexdigest();need(before['server_uuid_sha256']==after['server_uuid_sha256']==expected_uuid)
  native=model['validate'](projected,root,source_revision=expected_checkpoint,file_digest=actual_file,sql_obligations=sql,expected_obligations=expected,operation=operation)
  # Recheck private profile after all old strict fields and runtime checks;
  # changing the source, profile or owner window prevents accepted exit.
  need(validator.profile(profile_path,profile_sha)==private_profile)
  result={'schema':'native-OAuth-Task-source-schema-receipt-v2','role':role,'native_receipt_accepted':True,'strict_original_body':native,'database_runtime':runtime,'SQL_obligations':len(sql),'source_v2':proof,'Task_additive_schema':delta,'exact_current_checkpoint':expected_checkpoint,'raw_envelope_sha256':hashlib.sha256(envelope_raw).hexdigest(),'runtime_observation_sha256':{'before':hashlib.sha256(before_raw).hexdigest(),'after':hashlib.sha256(after_raw).hexdigest()},'paired_file_binary_sha256':actual_file,'source_operation_exit_verified':False,'SQL_executed_by_checker':False,'production_enabled':False,'permission_migration':False,'Task_metadata_business_qualified':False}
 result['source_operation_exit_verified']=True;result['source_v2']['source_operation_exit_verified']=True;return result
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--root',default=str(ROOT));p.add_argument('--role',choices=sorted(ROLES),required=True)
 for name in('expected-checkpoint','envelope','profile','profile-sha','runtime-before','runtime-after','file-binary','file-sha'):p.add_argument('--'+name,required=True)
 a=p.parse_args()
 try:print(json.dumps(accept(a.root,a.expected_checkpoint,a.role,a.envelope,a.profile,a.profile_sha,a.runtime_before,a.runtime_after,a.file_binary,a.file_sha),sort_keys=True))
 except Exception:print('{"native_receipt_accepted":false,"source_operation_exit_verified":false,"SQL_executed_by_checker":false}');raise SystemExit(1)
