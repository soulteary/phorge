"""Execute actual CI phase shells with owned files and fixed process collaborators.
No database, Docker, native fixture or binary execution is performed.
"""
from pathlib import Path
import copy,json,os,re,shlex,shutil,subprocess,sys,tempfile,textwrap,unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import project_oauth_access_failure as projector
import test_recovery_oauth_access_sql as fixture
ATOMIC=ROOT/'.github/workflows/atomic-feature-regressions.yml'
FOCUSED=ROOT/'.github/workflows/oauth-access-producer-focused.yml'
def phase(which):
 text=(ATOMIC if which=='atomic'else FOCUSED).read_text()
 if which=='atomic':text=text.split('# BEGIN isolated OAuth access-token SQL8',1)[1]
 else:text=text.split('      - name: Run only actual native OAuth',1)[1]
 return textwrap.dedent(text.split('        run: |\n',1)[1].split('      - uses:',1)[0])
def historical_atomic_source(root=ROOT,*,_operation=None):
 with _workflow_owner(_operation)as operation:
  current=operation._modules['project_description_admission_delta']
  root=Path(root).resolve()
  if root!=ROOT:raise ValueError('unknown_workflow_actor_root')
  current.assert_current_recipe(root)
  path='.github/workflows/atomic-feature-regressions.yml'
  if path not in current.CURRENT_METADATA:raise ValueError('Atomic workflow current metadata not admitted')
  raw=current.description.checked_bytes(root/path)
  previous=current._normalize_metadata(path,raw)
  expected=subprocess.check_output(['git','--no-replace-objects','-C',str(root),'show','20b138b4cd9a2cf61522613b4a8e5e21eca7341b:'+path])
  if previous!=expected:raise ValueError('unknown Atomic workflow historical stage')
  return previous.decode('utf-8')
from contextlib import contextmanager
@contextmanager
def _workflow_owner(operation=None):
 from task_inverse_test_api_v2 import owner
 import source_operation_snapshot as source
 if operation is None:
  with owner(ROOT)as current:
   source.require_task_operation(current,ROOT);yield current
 else:
  source.require_task_operation(operation,ROOT);yield operation

class OAuthCI(unittest.TestCase):
 def test_fixed_20b_Atomic_stage_still_reconstructs_exact_e804_workflow(self):
  path='.github/workflows/atomic-feature-regressions.yml'
  raw=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','20b138b4cd9a2cf61522613b4a8e5e21eca7341b:'+path]).decode()
  prior=re.sub(r'      # BEGIN isolated OAuth access-token SQL8[^\n]*\n.*?      # END isolated OAuth access-token SQL8\.\n','',raw,flags=re.S)
  self.assertEqual(prior.encode(),subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','e804125e8ad0b716cbbbba59818fb140691bf4f0:'+path]))
 def test_Atomic_removing_new_block_recovers_original_workflow_and_Conduit_workflow_unchanged(self):
   with _workflow_owner()as operation:
    s=historical_atomic_source(_operation=operation);s=re.sub(r'      # BEGIN isolated OAuth access-token SQL8[^\n]*\n.*?      # END isolated OAuth access-token SQL8\.\n','',s,flags=re.S)
    self.assertEqual(s.encode(),subprocess.check_output(['git','-C',str(ROOT),'show','e804125e8ad0b716cbbbba59818fb140691bf4f0:.github/workflows/atomic-feature-regressions.yml']))
    path='.github/workflows/conduit-producer-focused.yml';self.assertEqual(operation._task_contexts[ROOT].current(path),(ROOT/path).read_bytes());self.assertEqual(operation._task_contexts[ROOT].parent(path),subprocess.check_output(['git','-C',str(ROOT),'show','e804125e8ad0b716cbbbba59818fb140691bf4f0:'+path]))
    current=operation._task_contexts[ROOT].current(path).decode();parent=operation._task_contexts[ROOT].parent(path).decode()
    self.assertEqual(current.split('jobs:',1)[0],parent.split('jobs:',1)[0])
    for required in ['run_task_directory_native_v2.py','--role conduit','check_conduit_task_schema_receipt_v2.py','probe_conduit_task_mysql84.php before','probe_conduit_task_mysql84.php after','--expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT"','mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7']:
     self.assertIn(required,current)
    self.assertNotIn('secrets.',current);self.assertNotIn('workflow_run:',current);self.assertNotIn('docker push',current)
 def test_Atomic_current_pair_continuation_keeps_physical_File_builders_closed(self):
  from task_atomic_access_shell_test_v2 import BUILDERS,builder_parent_DATA
  with _workflow_owner()as operation:
   context=operation._task_contexts[ROOT]
   for name in BUILDERS:
    current=context.current(name);parent=context.parent(name)
    self.assertEqual(builder_parent_DATA(name,current,parent,current),parent)
    for body in [current+b'\n',bytearray(current)]:
     with self.subTest(name=name),self.assertRaises(ValueError):builder_parent_DATA(name,current,parent,body)
   pair='tests/atomic/paired-gorge.json';current=context.current(pair);parent=context.parent(pair)
   with self.assertRaises(ValueError):builder_parent_DATA(pair,parent,parent)
   for name in ['tests/atomic/build_directory_file.py','tests/atomic/build_gorge.py']:
    raw=context.current(name)
    self.assertEqual(raw,context.parent(name))
    with self.assertRaises(ValueError):builder_parent_DATA(name,raw+b'\n',context.parent(name))
   with self.assertRaises(ValueError):builder_parent_DATA('tests/atomic/unreviewed.py',current,parent)
   value=json.loads(current)
   for key,bad in [('schemaVersion',True),('revision','a'*40),('release','v1'),('scope','production')]:
    changed=dict(value);changed[key]=bad
    with self.subTest(key=key),self.assertRaises(ValueError):builder_parent_DATA(pair,json.dumps(changed).encode(),parent)
 def test_actual_all_new_Bash_blocks_syntax_and_owned_artifact_conditions(self):
  for p in [ATOMIC,FOCUSED]:
   s=p.read_text();blocks=re.findall(r'        run: \|\n((?:(?:          .*|)\n)*)',s)
   for block in blocks:
    r=subprocess.run(['bash','-n'],input=textwrap.dedent(block),capture_output=True,text=True);self.assertEqual(r.returncode,0,r.stderr)
  self.assertIn('if: success()',FOCUSED.read_text());self.assertNotIn('if: always()',FOCUSED.read_text());self.assertIn("failure() && steps.oauth-access-owner.outputs.diagnostic_owned == 'true'",FOCUSED.read_text())
 def execute(self,which,mode,*,_workflow_data=None,_atomic_data=None):
   if which=='atomic':
    from task_atomic_access_shell_test_v2 import source_contract,run_atomic_phase
    proof=source_contract(ROOT)if _atomic_data is None else _atomic_data
    self.assertTrue(proof['source_operation_exit_verified'])
    self.assertTrue(proof['strict_contract_positive_and_negatives'])
    self.assertTrue(proof['real_File_only_builder_and_pair_source_preserved'])
    self.assertTrue(proof['reviewed_current_pair_continuation'])
    self.assertEqual((proof['source_SQL_obligations'],proof['total_contract_checks'],proof['source_inputs']),(8,18,50))
    self.assertFalse(proof['native_or_source_SQL_HMAC_qualified'])
    return run_atomic_phase(ROOT,mode,workflow_current=proof['workflow_current'],revision=proof['checkpoint'])
   if which=='focused':
    from task_ci_workflow_test_api_v2 import workflow_data
    from task_current_phase_shell_test_v2 import run_phase
    proof=workflow_data(ROOT,'.github/workflows/oauth-access-producer-focused.yml')if _workflow_data is None else _workflow_data
    self.assertTrue(proof['source_operation_exit_verified'])
    return run_phase(ROOT,'oauth-access',mode,workflow_current=proof['current'],revision=proof['checkpoint'])
   raise ValueError('unknown_Atomic_or_focused_role')
 def test_actual_phase_success_strict_consumer_and_pending_atomic_publication(self):
     from task_ci_workflow_test_api_v2 import workflow_data
     proof = workflow_data(ROOT, '.github/workflows/oauth-access-producer-focused.yml')
     from task_atomic_access_shell_test_v2 import source_contract
     atomic = source_contract(ROOT)
     for which in ['atomic', 'focused']:
         d = self.execute(which, 'success', _workflow_data=proof, _atomic_data=atomic)
         self.assertTrue(d['orchestration_only'])
         self.assertFalse(d['native_or_source_SQL_HMAC_qualified'])
         self.assertEqual(d['rc'], 0, d['stderr'])
         self.assertTrue(d['accepted'])
         self.assertFalse(d['pending'] or d['owned'])
 def test_actual_failure_projector_marker_cat_and_foreign_directory_preserve_native37(self):
     from task_ci_workflow_test_api_v2 import workflow_data
     proof = workflow_data(ROOT, '.github/workflows/oauth-access-producer-focused.yml')
     from task_atomic_access_shell_test_v2 import source_contract
     atomic = source_contract(ROOT)
     for which in ['atomic', 'focused']:
         for mode in ['native-failure', 'projector-failure', 'marker-failure', 'cat-failure', 'foreign-failure', 'stderr-only']:
             d = self.execute(which, mode, _workflow_data=proof, _atomic_data=atomic)
             self.assertTrue(d['orchestration_only'])
             self.assertFalse(d['native_or_source_SQL_HMAC_qualified'])
             self.assertEqual(d['rc'], 37, (which, mode, d))
             self.assertFalse(d['accepted'])
             self.assertNotIn('PRIVATE_TOKEN_PASSWORD_SQL', d['stderr'])
             if mode in ['projector-failure', 'marker-failure', 'foreign-failure']:
                 self.assertFalse(d['owned'])
             if mode == 'foreign-failure':
                 self.assertEqual(d['foreign'], 'preserve')
             if mode in ['native-failure', 'stderr-only']:
                 self.assertEqual(d['safe']['scope'], 'native-oauth-access-producer-failure-v1')
                 self.assertTrue(d['owned'])
class SafeOAuthProjection(unittest.TestCase):
 def test_real_pinned_PHP_projection_drops_credentials_query_and_unknown_input(self):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   p=Path(tmp)/'receipt';p.touch(mode=0o600)
   p.write_text(json.dumps({'batch':'native-oauth-access-producer-owner','passed':False,'failure':{'error_type':'RuntimeException','source_file':'oauth-access-cases.php','source_line':19,'message':'PRIVATE SQL TOKEN','password':'PRIVATE SQL TOKEN'}}));v=projector.project(p);self.assertTrue(v['diagnostic_available']);self.assertNotIn('PRIVATE',json.dumps(v));self.assertEqual(v['scope'],'native-oauth-access-producer-failure-v1')
   for raw in ['{"passed":true}', '{"passed":false,"passed":false}', 'PRIVATE INVALID']:
    p.write_text(raw);self.assertFalse(projector.project(p)['diagnostic_available'])
 def test_actual_private_stderr_only_fixed_early_protocol_is_reprojected(self):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   receipt=Path(tmp)/'receipt';receipt.touch(mode=0o600);err=Path(tmp)/'native.stderr.private';err.touch(mode=0o600)
   early={'scope':'native-conduit-producer-failure-v1','passed':False,'stage':'focused-native-oauth-access-producer-owner','error_type':'RuntimeException','source_file':'oauth-access-cases.php','source_line':31,'password':'PRIVATE SQL TOKEN'}
   err.write_text('PRIVATE raw fatal text\n'+json.dumps(early)+'\n');v=projector.project(receipt,err);self.assertTrue(v['diagnostic_available']);self.assertEqual(v['source_line'],31);self.assertNotIn('PRIVATE',json.dumps(v))
   receipt.write_text(json.dumps({'passed':True}));self.assertFalse(projector.project(receipt,err)['diagnostic_available']);receipt.write_text('')
   for raw in ['PRIVATE unknown',json.dumps(dict(early,stage='focused-native-conduit-producer-owner')),'X'*65537]:
    err.write_text(raw);self.assertFalse(projector.project(receipt,err)['diagnostic_available'])
 def test_changed_PHP_or_bad_projected_scope_never_returns_untrusted_bytes(self):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   p=Path(tmp)/'receipt';p.touch(mode=0o600);p.write_text(json.dumps({'batch':'native-oauth-access-producer-owner','passed':False,'failure':{}}))
   with patch.object(projector.gate,'read_source_checked',return_value=b'<?php\necho "PRIVATE";'),patch.object(projector.subprocess,'run',side_effect=AssertionError('untrusted PHP')):self.assertFalse(projector.project(p)['diagnostic_available'])
   with patch.object(projector.subprocess,'run',return_value=__import__('types').SimpleNamespace(returncode=0,stderr='',stdout='{"scope":"PRIVATE"}')):self.assertFalse(projector.project(p)['diagnostic_available'])
if __name__=='__main__':unittest.main()
