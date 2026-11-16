"""Three genuine protocol negatives. No issuer/reader/validator replacement."""
import ast,json,os,subprocess,sys,tempfile,unittest
from contextlib import contextmanager
from pathlib import Path
import assessment_legacy_DATA_model_v3 as model
import assessment_task_test_api_v2 as task
import source_operation_snapshot as source
import test_source_snapshot_assessment_task_v2 as assessment_tests
ROOT=Path(__file__).resolve().parents[2]
CASE='support/governance/recovery/assessment-credential-cases.php'
@contextmanager
def profile_boundary(callback):
 previous=sys.getprofile()
 def observe(frame,event,value):
  if previous is not None:previous(frame,event,value)
  callback(frame,event,value)
 sys.setprofile(observe)
 try:yield
 finally:sys.setprofile(previous)
def prepared(root):
 assessment_tests.AssessmentTaskTopLevelV2('test_minimal_fixture_preserves_original_strict_reader_and_corruption_refusal')._minimal(root)
def is_actual_model_return(frame,event,root):
 return event=='return'and frame.f_code.co_name=='SQL_obligations'and frame.f_globals.get('__name__')=='unadmitted_legacy_DATA_check_recovery_assessment_credentials_v2'and frame.f_locals.get('root')==root
def traceback_has(error,function,line):
 trace=error.__traceback__
 while trace is not None:
  if trace.tb_frame.f_code is function.__code__ and trace.tb_lineno==line:return True
  trace=trace.tb_next
 return False
def model_line(fragment):
 lines=Path(model.__file__).read_text().splitlines();found=[i+1 for i,line in enumerate(lines)if line.strip()==fragment]
 if len(found)!=1:raise AssertionError('unique actual protocol boundary required')
 return found[0]
def origin_child(root):
 prepared(root);case=root/CASE;fired=[False];held=[]
 original_profile=sys.getprofile()
 def inject(frame,event,value):
  if fired[0]or event!='call'or frame.f_code is not model.capture_E.__code__ or frame.f_locals.get('root')!=root:return
  if frame.f_back is None or frame.f_back.f_code is not model.legacy_DATA_labels.__code__:return
  # Actual S was already entered and captured. Only ISOLATED CLONE S changes.
  case.unlink();os.link(ROOT/'README.md',case);fired[0]=True
 error=None
 try:
  with profile_boundary(inject):
   try:model.legacy_DATA_labels(root)
   except ValueError as caught:error=caught
 finally:
  # Owned E leaf only, after genuine owner exit; preserve source-drift evidence.
  if fired[0]and case.exists():case.unlink()
 if error is None or not fired[0]:raise AssertionError('actual shared inode refusal not reached')
 if not traceback_has(error,model.legacy_DATA_labels,model_line('need(not S_files&E_files)')):raise AssertionError('wrong refusal boundary')
 if 'source_operation_exit_refused'not in getattr(error,'__notes__',[]):raise AssertionError('isolated S metadata drift evidence missing')
 if source._ACTIVE.get()is not None or sys.getprofile()is not original_profile:raise AssertionError('owner/profile not restored')
 return {'schema':'Assessment-DATA-shared-inode-negative-v3','actual_origin_guard_refused':True,'actual_S_exit_drift_retained':True,'root_worktree_source_modified':False,'runtime_authority':False}
class ActualDATATerminalBoundariesV3(unittest.TestCase):
 def test_E_drift_after_actual_success_before_terminal_refuses(self):
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp).resolve();prepared(root);case=root/CASE;fired=[False]
   def inject(frame,event,value):
    if not fired[0]and is_actual_model_return(frame,event,root)and type(value)is set and len(value)==8:
     case.write_bytes(case.read_bytes()+b'\nchanged-after-real-success');fired[0]=True
   error=None
   with profile_boundary(inject):
    try:model.legacy_DATA_labels(root)
    except ValueError as caught:error=caught
   self.assertTrue(fired[0]);self.assertIsNotNone(error)
   self.assertTrue(traceback_has(error,model.legacy_DATA_labels,model_line('need(_identity(fixture)==identity and capture_E(fixture)==E_before)')))
   self.assertIsNone(source._ACTIVE.get())
 def test_E_drift_after_actual_validator_failure_retains_terminal_refusal(self):
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp).resolve();prepared(root);case=root/CASE;case.write_bytes(case.read_bytes()+b'\ninitial-invalid-case');fired=[False]
   def inject(frame,event,value):
    if not fired[0]and is_actual_model_return(frame,event,root)and value is None:
     case.write_bytes(case.read_bytes()+b'\nchanged-after-real-rejection');fired[0]=True
   error=None
   with profile_boundary(inject):
    try:model.legacy_DATA_labels(root)
    except ValueError as caught:error=caught
   self.assertTrue(fired[0]);self.assertIsNotNone(error);self.assertIn('E_terminal_refused',getattr(error,'__notes__',[]))
   self.assertIsNone(source._ACTIVE.get())
 def test_shared_S_E_inode_reaches_origin_guard_in_isolated_current_clone(self):
  with tempfile.TemporaryDirectory()as tmp:
   parent=Path(tmp).resolve();clone=parent/'signed-current-S';data=parent/'legacy-E';data.mkdir(mode=0o700)
   subprocess.run(['/usr/bin/git','--no-replace-objects','clone','--no-hardlinks','--no-checkout',str(ROOT),str(clone)],capture_output=True,check=True,timeout=120)
   subprocess.run(['/usr/bin/git','--no-replace-objects','-C',str(clone),'checkout','--detach',task.checkpoint()],capture_output=True,check=True,timeout=120)
   command=[sys.executable,'-B',str(clone/'tests/governance/test_assessment_legacy_DATA_boundaries_v3.py'),'--isolated-origin-child',str(data)]
   result=subprocess.run(command,capture_output=True,text=True,timeout=180)
   self.assertEqual(result.returncode,0,result.stderr);receipt=json.loads(result.stdout)
   self.assertEqual(receipt,{'schema':'Assessment-DATA-shared-inode-negative-v3','actual_origin_guard_refused':True,'actual_S_exit_drift_retained':True,'root_worktree_source_modified':False,'runtime_authority':False})
if __name__=='__main__':
 if len(sys.argv)==3 and sys.argv[1]=='--isolated-origin-child':print(json.dumps(origin_child(Path(sys.argv[2])),sort_keys=True))
 else:unittest.main()
