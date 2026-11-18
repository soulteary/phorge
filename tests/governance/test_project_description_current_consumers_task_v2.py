"""Explicit Task source consumers; historical controls inherited unchanged."""
import json,os,subprocess,sys,unittest
from pathlib import Path
import test_project_description_current_consumers as legacy_module
from task_description_consumer_test_api_v2 import snapshot_prior, assert_recipe, auth_obligations, pure_audit
ROOT=Path(__file__).resolve().parents[2]
class KnownDirtyClassificationPure(unittest.TestCase):
 def test_exact_legacy_and_hardened_diff1_only_is_a_dirty_refusal(self):
  from task_description_consumer_test_api_v2 import known_dirty_rejection
  root=Path('/reviewed/source');checkpoint='a'*40
  prefixes=(['git','-C',str(root),'diff'],
   ['git','--no-replace-objects','-c','core.hooksPath=/dev/null','-c','core.fsmonitor=false',
    '-c','gpg.format=openpgp','-c','gpg.program=/usr/bin/gpg','-c','gpg.openpgp.program=/usr/bin/gpg',
    '-C',str(root),'diff'])
  for prefix in prefixes:
   for tail in (['--quiet',checkpoint,'--'],['--cached','--quiet',checkpoint,'--']):
    command=prefix+tail
    error=subprocess.CalledProcessError(1,command,output=b'',stderr=b'')
    self.assertTrue(known_dirty_rejection(error,root,checkpoint))
    for rc in (0,2,128,-9,True):
     with self.subTest(command=command,rc=rc):
      self.assertFalse(known_dirty_rejection(subprocess.CalledProcessError(rc,command,output=b'',stderr=b''),root,checkpoint))
    for stdout,stderr in ((b'diff',b''),(b'',b'fatal'),('',b''),(b'',''),(None,b''),(b'',None)):
     with self.subTest(stdout=stdout,stderr=stderr):
      self.assertFalse(known_dirty_rejection(subprocess.CalledProcessError(1,command,output=stdout,stderr=stderr),root,checkpoint))
    for wrongroot,wrongcp in ((root/'other',checkpoint),(Path('relative'),checkpoint),(str(root),checkpoint),
                              (root,'b'*40),(root,'HEAD'),(root,'0'*40)):
     self.assertFalse(known_dirty_rejection(error,wrongroot,wrongcp))
    for argv in (tuple(command),command+['src'],command[:-1],['/usr/bin/git']+command[1:],
                 [word.replace('/usr/bin/gpg','/tmp/foreign-gpg') for word in command]if len(prefix)>4 else command+['--exit-code']):
     self.assertFalse(known_dirty_rejection(subprocess.CalledProcessError(1,argv,output=b'',stderr=b''),root,checkpoint))
  self.assertFalse(known_dirty_rejection(ValueError('not a Git terminal'),root,checkpoint))
class TaskCurrentRecipeConsumerClosure(legacy_module.CurrentRecipeConsumerClosure):
 def observe(self):
  from task_description_consumer_test_api_v2 import CHILD
  checkpoint=os.environ.get('PHORGE_TASK_TEST_CHECKPOINT')
  if checkpoint is None:raise ValueError('external_Task_checkpoint_required')
  p=subprocess.run([sys.executable,'-B','-c',CHILD,str(self.clone),checkpoint],capture_output=True,text=True,env={**os.environ,'PYTHONDONTWRITEBYTECODE':'1'},timeout=60)
  self.assertEqual(p.returncode,0,p.stderr);return json.loads(p.stdout)
 def test_valid_actual_recipe_reaches_exact17_and_selected8_not_restore_permission(self):
   d=self.observe();self.assertEqual(d['cohort'],{'accepted':True,'count':19});self.assertTrue(d['authorization']['accepted']);self.assertEqual(d['assessment'],{'accepted':True,'count':8});self.assertEqual(d['auth_producer'],{'accepted':True,'count':20})
 def test_every_current_metadata_mutation_refuses_all_real_consumer_entries(self):
   sys.path.insert(0,str(ROOT/'scripts/governance'));import project_description_admission_delta as admission
   self.assertIn('.github/workflows/project-description-focused.yml',admission.CURRENT_METADATA)
   for name in admission.CURRENT_METADATA:
    with self.subTest(path=name):
     p=self.clone/name;old=p.read_bytes();p.write_bytes(old+b'\n# unknown-current-metadata\n')
     try:
      result=self.observe();self.assertEqual(result,{'cohort':{'accepted':False},'authorization':{'accepted':False},'assessment':{'accepted':False},'auth_producer':{'accepted':False}})
     finally:p.write_bytes(old)
 def test_auth_producer_rejects_unlisted_selected_native_source_change(self):
   name='src/applications/oauthserver/storage/PhabricatorOAuthClientAuthorization.php';p=self.clone/name;raw=p.read_bytes();p.write_bytes(raw+b'\n// unknown actual selected native source')
   try:self.assertFalse(self.observe()['auth_producer']['accepted'])
   finally:p.write_bytes(raw)
 def test_mixed_R3_native_to_main_projection_plus_unknown_metadata_refuses(self):
   sys.path.insert(0,str(ROOT/'scripts/governance'));import project_description_admission_delta as current
   import check_recovery_oauth_authorization_producer_v1 as native
   snapshot,prior=snapshot_prior(ROOT)
   names=set(snapshot)|{'.github/workflows/project-description-focused.yml'};saved={n:((self.clone/n).read_bytes(),(self.clone/n).stat().st_mode&0o7777)for n in names}
   try:
    for n in snapshot:
     p=self.clone/n
     if n in prior:p.write_bytes(prior[n])
     else:p.unlink()
    p=self.clone/'.github/workflows/project-description-focused.yml';p.write_bytes(p.read_bytes()+b'\n# unknown mixed R3 metadata')
    with self.assertRaises((ValueError,FileNotFoundError)):assert_recipe(self.clone)
    with self.assertRaises(ValueError):auth_obligations(self.clone)
   finally:
    for n,(raw,mode) in saved.items():p=self.clone/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(raw);p.chmod(mode)
 def test_pure_native_inverse_is_separate_from_current_recipe_consumer(self):
   # Immutable T3 uses the separate exact Git clean/source/48-inventory path.
   # Its C1 inverse has no metadata admission API and never grants execution.
   sys.path.insert(0,str(ROOT/'scripts/governance'));import project_description_source_delta as pure
   d=pure_audit(ROOT);self.assertTrue(d['inverse_verified']);self.assertFalse(d['full_source_admission']);self.assertFalse(d['can_execute'])
