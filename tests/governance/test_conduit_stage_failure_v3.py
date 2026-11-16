"""Closed phase metadata and pure shell failure-injection; no SourceOperation/SQL."""
import ast,importlib.util,json,os,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
import sys
sys.path.insert(0,str(ROOT/'scripts/governance'))
import project_conduit_stage_failure_v3 as phase
import project_task_bootstrap_failure_v2 as native

def trap_body():
 text=(ROOT/'.github/workflows/conduit-producer-focused.yml').read_text()
 start=text.index('          conduit_phase=source-preflight');end=text.index('          test "$(git rev-parse HEAD)',start)
 return '\n'.join(line[10:]for line in text[start:end].splitlines())
class StageFailure(unittest.TestCase):
 def private(self,root,name,value):
  p=root/name;p.write_text(json.dumps(value));p.chmod(0o600);return p
 def test_all_phases_keep_exit_and_no_authority(self):
  for name in phase.PHASES:
   for code in(1,37,255):
    row=phase.project(name,code,name,code);self.assertEqual(set(row),phase.KEYS)
    self.assertEqual((row['actual_exit'],row['primary_exit']),(code,code));self.assertEqual(row['cleanup'],'unknown')
    for field in('passed','runtime_authority','SQL_qualified','native_or_schema_proof_claimed','source_context_details_available'):self.assertIs(row[field],False)
    for field in('error_class','source_file','source_line'):self.assertIsNone(row[field])
 def test_known_native_window_details_only_for_native_phase(self):
  value=native.project_data('conduit',37,json.dumps({'batch':native.ROLES['conduit'],'passed':False,'failure':{'stage':'preflight','error_type':'TypeError','source_file':'support/runtime/bootstrap-cli.php','source_line':2,'message':'CANARY-secret-SQL-token'}}).encode(),b'',{'support/runtime/bootstrap-cli.php':10})
  with tempfile.TemporaryDirectory()as tmp:
   p=self.private(Path(tmp).resolve(),'context',value)
   row=phase.project('native-command',37,'native-command',37,bootstrap=p)
   self.assertEqual(row['error_class'],'TypeError');self.assertEqual(row['source_file'],'support/runtime/bootstrap-cli.php');self.assertNotIn('CANARY',json.dumps(row));self.assertIs(row['runtime_authority'],False)
   for name in phase.PHASES-{'native-command'}:
    row=phase.project(name,37,name,37,bootstrap=p);self.assertIsNone(row['error_class']);self.assertFalse(row['source_context_details_available'])
 def test_existing_body_readonly_no_invented_schema_proof(self):
  text=(ROOT/native.SELF).read_text();tree=ast.parse(text);f=next(x for x in tree.body if isinstance(x,ast.FunctionDef)and x.name=='capture_in_operation');body=ast.get_source_segment(text,f)
  self.assertIn('body_raw=private(body)',body);self.assertIn("value['phase']='native-body-unprojected'",body);self.assertNotIn('body.write',body)
  self.assertEqual(body.count('source.require_task_operation(operation,root)'),2)
 def test_invalid_record_or_missing_private_files_stays_null(self):
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp).resolve();p=self.private(root,'private',{'message':'CANARY-raw-trace','schema':'untrusted','runtime_authority':True})
   row=phase.project('native-command',1,'native-command',1,original=p,bootstrap=p);self.assertFalse(row['source_context_details_available']);self.assertNotIn('CANARY',json.dumps(row))
   p.chmod(0o644);row=phase.project('native-command',1,'native-command',1,bootstrap=p);self.assertIsNone(row['source_file'])
   for code in(0,True,-1,256,None):
    with self.assertRaises(ValueError):phase.project('probe-before',code,'probe-before',1)
 def test_stage_CLI_never_prints_private_invalid_input(self):
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp).resolve();p=self.private(root,'private',{'message':'CANARY-private-stderr-SQL'})
   run=subprocess.run([sys.executable,'-B',str(ROOT/'scripts/governance/project_conduit_stage_failure_v3.py'),'--phase','probe-before','--actual-exit','37','--primary-phase','probe-before','--primary-exit','37','--original',str(p),'--bootstrap',str(p)],capture_output=True,text=True,timeout=5)
   self.assertEqual(run.returncode,0);self.assertEqual(run.stderr,'');self.assertNotIn('CANARY',run.stdout);self.assertEqual(json.loads(run.stdout)['primary_exit'],37)
 def test_projection_and_publication_faults_keep_original_status(self):
  # Fake commands only model publication plumbing; never issue a source owner.
  setup=r'''
set -e
python3(){
 case "$*" in
  *project_conduit_failure.py*) [ "$FAIL" != original ] || return 19; printf '%s\n' '{"passed":false,"diagnostic_available":false}';;
  *project_conduit_stage_failure_v3.py*) [ "$FAIL" != projector ] || return 21; printf '%s\n' '{"schema":"Conduit-failure-stage-v3","passed":false}';;
  *)return 99;;
 esac
}
mkdir(){ [ "$FAIL" != mkdir ] || return 23; command mkdir "$@"; }
mv(){ [ "$FAIL" != mv ] || return 24; command mv "$@"; }
cat(){ [ "$FAIL" != cat ] || return 25; command cat "$@"; }
echo(){ [ "$FAIL" != marker ] || return 26; command echo "$@"; }
'''
  for name in('source-preflight','probe-before','native-command','probe-after','receipt-check'):
   for status in(1,37):
    for failure in('none','original','projector','mkdir','mv','cat','marker'):
     with self.subTest(phase=name,status=status,failure=failure),tempfile.TemporaryDirectory()as tmp:
      root=Path(tmp).resolve();env=dict(os.environ,RUNNER_TEMP=str(root),GITHUB_OUTPUT=str(root/'output'),FAIL=failure)
      run=subprocess.run(['bash','-c',setup+'\n'+trap_body()+f'\nconduit_phase={name}\nconduit_native_record_eligible=1\nbash -c \"exit {status}\"\n:\n'],env=env,capture_output=True,text=True,timeout=5)
      self.assertEqual(run.returncode,status);self.assertNotIn('Traceback',run.stderr)
      events=[]
      for line in run.stderr.splitlines():
       try:item=json.loads(line)
       except ValueError:continue
       if set(item)==phase.KEYS:events.append(item)
      self.assertTrue(events);self.assertTrue(all(item['primary_exit']==status and item['primary_phase']==name and item['runtime_authority']is False for item in events))
      if failure=='projector':self.assertTrue(any(item['phase']=='failure-projection'and item['actual_exit']==21 for item in events))
      if failure=='mkdir':self.assertTrue(any(item['phase']=='failure-publication'and item['actual_exit']==23 for item in events))
      self.assertFalse((root/'accepted.json').exists())
 def test_original_projector_priority_and_all_phase_boundaries(self):
  body=trap_body();self.assertLess(body.index('project_conduit_failure.py'),body.index('project_conduit_stage_failure_v3.py'))
  workflow=(ROOT/'.github/workflows/conduit-producer-focused.yml').read_text()
  for value in('source-preflight','probe-before','native-command','probe-after','receipt-check'):self.assertIn('conduit_phase='+value,workflow)
  self.assertIn('exit "$primary_exit"',body);self.assertIn("trap 'conduit_failure \"$?\"' ERR",body)
  self.assertNotIn('stderr.private" >&2',body);self.assertNotIn('accepted',body)
  self.assertIn('check_conduit_task_schema_receipt_v2.py',workflow)
 def test_original_standalone_projection_does_not_invent_source_context(self):
  original={'scope':'native-conduit-producer-failure-v1','passed':False,'diagnostic_available':True,'stage':'preflight','attempt_stage':'preflight','error_type':'RuntimeException','source_file':'fixture.php','source_line':2,'query_code':None,'child_available':False,'child_status':'other','child_identity_started':False,'child_result_available':False}
  with tempfile.TemporaryDirectory()as tmp:
   p=self.private(Path(tmp).resolve(),'original',original)
   row=phase.project('native-command',37,'native-command',37,original=p)
   self.assertTrue(row['original_projection_available']);self.assertFalse(row['source_context_details_available']);self.assertIsNone(row['source_file']);self.assertIsNone(row['error_class'])
 def test_context_requires_entered_fresh_native_directory(self):
  text=(ROOT/'.github/workflows/conduit-producer-focused.yml').read_text();body=trap_body()
  self.assertIn('conduit_native_record_eligible=0',body)
  self.assertIn('[ \"$conduit_native_record_eligible\" = 1 ]',body)
  check='test ! -e \"$RUNNER_TEMP/task-conduit-v2-private\"'
  self.assertLess(text.index(check),text.index('          conduit_native_record_eligible=1'))
  self.assertIn('context_args=()',body)

if __name__=='__main__':unittest.main()
