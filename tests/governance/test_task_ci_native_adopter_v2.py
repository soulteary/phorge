"""Pure event/signature/native-consumer mutation controls, NOT CI/SQL proof.

Integration runbook: preserve the qualified source signature.

The source-admission gate intentionally accepts only the pinned project signer,
not every signature GitHub marks as verified. A GitHub-created merge, squash or
rebase commit has a new identity and may have another signer. Passing PR checks
on the parent does not qualify that new main commit. Do not fix this by adding a
provider key, skipping admission, rewriting `GITHUB_SHA`, selecting a signed
ancestor, or transferring a report to a different tree or commit.

Before integration, refresh the remote base, PR head, unresolved reviews and all
required checks. Review the exact final diff. The candidate must include current
main, have a clean checkout, and carry the existing project signature. Verify it
using `check_task_ci_head_v1.py` and a new public-only trust home prepared from
`support/governance/source-operation/ci-source-verifier.asc`. Use the actual PR
or push event and its external expected checkpoint; never derive the expected
checkpoint from the checkout being verified. Admission is only source identity:
all applicable core security, native-boundary, contract and artifact checks
remain separate required gates on that same checkpoint. Closed optional suites
run in the full release tier, where every unit partition and native integration
must succeed before publication; a core-only pass is never full qualification.

Integrate only through a normal protected, non-force fast-forward that preserves
the reviewed signed commit, if repository protections permit it. Never disable
branch protection or use an administrator bypass. For a server-created merge, the explicitly enabled external proof path requires
a separately project-signed tag for that exact merge commit, tree, parents and
reviewed CI head. It does not inherit a parent signature or trust GitHub signing
keys. Without the proof, admission still fails closed. If main advances first,
integrate the new base, create a new project-signed candidate, and rerun its gates
and final review. Do not reuse the earlier head's green checks.

After integration, read main again and require equality with the reviewed head
and tree. Require all main push workflows to pass on that exact commit. Preserve
failed earlier run records; a later green run qualifies its own source, not the
previous merge. Image publication is a later gate: verify paired source revisions,
immutable image versions/digests and signatures independently. Source integration
does not authorize deployment or activate optional governance/media features.
"""
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
 def test_project_signer_and_public_key_pins_remain_fixed(self):
  self.assertEqual(head.SIGNER,'CD85ED7465AFF2FFA2FFB686DBCDD8CBF440F8DE')
  self.assertEqual(head.PUBLIC_KEY_SHA256,'d3ffbebe7d73c4223e3a85e924913dcbd7ce0492649b117c8110074d4c5ffa7d')
 def test_push_merge_event_does_not_inherit_reviewed_parent_signature(self):
  # A valid provider signature is not a project signature. The exact pushed
  # merge must be checked even when its reviewed parent passed every PR gate.
  provider='968479A1AFF927E37D1A566BB5690EEEBB952194'
  with tempfile.TemporaryDirectory()as t:
   root=Path(t).resolve();path=root/'event.json'
   path.write_text(json.dumps({'repository':{'full_name':'soulteary/phorge'},'after':B,'deleted':False}));path.chmod(0o600)
   with mock.patch.dict(os.environ,{'GITHUB_SHA':B}),mock.patch.object(head.subprocess,'run',side_effect=self.mock_git(actual=B,signer=provider))as call:
    with self.assertRaises(ValueError):head.verify(root,path,'push',B,B)
    self.assertEqual(call.call_count,2)
    self.assertEqual(call.call_args.args[0][-1],B)
 def test_project_signed_fast_forward_push_qualifies_exact_event_head(self):
  with tempfile.TemporaryDirectory()as t:
   root=Path(t).resolve();path=root/'event.json'
   path.write_text(json.dumps({'repository':{'full_name':'soulteary/phorge'},'after':A,'deleted':False}));path.chmod(0o600)
   with mock.patch.dict(os.environ,{'GITHUB_SHA':A}),mock.patch.object(head.subprocess,'run',side_effect=self.mock_git()):
    result=head.verify(root,path,'push',A,A)
    self.assertTrue(result['Root_signer_fingerprint_matched']);self.assertEqual(result['expected_checkpoint'],A)
    self.assertFalse(result['GITHUB_SHA_rewritten']);self.assertFalse(result['HEAD_adopted_as_authority'])
    self.assertFalse(result['source_inventory_or_SQL_qualified']);self.assertEqual(os.environ['GITHUB_SHA'],A)
 def test_pushed_merge_cannot_fall_back_to_reviewed_parent(self):
  with tempfile.TemporaryDirectory()as t:
   root=Path(t).resolve();path=root/'event.json'
   path.write_text(json.dumps({'repository':{'full_name':'soulteary/phorge'},'after':B,'deleted':False}));path.chmod(0o600)
   with mock.patch.dict(os.environ,{'GITHUB_SHA':B}),mock.patch.object(head.subprocess,'run')as call:
    with self.assertRaises(ValueError):head.verify(root,path,'push',A,B)
    call.assert_not_called()
 def test_signed_checkout_with_staged_or_unstaged_changes_is_refused(self):
  for staged in(False,True):
   with self.subTest(staged=staged),tempfile.TemporaryDirectory()as t:
    original=self.mock_git()
    def dirty(argv,**kwargs):
     if 'diff'in argv and ('--cached'in argv)==staged:
      raise subprocess.CalledProcessError(1,argv)
     return original(argv,**kwargs)
    with mock.patch.object(head.subprocess,'run',side_effect=dirty):
     with self.assertRaises(subprocess.CalledProcessError):head.verify_checkout(Path(t).resolve(),A)
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
class IndependentMergeProofTests(unittest.TestCase):
 def proof_git(self,changes=None,wrong_signer=False):
  commit='b'*40;parent='a'*40;first='c'*40;tree='d'*40;tag='e'*40
  proof={'schema':'phorge-merge-source-proof-v1','commit':commit,'tree':tree,'parents':[first,parent],'pr_head':parent,'ci_head':parent,'pr_number':151}
  proof.update(changes or {})
  def git(*args):
   out=b'';err=b''
   if args==('rev-parse','FETCH_HEAD'):out=(tag+'\n').encode()
   elif args[0]=='verify-tag':err=('[GNUPG:] VALIDSIG '+('F'*40 if wrong_signer else head.SIGNER)+' test\n').encode()
   elif args[:2]==('cat-file','tag'):
    out=('object '+commit+'\ntype commit\ntag source-proof/'+commit+'\ntagger test\n\n'+json.dumps(proof)+'\n-----BEGIN PGP SIGNATURE-----\nsynthetic').encode()
   elif args[0]=='show':out=(tree+' '+first+' '+parent+'\n').encode()
   elif args[0]=='rev-parse':out=(tree+'\n').encode()
   return subprocess.CompletedProcess(args,0,out,err)
  return git
 def test_exact_independent_proof_accepts_without_selecting_an_ancestor(self):
  calls=[];delegate=self.proof_git()
  def git(*args):calls.append(args);return delegate(*args)
  result=head.verify_external_proof(git,'b'*40,'source-proof/'+'b'*40)
  self.assertTrue(result['independent_merge_proof_verified'])
  self.assertEqual(result['signed_proof_commit'],'b'*40)
  self.assertFalse(any(c[0]=='verify-commit' for c in calls))
 def test_commit_tree_parents_and_CI_binding_are_not_transferable(self):
  for changes in ({'commit':'f'*40},{'tree':'f'*40},{'parents':['f'*40,'a'*40]},{'pr_head':'f'*40},{'ci_head':'f'*40},{'pr_number':0},{'extra':True}):
   with self.subTest(changes=changes),self.assertRaises(ValueError):
    head.verify_external_proof(self.proof_git(changes),'b'*40,'source-proof/'+'b'*40)
 def test_other_signer_and_other_reference_are_refused(self):
  with self.assertRaises(ValueError):head.verify_external_proof(self.proof_git(wrong_signer=True),'b'*40,'source-proof/'+'b'*40)
  with self.assertRaises(ValueError):head.verify_external_proof(self.proof_git(),'b'*40,'source-proof/'+'a'*40)
 def test_different_reviewed_tree_and_nonancestor_base_are_refused(self):
  for mode in ('tree','base'):
   delegate=self.proof_git()
   def git(*args):
    if mode=='tree'and args==('rev-parse','a'*40+'^{tree}'):return subprocess.CompletedProcess(args,0,('f'*40).encode(),b'')
    if mode=='base'and args[0]=='merge-base':raise subprocess.CalledProcessError(1,args)
    return delegate(*args)
   with self.subTest(mode=mode),self.assertRaises((ValueError,subprocess.CalledProcessError)):
    head.verify_external_proof(git,'b'*40,'source-proof/'+'b'*40)

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
