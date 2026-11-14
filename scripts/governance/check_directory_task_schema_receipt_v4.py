#!/usr/bin/env python3
"""Current Task schema + unchanged strict v1 body, no legacy-shape fallback."""
import argparse,hashlib,json,types
from pathlib import Path
import source_operation_snapshot as source
import check_directory_transports_task_source_v2 as current_source
import check_directory_receipt_task_v2 as native_body
ROOT=Path(__file__).resolve().parents[2]
def need(v):
 if v is not True:raise ValueError('Task_schema_native_v4_refused')
def accept(root,expected_checkpoint,envelope_path,profile_path,expected_profile_sha256,file_binary,file_binary_sha256):
 root=Path(root)
 with source.open_task_source_operation(root,expected_checkpoint=expected_checkpoint)as operation:
  proof=current_source.verify_in_operation(operation,root)
  source.require_task_operation(operation,root);context=operation._task_contexts[root]
  relative='support/governance/task-additive-schema-v2/validate.py';raw=context.current(relative)
  validator=types.ModuleType('Task_actual_CURRENT_additive_schema_validator');validator.__file__=str(operation.code_root/relative);exec(compile(raw,validator.__file__,'exec'),validator.__dict__)
  envelope=validator.decode(validator.private(envelope_path));projected,delta=validator.validate_envelope(envelope,profile_path,expected_profile_sha256)
  need(delta['source_revision']==expected_checkpoint and delta['source_root']==str(root))
  for path,pin in delta['source_pins'].items():need(hashlib.sha256(context.current(path)).hexdigest()==pin)
  checker=native_body.checker_for_operation(operation,root);actual_file=checker.file_binary_digest(file_binary,file_binary_sha256)
  original=native_body.strict_native_body(checker,projected,root,actual_file)
  result={'schema':'native-directory-current-Task-schema-accepted-v4','selected_inprocess_matrix_accepted':True,'strict_original_native_body':original,'source_v2':proof,'Task_additive_schema':delta,'raw_envelope_sha256':hashlib.sha256(validator.encoded(envelope)).hexdigest(),'exact_current_checkpoint':expected_checkpoint,'source_bound_by_Task_v4_CLI':False,'new_SQL_execution_proved_by_checker':False,'actual_Task_metadata_business_operations_qualified':False,'HTTP_browser_proved':False,'production_enabled':False}
 result['source_v2']['source_operation_exit_verified']=True;result['source_bound_by_Task_v4_CLI']=True;return result
if __name__=='__main__':
 parser=argparse.ArgumentParser();parser.add_argument('--root',default=str(ROOT))
 for name in('expected-checkpoint','envelope','profile','expected-profile-sha256','file-binary','file-binary-sha256'):parser.add_argument('--'+name,required=True)
 a=parser.parse_args()
 try:print(json.dumps(accept(a.root,a.expected_checkpoint,a.envelope,a.profile,a.expected_profile_sha256,a.file_binary,a.file_binary_sha256),sort_keys=True))
 except Exception:print('{"schema":"native-directory-current-Task-schema-accepted-v4","selected_inprocess_matrix_accepted":false,"source_bound_by_Task_v4_CLI":false}');raise SystemExit(1)
