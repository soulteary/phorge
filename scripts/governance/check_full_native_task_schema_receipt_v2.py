#!/usr/bin/env python3
"""Default empty-argv native envelope, original native and notification contracts.
Read-only validation; no SQL execution, deployment or activation authority.
"""
import argparse,hashlib,json,re,types
from pathlib import Path
import source_operation_snapshot as source
import check_directory_transports_task_source_v2 as current_source
import check_directory_receipt_task_v2 as binary_source
ROOT=Path(__file__).resolve().parents[2]
IMAGE='mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7'
def need(value):
 if value is not True:raise ValueError('full_native_Task_receipt_refused')
def captured(operation,root,relative):
 source.require_task_operation(operation,root);raw=operation._task_contexts[root].current(relative)
 module=types.ModuleType('Task_CURRENT_'+Path(relative).stem);module.__file__=str(root/relative);exec(compile(raw,module.__file__,'exec'),module.__dict__);return module

def runtime_pair(before,after,revision,profile):
 fields={'schema','phase','source_revision','version','server_uuid_sha256','observed_service_image'}
 for phase,row in(('before',before),('after',after)):
  need(type(row)is dict and set(row)==fields and row['schema']=='native-full-mysql-runtime-v1'and row['phase']==phase and row['source_revision']==revision and row['version']=='8.4.13'and row['observed_service_image']==IMAGE and type(row['server_uuid_sha256'])is str and re.fullmatch('[a-f0-9]{64}',row['server_uuid_sha256'])is not None)
 expected=hashlib.sha256(profile['server_uuid'].encode()).hexdigest();need(before['server_uuid_sha256']==after['server_uuid_sha256']==expected)
 return {'actual_MySQL84_both_observations':True,'same_primary_UUID':True,'source_revision':revision,'observed_service_image':IMAGE}

def accept(root,checkpoint,consumer,envelope_path,native_body_path,profile_path,profile_sha,runtime_before,runtime_after,file_binary,file_sha,render_binary,render_sha,taskqueue_binary,taskqueue_sha):
 root=Path(root);need(consumer in('native','notifications')and type(checkpoint)is str and re.fullmatch('[a-f0-9]{40}',checkpoint)is not None and checkpoint!='0'*40)
 with source.open_task_source_operation(root,expected_checkpoint=checkpoint)as operation:
  source.require_task_operation(operation,root);need(operation.code_root==ROOT and operation.producer_root==ROOT and source.ROOT==ROOT)
  proof=current_source.verify_in_operation(operation,root);context=operation._task_contexts[root]
  schema=captured(operation,root,'support/governance/task-additive-schema-v2/validate.py')
  envelope_raw=schema.private(envelope_path);envelope=schema.decode(envelope_raw)
  projected,delta=schema.validate_envelope(envelope,profile_path,profile_sha,expected_mode='full-native-Task-OFF')
  need(delta['source_revision']==checkpoint and delta['source_root']==str(root)and delta['native_profile_mode']=='full-native-Task-OFF')
  for path,pin in delta['source_pins'].items():need(hashlib.sha256(context.current(path)).hexdigest()==pin)
  profile=schema.profile(profile_path,profile_sha)
  before_raw=schema.private(runtime_before);after_raw=schema.private(runtime_after);database=runtime_pair(schema.decode(before_raw),schema.decode(after_raw),checkpoint,profile)
  checker=binary_source.checker_for_operation(operation,root)
  binaries={}
  for kind,path,pin in(('file',file_binary,file_sha),('render',render_binary,render_sha),('taskqueue',taskqueue_binary,taskqueue_sha)):
   binaries[kind]=checker.file_binary_digest(path,pin)
  adapter=captured(operation,root,'scripts/governance/full_native_task_strict_body_v2.py');model=adapter.build_in_operation(operation,root)
  native_raw=schema.private(native_body_path);need(len(native_raw)<=1048576);raw_native=schema.decode(native_raw);model['native_exact_tree'](raw_native,envelope['native_receipt'])
  notification=None
  if consumer=='notifications':
   manifest=schema.decode(context.current('docs/governance/private-notification-producers.json'))
   notification=model['notification_validate'](manifest,root,operation=operation)
   need(notification['smtp_proved']is False and notification['all_producers_proved']is False and notification['activation_blocked']is True)
  accepted=model['native_validate'](projected,root,file_digest=binaries['file'],measurement_revision=checkpoint,render_digest=binaries['render'],operation=operation)
  need(accepted['schema_valid']is True and accepted['global_completion']is False and accepted['production_optional_features_enabled']is False and accepted['deployment_proved']is False and accepted['browser_proved']is False)
  need(schema.profile(profile_path,profile_sha)==profile)
  result={'schema':'default-full-native-Task-dual-consumer-receipt-v2','consumer':consumer,'original_full_native_strict':accepted,'original_private_notification_strict':notification,'Task_additive_schema':delta,'database_runtime':database,'actual_input_binary_sha256':binaries,'raw_envelope_sha256':hashlib.sha256(envelope_raw).hexdigest(),'raw_native_body_sha256':hashlib.sha256(native_raw).hexdigest(),'runtime_artifact_sha256':{'before':hashlib.sha256(before_raw).hexdigest(),'after':hashlib.sha256(after_raw).hexdigest()},'source_v2':proof,'source_operation_exit_verified':False,'checker_SQL_executed':False,'smtp_proved':False,'deployment_proved':False,'activation_authorized':False,'all_other14_Atomic_routes_qualified':False}
 result['source_operation_exit_verified']=True;result['source_v2']['source_operation_exit_verified']=True;return result
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--root',default=str(ROOT));p.add_argument('--consumer',required=True,choices=['native','notifications'])
 for name in('expected-checkpoint','envelope','native-body','profile','profile-sha','runtime-before','runtime-after','file-binary','file-sha','render-binary','render-sha','taskqueue-binary','taskqueue-sha'):p.add_argument('--'+name,required=True)
 a=p.parse_args()
 try:print(json.dumps(accept(a.root,a.expected_checkpoint,a.consumer,a.envelope,a.native_body,a.profile,a.profile_sha,a.runtime_before,a.runtime_after,a.file_binary,a.file_sha,a.render_binary,a.render_sha,a.taskqueue_binary,a.taskqueue_sha),sort_keys=True))
 except Exception:print('{"full_native_or_notification_accepted":false,"source_operation_exit_verified":false,"checker_SQL_executed":false}');raise SystemExit(1)
