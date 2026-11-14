#!/usr/bin/env python3
"""Assessment-only actual native receipt + Task-OFF schema provenance. No SQL."""
import argparse,hashlib,json,re,types
from pathlib import Path
import source_operation_snapshot as source
import check_directory_transports_task_source_v2 as current_source
import check_directory_receipt_task_v2 as file_source
ROOT=Path(__file__).resolve().parents[2]
def need(v):
 if v is not True:raise ValueError('Task_Assessment_receipt_refused')
def captured_module(operation,root,relative):
 source.require_task_operation(operation,root);raw=operation._task_contexts[root].current(relative)
 module=types.ModuleType('captured_CURRENT_'+Path(relative).stem);module.__file__=str(root/relative);exec(compile(raw,module.__file__,'exec'),module.__dict__);return module
def source_obligations(operation,root,checker):
 source.require_task_operation(operation,root)
 # The original typed CoordinatorView checks raw current identity, then applies
 # its internally owned Task-parent projection. Do not replace that reader.
 sql=checker.SQL_obligations(root,_source_operation=operation)
 need(type(sql)is set and len(sql)==8)
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
  projected,delta=validator.validate_envelope(envelope,profile_path,profile_sha,expected_mode='assessment-credential-Task-OFF')
  need(delta['source_revision']==checkpoint and delta['source_root']==str(root)and delta['native_profile_mode']=='assessment-credential-Task-OFF')
  for relative,pin in delta['source_pins'].items():need(hashlib.sha256(context.current(relative)).hexdigest()==pin)
  profile=validator.profile(profile_path,profile_sha)
  checker=captured_module(operation,root,'scripts/governance/check_recovery_assessment_credentials_v2.py')
  common=captured_module(operation,root,'scripts/governance/oauth_task_strict_body_v2.py')
  adapter=captured_module(operation,root,'scripts/governance/assessment_task_strict_body_v2.py')
  raws={n:context.current('scripts/governance/'+n+'.py')for n in adapter.PINS};model=adapter.model(raws,common,checker)
  sql,expected=source_obligations(operation,root,checker)
  file_checker=file_source.checker_for_operation(operation,root);actual_file=file_checker.file_binary_digest(file_binary,file_sha)
  need(model['read_checked'](file_binary,binary=True)==actual_file)
  before_raw=model['read_checked'](runtime_before);after_raw=model['read_checked'](runtime_after)
  before=validator.decode(before_raw);after=validator.decode(after_raw);runtime=adapter.runtime(before,after,checkpoint)
  need(before['server_uuid_sha256']==after['server_uuid_sha256']==hashlib.sha256(profile['server_uuid'].encode()).hexdigest())
  native=model['validate'](projected,root,source_revision=checkpoint,file_digest=actual_file,_source_operation=operation)
  need(native['SQL_obligations']==8 and native['checks']==len(expected)and native['all_sources_complete']is False and native['restoration_authorized']is False)
  need(validator.profile(profile_path,profile_sha)==profile)
  result={'schema':'native-Assessment-Task-source-schema-receipt-v2','role':'assessment-credential','native_receipt_accepted':True,'original_strict_Assessment_body':native,'source_v2':proof,'Task_additive_schema':delta,'database_runtime':runtime,'source_SQL_obligations':len(sql),'raw_envelope_sha256':hashlib.sha256(envelope_raw).hexdigest(),'runtime_artifact_sha256':{'before':hashlib.sha256(before_raw).hexdigest(),'after':hashlib.sha256(after_raw).hexdigest()},'paired_file_binary_sha256':actual_file,'source_operation_exit_verified':False,'SQL_executed_by_checker':False,'all_sources_complete':False,'restoration_authorized':False,'production_enabled':False,'permission_migration':False}
 result['source_operation_exit_verified']=True;result['source_v2']['source_operation_exit_verified']=True;return result
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--root',default=str(ROOT))
 for name in('expected-checkpoint','envelope','profile','profile-sha','runtime-before','runtime-after','file-binary','file-sha'):p.add_argument('--'+name,required=True)
 a=p.parse_args()
 try:print(json.dumps(accept(a.root,a.expected_checkpoint,a.envelope,a.profile,a.profile_sha,a.runtime_before,a.runtime_after,a.file_binary,a.file_sha),sort_keys=True))
 except Exception:print('{"native_receipt_accepted":false,"source_operation_exit_verified":false,"SQL_executed_by_checker":false}');raise SystemExit(1)
