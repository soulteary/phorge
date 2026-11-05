"""Portable build recipe host controls, not hosted build or RR21 execution."""
import copy,importlib.util,hashlib,json,os,sys,tempfile,unittest
from unittest.mock import patch
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'tests/governance/native_rr_ci'))
import provenance as p
import build_owner as b
REV='a'*40
def info():return {'GoVersion':'go1.27.3','Path':p.MODULE,'Main':{'Path':p.MODULE,'Version':'(devel)'},'Deps':[p.DEP.copy()],'Settings':[{'Key':k,'Value':v}for k,v in {'-buildmode':'exe','-compiler':'gc','-trimpath':'true','CGO_ENABLED':'0','GOARCH':'amd64','GOOS':'linux','GOAMD64':'v1','vcs':'git','vcs.revision':REV,'vcs.time':'2026-11-05T00:00:00Z','vcs.modified':'false'}.items()]}
class Controls(unittest.TestCase):
 def test_complete_provenance_and_settings_mutations(self):
  self.assertFalse(p.verify_build_info(info(),REV,'amd64')['modified'])
  for key,value in (('vcs.modified','true'),('vcs.revision','b'*40),('CGO_ENABLED','1'),('GOARCH','arm64'),('GOOS','darwin'),('GOAMD64','v3'),('-trimpath','false')):
   d=info()
   for row in d['Settings']:
    if row['Key']==key:row['Value']=value
   with self.subTest(key=key),self.assertRaises(ValueError):p.verify_build_info(d,REV,'amd64')
 def test_dependency_replacement_duplicate_and_unknown_refused(self):
  for change in ('replace','duplicate','unknown','toolchain'):
   d=info()
   if change=='replace':d['Deps'][0]['Replace']={'Path':'/foreign'}
   elif change=='duplicate':d['Settings'].append(d['Settings'][0])
   elif change=='unknown':d['Settings'].append({'Key':'-overlay','Value':'foreign'})
   else:d['GoVersion']='go1.27.1'
   with self.subTest(change=change),self.assertRaises(ValueError):p.verify_build_info(d,REV,'amd64')
 def test_exact_same_run_not_caller_hash(self):
  env={'GITHUB_ACTIONS':'true','RUNNER_OS':'Linux','RUNNER_ARCH':'X64','GITHUB_SHA':REV,'GITHUB_RUN_ID':'12','GITHUB_RUN_ATTEMPT':'1','GITHUB_EVENT_NAME':'pull_request','GITHUB_REF':'refs/pull/12/merge'}
  self.assertEqual(REV,b.ci_identity(env,REV)['source_revision'])
  for key in env:
   d=env.copy();d[key]='unknown'
   with self.subTest(key=key),self.assertRaises(ValueError):b.ci_identity(d,REV)
 def test_real_filesystem_signed_source_and_symlink_refusal(self):
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp)
   for path in p.OWNER_FILES+p.RECIPE_FILES:
    target=root/path;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(path.encode())
   committed=lambda path:path.encode()
   self.assertEqual(8,len(p.source_bindings(root,committed)))
   target=root/p.OWNER_FILES[0];target.write_bytes(b'mutated')
   with self.assertRaises(ValueError):p.source_bindings(root,committed)
   target.unlink();target.symlink_to(root/p.OWNER_FILES[1])
   with self.assertRaises(ValueError):p.source_bindings(root,committed)
 def test_exact_module_inventory_rejects_extra_files_dirs_and_aliases(self):
  for kind in ('tracked-Go','directory','resource','file-symlink','parent-symlink'):
   with tempfile.TemporaryDirectory()as tmp:
    root=Path(tmp);module=root/'support/governance/recovery/owned-process';module.mkdir(parents=True)
    for path in p.OWNER_FILES:(root/path).write_bytes(path.encode())
    p.validate_owner_inventory(root)
    if kind=='parent-symlink':
     original=root/'support';moved=root/'original-support';original.rename(moved);original.symlink_to(moved,target_is_directory=True)
    else:
     extra=module/('extra.go'if kind=='tracked-Go'else 'extra')
     if kind=='directory':extra.mkdir()
     elif kind=='file-symlink':extra.symlink_to(module/'request.go')
     else:extra.write_bytes(b'unreviewed input')
    with self.subTest(kind=kind),self.assertRaises(ValueError):p.validate_owner_inventory(root)
 def test_actual_module_inventory_exact(self):p.validate_owner_inventory(ROOT)
 def test_real_exclusive_private_artifact_and_foreign_refusal(self):
  with tempfile.TemporaryDirectory()as tmp:
   path=Path(tmp)/'artifact';self.assertEqual(p.digest(b'bytes'),b.exclusive(path,b'bytes'));self.assertEqual(0o400,path.stat().st_mode&0o777)
   with self.assertRaises(FileExistsError):b.exclusive(path,b'other')
   self.assertEqual(b'bytes',path.read_bytes());link=Path(tmp)/'link';link.symlink_to(path)
   with self.assertRaises(FileExistsError):b.exclusive(link,b'other')
 def test_duplicate_or_nonfinite_JSON_refused(self):
  for raw in ('{"field":1,"field":2}','{"field":NaN}'):
   with self.assertRaises(ValueError):p.decode(raw)
class MainFlow(unittest.TestCase):
 def exercise(self,fault=None):
  with tempfile.TemporaryDirectory()as tmp:
   outer=Path(tmp);root=outer/'source';root.mkdir();cache=outer/'locked-modules';cache.mkdir();go=outer/'go';go.write_bytes(b'synthetic-executable-toolchain');go.chmod(0o700);output=outer/'output'
   for path in p.OWNER_FILES+p.RECIPE_FILES:
    target=root/path;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(path.encode())
   if fault=='extra-Go':(root/'support/governance/recovery/owned-process/extra.go').write_text('package main')
   env={'GITHUB_ACTIONS':'true','RUNNER_OS':'Linux','RUNNER_ARCH':'X64','GITHUB_SHA':REV,'GITHUB_RUN_ID':'12','GITHUB_RUN_ATTEMPT':'1','GITHUB_EVENT_NAME':'pull_request','GITHUB_REF':'refs/pull/12/merge','NATIVE_RR_LOCKED_GOMODCACHE':str(cache)}
   calls=[];status_count=0;verify_count=0
   def command(args,**kwargs):
    nonlocal status_count,verify_count
    calls.append(args)
    if args[0]=='git':
     if 'rev-parse'in args:return (REV+'\n').encode()
     if 'status'in args:
      status_count+=1
      return b' M changed'if fault=='dirty-before'or(fault=='dirty-after'and status_count>1)else b''
     if 'show'in args:return args[-1].split(':',1)[1].encode()
    if args[1:]==['version']:return b'go version go1.27.3 linux/amd64\n'
    if args[1:3]==['mod','verify']:
     verify_count+=1
     if fault=='module-verify'or(fault=='module-verify-after'and verify_count==2):return b'invalid modules'
     if fault=='uppercase-verify'or(fault=='uppercase-verify-after'and verify_count==2):return b'All modules verified\n'
     return b'all modules verified\n'
    if args[1]=='build':
     binary=Path(args[args.index('-o')+1]);binary.write_bytes(b'synthetic-cross-build-output')
     if fault=='extra-Go-after':(root/'support/governance/recovery/owned-process/extra.go').write_text('package main')
     if fault=='build-failed':raise RuntimeError('original-build-failure')
     if fault=='unknown-output':
      output.rename(outer/'retained-original');output.mkdir();(output/'foreign').write_text('retain')
      raise RuntimeError('original-build-failure')
     return b''
    if args[1:4]==['version','-m','-json']:
     d=info()
     if fault=='bad-build-info':d['GoVersion']='go1.27.1'
     return json.dumps(d).encode()
    raise AssertionError('unexpected fixed command')
   with patch.object(b,'ROOT',root),patch.object(b,'command',command),patch.dict(os.environ,env,clear=True):
    if fault:
     with self.assertRaises((RuntimeError,ValueError))as caught:b.main(['--go',str(go),'--output-dir',str(output)])
     if fault=='unknown-output':self.assertEqual('original-build-failure',str(caught.exception));self.assertEqual('retain',(output/'foreign').read_text());self.assertTrue(any((outer/'retained-original').glob('native-rr-build-cache-*')))
     elif output.exists():self.assertEqual([],list(output.glob('native-rr-build-cache-*')))
     self.assertFalse((output/'build-record.json').exists())
     if fault=='extra-Go':self.assertEqual('unbound owner module entry refused',str(caught.exception));self.assertFalse(output.exists());self.assertFalse(any(c[0]==str(go)for c in calls))
     if fault=='extra-Go-after':self.assertEqual('unbound owner module entry refused',str(caught.exception));self.assertTrue(any(len(c)>1 and c[1]=='build'for c in calls));self.assertEqual(2,verify_count)
     if fault in ('uppercase-verify','module-verify'):self.assertEqual(1,verify_count);self.assertFalse(any(len(c)>1 and c[1]=='build'for c in calls))
     if fault in ('uppercase-verify-after','module-verify-after'):self.assertEqual(2,verify_count);self.assertTrue(any(len(c)>1 and c[1]=='build'for c in calls))
    else:
     b.main(['--go',str(go),'--output-dir',str(output)])
     record=json.loads((output/'build-record.json').read_text());self.assertEqual(REV,record['source_revision']);self.assertEqual('pull-request-synthetic-merge',record['source_kind']);self.assertFalse(record['PR_head_measured']);self.assertFalse(record['descriptor_is_execution_authority']);self.assertFalse(record['external_trusted_anchor_established']);self.assertEqual([],list(output.glob('native-rr-build-cache-*')))
   return calls
 def test_main_positive_actual_filesystem(self):self.assertTrue(self.exercise())
 def test_main_dirty_before_and_after(self):
  for fault in ('dirty-before','dirty-after','extra-Go','extra-Go-after'):
   with self.subTest(fault=fault):self.exercise(fault)
 def test_main_bad_build_info_and_failed_module_verify(self):
  for fault in ('bad-build-info','module-verify','module-verify-after','uppercase-verify','uppercase-verify-after'):
   with self.subTest(fault=fault):self.exercise(fault)
 def test_main_original_failure_cleanup_and_unknown_retention(self):
  for fault in ('build-failed','unknown-output'):
   with self.subTest(fault=fault):self.exercise(fault)
if __name__=='__main__':unittest.main()
