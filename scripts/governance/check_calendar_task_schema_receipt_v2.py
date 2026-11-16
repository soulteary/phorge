#!/usr/bin/env python3
"""Actual Calendar strict body with Task-OFF provenance; no SQL issuer."""
import argparse,hashlib,json,re,types
from pathlib import Path
import source_operation_snapshot as source
import check_directory_transports_task_source_v2 as current_source
import check_directory_receipt_task_v2 as file_source
from check_full_native_task_schema_receipt_v2 import captured,runtime_pair
ROOT=Path(__file__).resolve().parents[2]
def need(value):
 if value is not True:raise ValueError('Calendar_Task_receipt_refused')
def accept(root,checkpoint,envelope_path,native_body_path,profile_path,profile_sha,runtime_before,runtime_after,file_binary,file_sha,queue_binary,queue_sha):
 root=Path(root);role='calendar-reminders';need(type(checkpoint)is str and re.fullmatch('[a-f0-9]{40}',checkpoint)is not None and checkpoint!='0'*40)
 with source.open_task_source_operation(root,expected_checkpoint=checkpoint)as operation:
  source.require_task_operation(operation,root);need(operation.code_root==ROOT and operation.producer_root==ROOT and source.ROOT==ROOT)
  proof=current_source.verify_in_operation(operation,root);context=operation._task_contexts[root]
  schema=captured(operation,root,'support/governance/task-additive-schema-v2/validate.py')
  envelope_raw=schema.private(envelope_path);envelope=schema.decode(envelope_raw)
  projected,delta=schema.validate_envelope(envelope,profile_path,profile_sha,expected_mode=role+'-Task-OFF')
  need(delta['source_revision']==checkpoint and delta['source_root']==str(root)and delta['native_profile_mode']==role+'-Task-OFF')
  for relative,pin in delta['source_pins'].items():need(hashlib.sha256(context.current(relative)).hexdigest()==pin)
  profile=schema.profile(profile_path,profile_sha);before_raw=schema.private(runtime_before);after_raw=schema.private(runtime_after)
  database=runtime_pair(schema.decode(before_raw),schema.decode(after_raw),checkpoint,profile)
  full=captured(operation,root,'scripts/governance/full_native_task_strict_body_v2.py').build_in_operation(operation,root)
  native_raw=schema.private(native_body_path);need(len(native_raw)<=1048576);full['native_exact_tree'](schema.decode(native_raw),envelope['native_receipt'])
  adapter=captured(operation,root,'scripts/governance/calendar_task_strict_body_v2.py')
  restore=full['native_validate_restore'];dependencies=restore.__globals__;native=types.SimpleNamespace(FIELDS=dependencies['FIELDS'],BLOCKERS=dependencies['BLOCKERS'])
  model=adapter.model(operation,root,captured,restore,dependencies['completion'],native)
  checker=file_source.checker_for_operation(operation,root);actual_file=checker.file_binary_digest(file_binary,file_sha);actual_queue=checker.file_binary_digest(queue_binary,queue_sha)
  original=model['validate'](projected,source_revision=checkpoint,file_digest=actual_file,queue_digest=actual_queue)
  need(original['passed']is True and original['checks']==32 and original['calendar_obligations']==21 and original['source_inputs']==12 and original['physical_SMTP_or_browser_proved']is False and original['native_global_lock_or_daemon_proved']is False and original['ephemeral_ghost_delivery_supported']is False and original['activation_blocked']is True)
  need(schema.profile(profile_path,profile_sha)==profile)
  result={'schema':'Calendar-Task-OFF-receipt-v2','original_strict_calendar':original,'Task_additive_schema':delta,'source_v2':proof,'database_runtime':database,'raw_envelope_sha256':hashlib.sha256(envelope_raw).hexdigest(),'raw_native_body_sha256':hashlib.sha256(native_raw).hexdigest(),'runtime_artifact_sha256':{'before':hashlib.sha256(before_raw).hexdigest(),'after':hashlib.sha256(after_raw).hexdigest()},'source_operation_exit_verified':False,'checker_SQL_executed':False,'deployment_proved':False,'production_optional_features_enabled':False,'permission_migration':False,'paired_file_binary_sha256':actual_file,'paired_taskqueue_binary_sha256':actual_queue}
 result['source_operation_exit_verified']=True;result['source_v2']['source_operation_exit_verified']=True;return result
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--root',default=str(ROOT))
 for name in('expected-checkpoint','envelope','native-body','profile','profile-sha','runtime-before','runtime-after','file-binary','file-sha','queue-binary','queue-sha'):p.add_argument('--'+name,required=True)
 a=p.parse_args()
 try:print(json.dumps(accept(a.root,a.expected_checkpoint,a.envelope,a.native_body,a.profile,a.profile_sha,a.runtime_before,a.runtime_after,a.file_binary,a.file_sha,a.queue_binary,a.queue_sha),sort_keys=True))
 except Exception:print('{"Calendar_accepted":false,"source_operation_exit_verified":false,"checker_SQL_executed":false}');raise SystemExit(1)
