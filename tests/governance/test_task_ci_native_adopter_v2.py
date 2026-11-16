"""Pure event/signature/native-consumer mutation controls, NOT CI/SQL proof."""
import copy,hashlib,importlib.util,json,os,subprocess,sys,tempfile,unittest
from pathlib import Path
from unittest import mock
sys.dont_write_bytecode=True
ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_task_ci_head_v1 as head
A='a'*40;B='b'*40
def event():return {'repository':{'full_name':'soulteary/phorge'},'pull_request':{'head':{'sha':A}}}
class CIHeadPure(unittest.TestCase):
 def test_PR_head_is_separate_from_truthful_merge_context(self):
  r=head.bind_event('pull_request',event(),A,B);self.assertEqual(r['expected_checkpoint'],A);self.assertEqual(r['github_context_sha'],B);self.assertFalse(r['GITHUB_SHA_rewritten']);self.assertFalse(r['HEAD_adopted_as_authority'])
 def test_PR_merge_sha_cannot_replace_event_head(self):
  with self.assertRaises(ValueError):head.bind_event('pull_request',event(),B,B)
 def test_missing_zero_noncanonical_or_unrelated_repository_refused(self):
  for expected in(None,'0'*40,'A'*40,'a'*39):
   with self.assertRaises(ValueError):head.bind_event('pull_request',event(),expected,B)
  e=event();e['repository']['full_name']='foreign/repository'
  with self.assertRaises(ValueError):head.bind_event('pull_request',e,A,B)
 def test_dispatch_and_push_external_bindings(self):
  e={'repository':{'full_name':'soulteary/phorge'},'after':A,'deleted':False};head.bind_event('push',e,A,A);head.bind_event('workflow_dispatch',e,A,A)
  for name,changed,expected,context in [('push',dict(e,deleted=True),A,A),('push',dict(e,after=B),A,A),('workflow_dispatch',e,A,B),('merge_group',e,A,A)]:
   with self.assertRaises(ValueError):head.bind_event(name,changed,expected,context)
 def test_duplicate_or_nonfinite_event_JSON_refuses(self):
  for raw in(b'{"x":1,"x":2}',b'{"x":NaN}'):
   with self.assertRaises(ValueError):head.decode(raw)
 def mock_git(self,actual=A,signer=head.SIGNER,bad=None):
  def run(argv,**kwargs):
   out=(actual+'\n').encode()if'rev-parse'in argv else b'';err=(b'[GNUPG:] VALIDSIG '+signer.encode()+b' synthetic\n')if'verify-commit'in argv else b''
   if bad and'verify-commit'in argv:err+=b'[GNUPG:] '+bad.encode()+b' synthetic\n'
   return subprocess.CompletedProcess(argv,0,out,err)
  return run
 def test_exact_checkout_known_signer_and_sanitized_Git_env(self):
  with tempfile.TemporaryDirectory()as t,mock.patch.dict(os.environ,{'GIT_DIR':'/foreign','GIT_WORK_TREE':'/foreign'}),mock.patch.object(head.subprocess,'run',side_effect=self.mock_git())as call:
   r=head.verify_checkout(Path(t).resolve(),A);self.assertTrue(r['Root_signer_fingerprint_matched']);self.assertEqual(len(call.call_args_list),4)
   for c in call.call_args_list:
    self.assertNotIn('GIT_DIR',c.kwargs['env']);self.assertNotIn('GIT_WORK_TREE',c.kwargs['env']);self.assertIn('gpg.program=/usr/bin/gpg',c.args[0]);self.assertEqual(c.kwargs['timeout'],10)
 def test_different_HEAD_stops_before_signature(self):
  with tempfile.TemporaryDirectory()as t,mock.patch.object(head.subprocess,'run',side_effect=self.mock_git(actual=B))as call:
   with self.assertRaises(ValueError):head.verify_checkout(Path(t).resolve(),A)
   self.assertEqual(call.call_count,1)
 def test_unreviewed_signer_and_bad_expired_revoked_status_refused(self):
  with tempfile.TemporaryDirectory()as t:
   for signer,bad in [('A'*40,None)]+[(head.SIGNER,b)for b in('BADSIG','ERRSIG','EXPSIG','EXPKEYSIG','REVKEYSIG','KEYREVOKED','SIGEXPIRED','KEYEXPIRED')]:
    with mock.patch.object(head.subprocess,'run',side_effect=self.mock_git(signer=signer,bad=bad)):
     with self.assertRaises(ValueError):head.verify_checkout(Path(t).resolve(),A)
 def test_missing_public_key_or_signature_failure_not_passed(self):
  with tempfile.TemporaryDirectory()as t,mock.patch.object(head.subprocess,'run',side_effect=subprocess.CalledProcessError(2,['git'])):
   with self.assertRaises(subprocess.CalledProcessError):head.verify_checkout(Path(t).resolve(),A)
 def test_public_trust_is_new_owned_HOME_and_never_default_keyring(self):
  key=ROOT/'support/governance/source-operation/ci-source-verifier.asc'
  self.assertEqual(hashlib.sha256(key.read_bytes()).hexdigest(),head.PUBLIC_KEY_SHA256)
  def gpg(argv,**kwargs):return subprocess.CompletedProcess(argv,0,('pub:-:2048:1:known::::::::\nfpr:::::::::'+head.SIGNER+':\n').encode()if'--with-colons'in argv else b'',b'')
  with tempfile.TemporaryDirectory()as t,mock.patch.object(head.subprocess,'run',side_effect=gpg)as call:
   home=Path(t).resolve()/'public-home';r=head.prepare_public_trust(home,key.resolve());self.assertFalse(r['private_key_used']);self.assertFalse(r['default_keyring_modified']);self.assertEqual(call.call_count,2)
   for c in call.call_args_list:
    self.assertIn('--no-autostart',c.args[0]);self.assertIn(str(home/'.gnupg'),c.args[0]);self.assertEqual(c.kwargs['env']['HOME'],str(home));self.assertNotIn('--generate-key',c.args[0])
   with self.assertRaises(ValueError):head.prepare_public_trust(home,key.resolve())
 def test_wrong_public_key_refuses_before_home_creation_or_import(self):
  with tempfile.TemporaryDirectory()as t,mock.patch.object(head.subprocess,'run')as call:
   root=Path(t).resolve();key=root/'key';key.write_bytes(b'unreviewed');key.chmod(0o600)
   with self.assertRaises(ValueError):head.prepare_public_trust(root/'home',key)
   call.assert_not_called();self.assertFalse((root/'home').exists())
class NativeBodyDelegationPure(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  # Partial-owned development uses an explicit reference root. Once installed,
  # ROOT is the actual candidate. This is only unbound synthetic body testing.
  cls.reference=Path(os.environ.get('TASK_ADOPTER_TEST_REFERENCE_ROOT',str(ROOT))).resolve()
  sys.path.insert(0,str(cls.reference/'scripts/governance'))
  def load(name,path):
   spec=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
  cls.consumer=load('Task_native_adopter_under_test',ROOT/'scripts/governance/check_directory_receipt_task_v2.py')
  cls.fixture=load('Task_original_native_body_vectors',cls.reference/'tests/governance/test_directory_transports_receipt_v1.py')
 def test_original_strict_native_body_result_remains_unbound(self):
  d=self.consumer.strict_native_body(self.fixture.gate,self.fixture.template(),self.reference,self.fixture.FILE_DIGEST)
  self.assertTrue(d['selected_inprocess_matrix_accepted']);self.assertFalse(d['source_bound_by_CLI']);self.assertFalse(d['runtime_authority'])
 def test_native_permission_authentication_and_cleanup_controls_are_not_removed(self):
  for mutate in(lambda d:d['native'].update(native_permission_axes_changed=True),lambda d:d['native'].update(actual_native_token_authentication=False),lambda d:d['cleanup'].update(readerRemoved=False)):
   data=self.fixture.template();mutate(data)
   with self.assertRaises(ValueError):self.consumer.strict_native_body(self.fixture.gate,data,self.reference,self.fixture.FILE_DIGEST)
 def test_expected_Task_schema_delta_is_not_normalized_without_genuine_new_proof(self):
  #552/1576 are source-derived expectations, not observed runtime results.
  for field in('tables','patches'):
   data=self.fixture.template()
   if field=='tables':data['source_restore']['current_table_count']=552
   else:data['source_restore']['patch_audit'].update(source_patch_count=1576,applied_patch_count=1576)
   with self.assertRaises(ValueError):self.consumer.strict_native_body(self.fixture.gate,data,self.reference,self.fixture.FILE_DIGEST)
 def test_fake_owner_cannot_compile_checker_or_create_source_bound_acceptance(self):
  with self.assertRaises(ValueError):self.consumer.checker_for_operation(object(),self.reference)
if __name__=='__main__':unittest.main()
