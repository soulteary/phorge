#!/usr/bin/env python3
"""Conduit-only actual native receipt + Task-OFF schema provenance. No SQL."""
import argparse,hashlib,json,re,types,sys
from pathlib import Path
import source_operation_snapshot as source
import check_directory_transports_task_source_v2 as current_source
import check_directory_receipt_task_v2 as file_source
ROOT=Path(__file__).resolve().parents[2]
def need(v):
 if v is not True:raise ValueError('Task_Conduit_receipt_refused')
def captured_module(operation,root,relative):
 source.require_task_operation(operation,root);raw=operation._task_contexts[root].current(relative)
 module=types.ModuleType('captured_CURRENT_'+Path(relative).stem);module.__file__=str(root/relative);exec(compile(raw,module.__file__,'exec'),module.__dict__);return module
def source_obligations(operation,root,checker):
 source.require_task_operation(operation,root)
 # The original typed CoordinatorView checks raw current identity, then applies
 # its internally owned Task-parent projection. Do not replace that reader.
 sql=checker.SQL_obligations(root,_source_operation=operation)
 need(type(sql)is set and len(sql)==6)
 expected=checker.obligations(root,_source_operation=operation)
 need(type(expected)in(set,frozenset)and sql<=expected)
 return sql,expected
def accept(root,checkpoint,envelope_path,profile_path,profile_sha,runtime_before,runtime_after,file_binary,file_sha):
 root=Path(root);need(type(checkpoint)is str and re.fullmatch('[a-f0-9]{40}',checkpoint)is not None and checkpoint!='0'*40)
 with source.open_task_source_operation(root,expected_checkpoint=checkpoint)as operation:
  source.require_task_operation(operation,root);need(operation.code_root==ROOT and source.ROOT==ROOT)
  proof=current_source.verify_in_operation(operation,root);context=operation._task_contexts[root]
  validator=captured_module(operation,root,'support/governance/task-additive-schema-v2/validate.py')
  envelope_raw=validator.private(envelope_path);envelope=validator.decode(envelope_raw)
  projected,delta=validator.validate_envelope(envelope,profile_path,profile_sha,expected_mode='conduit-Task-OFF')
  need(delta['source_revision']==checkpoint and delta['source_root']==str(root)and delta['native_profile_mode']=='conduit-Task-OFF')
  for relative,pin in delta['source_pins'].items():need(hashlib.sha256(context.current(relative)).hexdigest()==pin)
  profile=validator.profile(profile_path,profile_sha)
  checker=captured_module(operation,root,'scripts/governance/check_recovery_conduit_producer.py')
  common=captured_module(operation,root,'scripts/governance/oauth_task_strict_body_v2.py')
  adapter=captured_module(operation,root,'scripts/governance/conduit_task_strict_body_v2.py')
  raws={n:context.current('scripts/governance/'+n+'.py')for n in adapter.PINS};model=adapter.model(raws,common,checker)
  sql,expected=source_obligations(operation,root,checker)
  file_checker=file_source.checker_for_operation(operation,root);actual_file=file_checker.file_binary_digest(file_binary,file_sha)
  need(model['read_checked'](file_binary,binary=True)==actual_file)
  before_raw=model['read_checked'](runtime_before);after_raw=model['read_checked'](runtime_after)
  before=validator.decode(before_raw);after=validator.decode(after_raw);runtime=adapter.runtime(before,after,checkpoint)
  need(before['server_uuid_sha256']==after['server_uuid_sha256']==hashlib.sha256(profile['server_uuid'].encode()).hexdigest())
  native=model['validate'](projected,root,source_revision=checkpoint,file_digest=actual_file,_source_operation=operation)
  need(native['SQL_obligations']==6 and native['checks']==len(expected)and native['all_sources_complete']is False and native['restoration_authorized']is False)
  need(validator.profile(profile_path,profile_sha)==profile)
  result={'schema':'native-Conduit-Task-source-schema-receipt-v2','role':'conduit','native_receipt_accepted':True,'original_strict_Conduit_body':native,'source_v2':proof,'Task_additive_schema':delta,'database_runtime':runtime,'source_SQL_obligations':len(sql),'raw_envelope_sha256':hashlib.sha256(envelope_raw).hexdigest(),'runtime_artifact_sha256':{'before':hashlib.sha256(before_raw).hexdigest(),'after':hashlib.sha256(after_raw).hexdigest()},'paired_file_binary_sha256':actual_file,'source_operation_exit_verified':False,'SQL_executed_by_checker':False,'all_sources_complete':False,'restoration_authorized':False,'production_enabled':False,'permission_migration':False}
 result['source_operation_exit_verified']=True;result['source_v2']['source_operation_exit_verified']=True;return result

# Only exact fixed ValueError literals from the bounded source inventory map to enums.
REJECTION_REASONS={'Task_Conduit_receipt_refused': 'conduit_receipt_fixed_01_01', 'Task_additive_schema_proof_refused': 'conduit_receipt_fixed_02_01', 'Task_Conduit_body_refused': 'conduit_receipt_fixed_03_01', 'Task_OAuth_strict_body_v2_refused': 'conduit_receipt_fixed_04_01', 'Task_native_receipt_v2_refused': 'conduit_receipt_fixed_05_01', 'Task_directory_source_v2_refused': 'conduit_receipt_fixed_06_01', 'source parent alias refused': 'conduit_receipt_fixed_07_01', 'borrowed source obligations changed': 'conduit_receipt_fixed_07_02', 'source revision required': 'conduit_receipt_fixed_07_03', 'Conduit producer obligations refused': 'conduit_receipt_fixed_07_04', 'independent binary pin required': 'conduit_receipt_fixed_07_05', 'captured output refused': 'conduit_receipt_fixed_07_06', 'bounded owned source required': 'conduit_receipt_fixed_07_07', 'source changed during capture': 'conduit_receipt_fixed_07_08', 'reviewed borrowed source changed': 'conduit_receipt_fixed_07_09', 'independent binary mismatch': 'conduit_receipt_fixed_07_10', 'nonfinite': 'conduit_receipt_fixed_07_11', 'source short read': 'conduit_receipt_fixed_07_12', 'source parent changed': 'conduit_receipt_fixed_07_13'}
def rejection_diagnostic(error):
 reason=None
 if type(error)is ValueError and type(error.args)is tuple and len(error.args)==1 and type(error.args[0])is str:
  reason=REJECTION_REASONS.get(error.args[0])
 return {'schema':'Conduit-receipt-rejection-v3','role':'conduit','phase':'receipt-check','actual_exit':1,'reason':reason,'native_receipt_accepted':False,'source_operation_exit_verified':False,'SQL_executed_by_checker':False,'runtime_authority':False,'cleanup':'unknown'}

if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--root',default=str(ROOT))
 for name in('expected-checkpoint','envelope','profile','profile-sha','runtime-before','runtime-after','file-binary','file-sha'):p.add_argument('--'+name,required=True)
 a=p.parse_args()
 try:print(json.dumps(accept(a.root,a.expected_checkpoint,a.envelope,a.profile,a.profile_sha,a.runtime_before,a.runtime_after,a.file_binary,a.file_sha),sort_keys=True))
 except Exception as error:
  print('{"native_receipt_accepted":false,"source_operation_exit_verified":false,"SQL_executed_by_checker":false}')
  try:print(json.dumps(rejection_diagnostic(error),sort_keys=True),file=sys.stderr)
  finally:raise SystemExit(1)
