#!/usr/bin/env python3
"""Explicit current Task source + unchanged v1 strict native receipt body.
No old gate/pin is changed. Success needs actual source-window exit, file ELF
readback and ALL original native/restore/cleanup checks. This is not SQL execution.
"""
import argparse,hashlib,json,sys,types
from pathlib import Path
import source_operation_snapshot as source
import check_directory_transports_task_source_v2 as current_source
ROOT=Path(__file__).resolve().parents[2]
def need(v):
 if v is not True:raise ValueError('Task_native_receipt_v2_refused')
def checker_for_operation(operation,root):
 source.require_operation(operation,root);source.require_task_operation(operation,root)
 need(operation.code_root==ROOT and source.ROOT==ROOT)
 relative='scripts/governance/check_directory_transports_v1.py';raw=operation._task_contexts[Path(root)].current(relative)
 checker=types.ModuleType('Task_receipt_v2_actual_CURRENT_strict_v1');checker.__file__=str(operation.code_root/relative)
 exec(compile(raw,checker.__file__,'exec'),checker.__dict__);return checker

def strict_native_body(checker,data,root,file_digest):
 # Data-only seam for the unchanged legacy validator. This result is UNBOUND;
 # only accept() can add new source proof after a genuine scope closes.
 native=checker.validate(data,root=root,file_digest=file_digest)
 need(native.get('selected_inprocess_matrix_accepted')is True and native.get('source_bound_by_CLI')is False and native.get('runtime_authority')is False)
 return native

def accept(root,expected_checkpoint,receipt_path,file_binary_path,file_binary_sha256):
 root=Path(root)
 with source.open_task_source_operation(root,expected_checkpoint=expected_checkpoint)as operation:
  # Run source v2 inside the SAME genuine window as all native body validation.
  proof=current_source.verify_in_operation(operation,root)
  checker=checker_for_operation(operation,root)
  checker.mark('file-binary');actual_binary=checker.file_binary_digest(file_binary_path,file_binary_sha256)
  checker.mark('receipt-read');data,receipt_sha256=checker.read_json(receipt_path,True)
  native=strict_native_body(checker,data,root,actual_binary)
  result={'schema':'native-directory-transports-Task-source-and-native-accepted-v3','selected_inprocess_matrix_accepted':True,'native_strict_body_result':native,'source_v2':proof,'source_bound_by_Task_v2_CLI':False,'receipt_sha256':receipt_sha256,'paired_file_binary_sha256':actual_binary,'exact_current_checkpoint':expected_checkpoint,'SQL_executed_by_this_checker':False,'new_SQL_execution_proved':False,'HTTP_browser_proved':False,'Task_body_execution_proved':False,'full_N01_N04_complete':False,'production_enabled':False}
 # A failed close never reaches an accepted receipt. Preserve the original
 # native body's source_bound_by_CLI=false instead of impersonating old CLI.
 result['source_v2']['source_operation_exit_verified']=True;result['source_bound_by_Task_v2_CLI']=True;return result
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--root',default=str(ROOT));p.add_argument('--expected-checkpoint',required=True);p.add_argument('--receipt',required=True);p.add_argument('--file-binary',required=True);p.add_argument('--file-binary-sha256',required=True);a=p.parse_args()
 try:print(json.dumps(accept(a.root,a.expected_checkpoint,a.receipt,a.file_binary,a.file_binary_sha256),sort_keys=True))
 except Exception:print('{"schema":"native-directory-transports-Task-source-and-native-accepted-v3","selected_inprocess_matrix_accepted":false,"source_bound_by_Task_v2_CLI":false}');raise SystemExit(1)
