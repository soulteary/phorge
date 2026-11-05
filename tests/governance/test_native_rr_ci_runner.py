"""Runner orchestration with fixed-command mocks and real owned files; never VM/SQL."""
import io,json,os,shutil,sys,tarfile,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'tests/governance/native_rr_ci'))
import runner as r
import provenance as p
from test_native_rr_ci_provenance import info,REV
from test_native_rr_ci_execution import service,ID
from test_native_user_rr_mdl_gate import template
class Flow(unittest.TestCase):
 def exercise(self,fault=None):
  with tempfile.TemporaryDirectory()as tmp:
   base=Path(tmp);checkout=base/'checkout';checkout.mkdir();temp=base/'runner-temp';temp.mkdir();modules=base/'modules';modules.mkdir();go=base/'go';go.write_bytes(b'synthetic-go');go.chmod(0o700)
   for path in p.OWNER_FILES+p.RECIPE_FILES:
    target=checkout/path;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(path.encode())
   (checkout/'src').mkdir();(checkout/'src/__phutil_library_map__.php').write_bytes(b'<?php // signed synthetic map')
   archive=io.BytesIO()
   with tarfile.open(fileobj=archive,mode='w')as tar:
    for file in checkout.rglob('*'):
     if file.is_file():tar.add(file,arcname=str(file.relative_to(checkout)))
   source=base/'governance-src';bindir=base/'governance-bin';binary=bindir/'native-rr-process-owner-ci';seed_dirs=[];calls=[];native_called=[]
   env={'GITHUB_ACTIONS':'true','RUNNER_OS':'Linux','RUNNER_ARCH':'X64','GITHUB_SHA':REV,'GITHUB_RUN_ID':'12','GITHUB_RUN_ATTEMPT':'1','GITHUB_EVENT_NAME':'pull_request','GITHUB_REF':'refs/pull/12/merge','GITHUB_JOB':'owner-build','GITHUB_WORKFLOW_REF':'soulteary/phorge/.github/workflows/native-rr-owner-build.yml@refs/pull/12/merge','NATIVE_RR_LOCKED_GOMODCACHE':str(modules),'RUNNER_TEMP':str(temp),'NATIVE_RR_MYSQL_SERVICE_ID':ID}
   sources=p.source_bindings(checkout,lambda path:(checkout/path).read_bytes());ids={f'Synthetic::test{i}'for i in range(266)}
   def build(args):
    out=Path(args[args.index('--output-dir')+1]);out.mkdir();raw=json.dumps(info()).encode();body=b'synthetic-owner'
    record={'schema_version':1,'source_revision':REV,'source_kind':'pull-request-synthetic-merge','PR_head_measured':False,'run_id':'12','run_attempt':'1','profile':'isolated-native-RR21-linux-amd64-v1','source_sha256':sources,'binary_sha256':p.digest(body),'build_info_sha256':p.digest(raw),'go_binary_sha256':p.digest(go.read_bytes()),'source_clean_before_after':True,'build_info_verified':True,'descriptor_is_execution_authority':False,'external_trusted_anchor_established':False,'PHP_executed':False,'SQL_executed':False}
    (out/'native-rr-process-owner').write_bytes(body);(out/'build-info.json').write_bytes(raw);(out/'build-record.json').write_text(json.dumps(record))
   def command(args,**kwargs):
    calls.append(args)
    if args[0]=='git':
     if 'rev-parse'in args:return(REV+'\n').encode()
     if 'status'in args:return b''
     if 'show'in args:return(checkout/args[-1].split(':',1)[1]).read_bytes()
     if 'archive'in args:return archive.getvalue()
    if args[0]==str(go):return json.dumps(info()).encode()
    if args[0]=='docker':return json.dumps(service()).encode()
    if args[0]=='/usr/bin/php':
     if args[1:]==['-r','echo PHP_VERSION;']:return b'8.5.12'
     if args[1]=='scripts/runtime/build.php':
      self.assertEqual('xhpast',args[2])
      parser=source/'support/runtime/src/parser/xhpast/bin/xhpast';parser.parent.mkdir(parents=True);parser.write_bytes(b'synthetic-parser');parser.chmod(0o700)
      if fault=='parser-map-drift':(source/'src/__phutil_library_map__.php').write_bytes(b'changed')
      return b''
     if args[1]=='bin/rebuild-library-map':return b''
     if args[1]=='-r'and 'profile_accepted'in args[2]:return b'{"profile_accepted":true,"production_authority":false}'
     if args[1]=='-r'and 'new mysqli'in args[2]:return b'{"version":"8.4.13","principal":"root@%","role":"NONE","proxy":null}'
     if args[1].endswith('/tests/governance/run.php'):return json.dumps({'passed':True,'runtime':'8.5.12','expected_methods':266,'results':[{'id':i,'result':'pass'}for i in ids]}).encode()
    raise AssertionError('unexpected fixed command')
   real_seed=r.create_seed
   def seed(*args):seed_dirs.append(args[0]);return real_seed(*args)
   real_write=r.private_file
   def write(path,*args,**kwargs):
    result=real_write(path,*args,**kwargs)
    if fault=='lost-install-reply'and path==binary:raise RuntimeError('original-install-reply-lost')
    return result
   def native(args,**kwargs):
    self.assertEqual('--native-user-rr-mdl-only',args[-1]);native_called.append(True);data=template();data['native']['source_revision']=REV
    if fault=='unknown-SQL-cleanup':data['cleanup']={}
    if fault=='global-clean-local-unknown':data['native']['local_cleanup'][0]['owned_SQL_sessions_ended']=False
    if fault=='source-drift':(source/'support/governance/recovery/native-writer-rr-parent.php').write_bytes(b'drift')
    raw=b'not-json'if fault=='malformed-receipt'else json.dumps(data).encode()
    code=1 if fault in ('known-case-failure','unknown-SQL-cleanup','global-clean-local-unknown')else 0
    # Re-encode a genuine failure header; cleanup evidence remains independent.
    if code==1:data['passed']=False;raw=json.dumps(data).encode()
    return type('Result',(),dict(returncode=code,stdout=raw,stderr=b''))()
   original_read=Path.read_bytes
   try:
    with patch.object(Path,'read_bytes',lambda path:b'synthetic-runtime-PHP'if path==Path('/usr/bin/php')else original_read(path)),patch.object(r,'ROOT',checkout),patch.object(r,'SOURCE',source),patch.object(r,'BINDIR',bindir),patch.object(r,'BINARY',binary),patch.object(r,'command',command),patch.object(r,'create_seed',seed),patch.object(r,'private_file',write),patch.object(r.build_owner,'main',build),patch.object(r.execution,'pure_inventory',return_value=ids),patch.object(r.execution,'strict_accept',return_value=({'passed':True,'checks':21},b'{"passed":true,"checks":21}\n')),patch.object(r.subprocess,'run',side_effect=native),patch.dict(os.environ,env,clear=True):
     if fault:
      with self.assertRaises((RuntimeError,ValueError))as caught:r.main(['--go',str(go),'--execute-sql'])
      if fault=='parser-map-drift':self.assertEqual('parser preparation changed signed application map',str(caught.exception));self.assertEqual([],native_called);self.assertFalse(source.exists())
      if fault=='lost-install-reply':self.assertEqual('original-install-reply-lost',str(caught.exception));self.assertTrue(binary.exists());self.assertEqual([],native_called)
     else:r.main(['--go',str(go),'--execute-sql'])
    public=temp/'native-rr-owner-build';check=public/'checkpoint.json'
    if not fault:
     self.assertTrue(check.exists());proof=json.loads(check.read_text());self.assertTrue(proof['portable_RR21_accepted']);self.assertEqual(21,proof['SQL_checks']);self.assertFalse(source.exists());self.assertFalse(binary.exists());self.assertEqual([],proof['cleanup_failures']);self.assertFalse(proof['TERM_KILL_fault_matrix_proved'])
    else:
     self.assertFalse(check.exists())
     if fault=='global-clean-local-unknown':self.assertEqual('actual native RR case failed',str(caught.exception));self.assertIn('SQL_lifecycle_unknown_retained',json.loads((public/'prepare-result.json').read_text())['cleanup_failures'])
     if fault in ('unknown-SQL-cleanup','global-clean-local-unknown','malformed-receipt','source-drift'):self.assertTrue(source.exists());self.assertTrue(binary.exists());self.assertTrue(any(d.exists()for d in seed_dirs))
     if fault=='known-case-failure':self.assertFalse(source.exists());self.assertFalse(binary.exists())
   finally:
    # These are solely this host test's synthetic directories, never SQL workers.
    for directory in seed_dirs:
     if directory.exists():shutil.rmtree(directory)
 def test_full_runner_known_success_with_real_owned_files(self):self.exercise()
 def test_failure_never_seals_unknown_SQL_retains_owned_objects(self):
  for fault in ('known-case-failure','unknown-SQL-cleanup','global-clean-local-unknown','malformed-receipt','source-drift','lost-install-reply','parser-map-drift'):
   with self.subTest(fault=fault):self.exercise(fault)
if __name__=='__main__':unittest.main()
