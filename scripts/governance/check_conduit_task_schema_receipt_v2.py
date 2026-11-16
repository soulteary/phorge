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
def accept(root,checkpoint,envelope_path,profile_path,profile_sha,runtime_before,runtime_after,file_binary,file_sha,*,_diagnostic_codes=None):
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
  raws={n:context.current('scripts/governance/'+n+'.py')for n in adapter.PINS};model=adapter.model(raws,common,checker);_diagnostic_codes.update(model['diagnostic_codes'])if type(_diagnostic_codes)is dict else None
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


# Line labels identify actual unchanged accept() call sites, not source authority.
ACCEPT_FAILURE_STAGES={24: 'input-shape', 25: 'source-window-boundary', 26: 'source-binding', 27: 'source-freshness', 28: 'schema-source', 29: 'private-input', 30: 'schema-envelope', 31: 'schema-source-binding', 32: 'schema-source-pins', 33: 'schema-profile', 34: 'native-checker-source', 35: 'native-common-source', 36: 'native-adapter-source', 37: 'native-model', 38: 'source-obligations', 39: 'paired-binary', 40: 'paired-binary', 41: 'runtime-input', 42: 'runtime-binding', 43: 'runtime-identity', 44: 'native-body', 45: 'native-obligations', 46: 'profile-terminal', 47: 'result-build', 48: 'source-exit-complete'}
DIAGNOSTIC_MAIN_STAGES=frozenset(('accept-call','output'))
EXCEPTION_TYPES={ValueError:'ValueError',TypeError:'TypeError',KeyError:'KeyError',OSError:'OSError',FileNotFoundError:'FileNotFoundError',PermissionError:'PermissionError',RuntimeError:'RuntimeError',AssertionError:'AssertionError',UnicodeDecodeError:'UnicodeDecodeError',json.JSONDecodeError:'JSONDecodeError'}
def rejection_stage(error,main_stage):
 stage=main_stage if type(main_stage)is str and main_stage in DIAGNOSTIC_MAIN_STAGES else None
 tb=BaseException.__traceback__.__get__(error);seen=set()
 for _ in range(128):
  if tb is None:return stage
  identity=id(tb)
  if identity in seen:return None
  seen.add(identity)
  if tb.tb_frame.f_code is accept.__code__:stage=ACCEPT_FAILURE_STAGES.get(tb.tb_lineno)
  tb=tb.tb_next
 return None

# Only exact fixed ValueError literals from the bounded source inventory map to enums.
REJECTION_REASONS={'Task_Conduit_receipt_refused': 'conduit_receipt_fixed_01_01', 'Task_additive_schema_proof_refused': 'conduit_receipt_fixed_02_01', 'Task_Conduit_body_refused': 'conduit_receipt_fixed_03_01', 'Task_OAuth_strict_body_v2_refused': 'conduit_receipt_fixed_04_01', 'Task_native_receipt_v2_refused': 'conduit_receipt_fixed_05_01', 'Task_directory_source_v2_refused': 'conduit_receipt_fixed_06_01', 'source parent alias refused': 'conduit_receipt_fixed_07_01', 'borrowed source obligations changed': 'conduit_receipt_fixed_07_02', 'source revision required': 'conduit_receipt_fixed_07_03', 'Conduit producer obligations refused': 'conduit_receipt_fixed_07_04', 'independent binary pin required': 'conduit_receipt_fixed_07_05', 'captured output refused': 'conduit_receipt_fixed_07_06', 'bounded owned source required': 'conduit_receipt_fixed_07_07', 'source changed during capture': 'conduit_receipt_fixed_07_08', 'reviewed borrowed source changed': 'conduit_receipt_fixed_07_09', 'independent binary mismatch': 'conduit_receipt_fixed_07_10', 'nonfinite': 'conduit_receipt_fixed_07_11', 'source short read': 'conduit_receipt_fixed_07_12', 'source parent changed': 'conduit_receipt_fixed_07_13'}
BODY_SITE_ENUMS=frozenset('conduit_body_site_%02d'%n for n in range(1,34))
REJECTION_REASONS.update({'Conduit producer evidence refused': 'conduit_receipt_fixed_08_01', 'Conduit producer evidence shape refused': 'conduit_receipt_fixed_08_02', 'File lifecycle evidence refused': 'conduit_receipt_fixed_08_03', 'untyped evidence': 'conduit_receipt_fixed_08_04', 'unknown or missing evidence fields': 'conduit_receipt_fixed_08_05', 'evidence scope or result refused': 'conduit_receipt_fixed_08_06', 'restore fields refused': 'conduit_receipt_fixed_08_07', 'patch audit fields refused': 'conduit_receipt_fixed_08_08'})
def rejection_body_site(error,codes=None):
 if type(codes)is not dict:return None
 tb=BaseException.__traceback__.__get__(error);site=None;seen=set()
 for _ in range(128):
  if tb is None:return site
  if id(tb)in seen:return None
  seen.add(id(tb));code=tb.tb_frame.f_code;entry=codes.get(id(code))
  if type(entry)is tuple and len(entry)==2 and entry[0]is code and type(entry[1])is dict:
   lines=entry[1]
   candidate=lines.get(tb.tb_lineno)
   if type(candidate)is str and candidate in BODY_SITE_ENUMS:site=candidate
  tb=tb.tb_next
 return None

def rejection_diagnostic(error,main_stage=None,body_codes=None):
 reason=None
 if type(error)is ValueError and type(error.args)is tuple and len(error.args)==1 and type(error.args[0])is str:
  reason=REJECTION_REASONS.get(error.args[0])
 return {'schema':'Conduit-receipt-rejection-v3','role':'conduit','phase':'receipt-check','actual_exit':1,'reason':reason,'body_site':rejection_body_site(error,body_codes),'stage':rejection_stage(error,main_stage),'error_type':EXCEPTION_TYPES.get(type(error)),'native_receipt_accepted':False,'source_operation_exit_verified':False,'SQL_executed_by_checker':False,'runtime_authority':False,'cleanup':'unknown'}

if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--root',default=str(ROOT))
 for name in('expected-checkpoint','envelope','profile','profile-sha','runtime-before','runtime-after','file-binary','file-sha'):p.add_argument('--'+name,required=True)
 a=p.parse_args()
 diagnostic_main_stage='accept-call';diagnostic_codes={}
 try:
  accepted=accept(a.root,a.expected_checkpoint,a.envelope,a.profile,a.profile_sha,a.runtime_before,a.runtime_after,a.file_binary,a.file_sha,_diagnostic_codes=diagnostic_codes)
  diagnostic_main_stage='output'
  print(json.dumps(accepted,sort_keys=True))
 except Exception as error:
  print('{"native_receipt_accepted":false,"source_operation_exit_verified":false,"SQL_executed_by_checker":false}')
  try:print(json.dumps(rejection_diagnostic(error,diagnostic_main_stage,diagnostic_codes),sort_keys=True),file=sys.stderr)
  finally:raise SystemExit(1)
