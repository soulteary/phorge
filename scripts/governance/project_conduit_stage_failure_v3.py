"""Closed failure-stage metadata only. Never raw logs, acceptance or source grant."""
import argparse,json,sys
from pathlib import Path
import project_task_bootstrap_failure_v2 as native
PHASES=frozenset(('source-preflight','probe-before','native-command','probe-after','receipt-check','failure-projection','failure-publication'))
KEYS=frozenset(('schema','role','phase','actual_exit','primary_phase','primary_exit','passed','runtime_authority','SQL_qualified','cleanup','native_or_schema_proof_claimed','source_context_details_available','error_class','source_file','source_line','original_projection_available'))
def need(value):
 if value is not True:raise ValueError('Conduit_closed_failure_metadata_refused')
def status(value):need(type(value)is int and 1<=value<=255)
def project(phase,actual_exit,primary_phase,primary_exit,original=None,bootstrap=None):
 need(type(phase)is str and phase in PHASES and type(primary_phase)is str and primary_phase in PHASES);status(actual_exit);status(primary_exit)
 value={'schema':'Conduit-failure-stage-v3','role':'conduit','phase':phase,'actual_exit':actual_exit,
  'primary_phase':primary_phase,'primary_exit':primary_exit,'passed':False,'runtime_authority':False,
  'SQL_qualified':False,'cleanup':'unknown','native_or_schema_proof_claimed':False,
  'source_context_details_available':False,'error_class':None,'source_file':None,'source_line':None,'original_projection_available':False}
 # Original PHP projector runs first, unchanged. Its available schema is only
 # presence metadata here; standalone projection is NOT a current source grant.
 if original is not None:
  try:native.original_safe(native.decode(native.private(original,16384)));value['original_projection_available']=True
  except(ValueError,TypeError,OSError,UnicodeError,RecursionError):pass
 # This private record is emitted only inside the real native SourceOperation
 # by capture_in_operation; its existing closed reader/role/exit rules remain.
 # Source details are diagnostic-only and never SQL/schema acceptance evidence.
 if phase==primary_phase=='native-command'and bootstrap is not None:
  try:
   row=native.validate_safe(native.decode(native.private(bootstrap,16384)),'conduit',primary_exit)
   if row['error_class']!='unavailable':value['error_class']=row['error_class']
   value['source_file']=row['source_file'];value['source_line']=row['source_line']
   value['source_context_details_available']=value['error_class']is not None or value['source_file']is not None
  except(ValueError,TypeError,OSError,UnicodeError,RecursionError):pass
 need(set(value)==KEYS);return value

def main():
 p=argparse.ArgumentParser();p.add_argument('--phase',choices=sorted(PHASES),required=True);p.add_argument('--actual-exit',type=int,required=True);p.add_argument('--primary-phase',choices=sorted(PHASES),required=True);p.add_argument('--primary-exit',type=int,required=True);p.add_argument('--original',type=Path);p.add_argument('--bootstrap',type=Path);a=p.parse_args()
 try:row=project(a.phase,a.actual_exit,a.primary_phase,a.primary_exit,a.original,a.bootstrap);sys.stdout.write(json.dumps(row,sort_keys=True)+'\n');return 0
 except(ValueError,TypeError,OSError,UnicodeError,RecursionError):return 1
if __name__=='__main__':raise SystemExit(main())
