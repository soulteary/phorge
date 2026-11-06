"""Synthetic gate acceptance/rejection controls only; not native SQL evidence."""
import copy, importlib.util, json, re, subprocess, sys, tempfile, unittest, hashlib, os
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_recovery_partial_composition as gate
spec=importlib.util.spec_from_file_location('partial_native_template',ROOT/'tests/governance/test_native_organization.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
def template():
 d=module.template();checks=sorted(gate.BOOTSTRAP|gate.PREPARATION|gate.SQL|gate.CLEANUP)
 d.update(batch='native-recovery-partial-composition',scope='owned-native-partial-composition-no-permit',checks=checks,check_count=len(checks),native=gate.summary('a'*40),file_service_cleanup={'kind':'file','binary_sha256':'b'*64,'process_stopped':True,'private_storage_removed':True},successful_owned_file_service_startups=1)
 d['worker_cleanup']={k:0 for k in ('private_directories_removed','owned_processes_stopped','cleanup_failures','private_directories_remaining','live_processes_after_cleanup','workers_registered')}
 return d
class PartialGate(unittest.TestCase):
 def test_every_obligation_and_authority_negative(self):
  d=template();self.assertTrue(gate.validate(d,source_revision='a'*40,file_digest='b'*64)['passed'])
  for label in d['checks']:
   bad=copy.deepcopy(d);bad['checks'].remove(label)
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
  for k,v in [('complete',True),('all_sources_complete',True),('restoration_authorized',True),('Task_File_owned_RR_combined',True),('assessment_schema',2),('actual_current_writer_adapters',[])]:
   bad=copy.deepcopy(d);bad['native'][k]=v
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
 def test_duplicate_unrun_wrong_revision_and_independent_binary_mismatch_refused(self):
  with tempfile.TemporaryDirectory() as tmp:
   directory=Path(tmp).resolve();p=directory/'receipt.json';binary=directory/'file-binary';body=b'synthetic paired file binary; never executed';binary.write_bytes(body);binary.chmod(0o700);pin=hashlib.sha256(body).hexdigest()
   data=template();data['file_service_cleanup']['binary_sha256']=pin;raw=json.dumps(data)
   cmd=[sys.executable,'-B',str(ROOT/'scripts/governance/check_recovery_partial_composition.py'),'--receipt',str(p),'--source-revision','a'*40,'--file-binary',str(binary),'--file-binary-sha256',pin]
   for value,ok in [(raw,True),(raw.replace('"complete": false','"complete": true,"complete": false'),False),(raw.replace('"passed": true','"passed": false'),False),(raw.replace('a'*40,'c'*40),False),(raw.replace(pin,'d'*64),False)]:
    p.write_text(value);p.chmod(0o600);r=subprocess.run(cmd,capture_output=True,text=True);self.assertEqual(r.returncode==0,ok,r.stderr)
   p.write_text(raw);binary.write_bytes(body+b'changed');self.assertNotEqual(subprocess.run(cmd,capture_output=True).returncode,0)
 def test_real_file_required_and_cleanup_boolean_binary_identity_negatives(self):
  d=template();self.assertEqual(gate.validate(d,source_revision='a'*40,file_digest='b'*64)['checks'],31)
  for key,value in [('successful_owned_file_service_startups',0),('successful_owned_file_service_startups',True),('file_service_cleanup',None),('check_count',30)]:
   bad=copy.deepcopy(d);bad[key]=value
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
  for key,value in [('kind','taskqueue'),('process_stopped',False),('process_stopped',1),('private_storage_removed',False),('private_storage_removed',1),('binary_sha256','c'*64)]:
   bad=copy.deepcopy(d);bad['file_service_cleanup'][key]=value
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
  old=copy.deepcopy(d);old['checks'].remove('completion-real-gorge-file-ready-loopback');old['check_count']=30
  with self.assertRaises(ValueError):gate.validate(old,source_revision='a'*40,file_digest='b'*64)
 def test_actual_binary_and_receipt_FD_reject_FIFO_symlink_hardlink_mode(self):
  with tempfile.TemporaryDirectory() as tmp:
   root=Path(tmp).resolve();binary=root/'binary';binary.write_bytes(b'synthetic-only');binary.chmod(0o700)
   self.assertEqual(gate.read_checked(binary,binary=True),hashlib.sha256(b'synthetic-only').hexdigest())
   alias=root/'alias';alias.symlink_to(binary)
   with self.assertRaises(OSError):gate.read_checked(alias,binary=True)
   hard=root/'hard';os.link(binary,hard)
   with self.assertRaises(ValueError):gate.read_checked(binary,binary=True)
   hard.unlink();binary.chmod(0o600)
   with self.assertRaises(ValueError):gate.read_checked(binary,binary=True)
   fifo=root/'FIFO';os.mkfifo(fifo,0o600)
   with self.assertRaises(ValueError):gate.read_checked(fifo,binary=True)
   with self.assertRaises(ValueError):gate.read_checked(fifo)
 def test_gate_obligations_match_actual_fixture_cases(self):
  labels=set()
  for name in ('restore-review-cases.php','partial-composition-cases.php'):
   labels.update(re.findall(r"'(recovery-(?:restore|partial)-[a-z0-9-]+)'",(ROOT/'support/governance/recovery'/name).read_text()))
  self.assertEqual(labels,gate.SQL)
 def test_actual_missing_profile_consumer_requires_real_file_producer_and_propagates_error(self):
  code=r'''class PhabricatorUserCacheType{function getViewer(){return new stdClass();}}class PhabricatorUser{function getPHID(){return'synthetic';}function getProfileImagePHID(){return null;}function getDefaultProfileImagePHID(){return null;}function getDefaultProfileImageVersion(){return null;}}class PhabricatorFilesComposeAvatarBuiltinFile{const VERSION='v1';function updateUser($user){$GLOBALS['called']++;throw$GLOBALS['producer_error'];}}require$argv[1].'/src/applications/people/cache/PhabricatorUserProfileImageCacheType.php';$GLOBALS['called']=0;$GLOBALS['producer_error']=new RuntimeException('synthetic-storage-unavailable');$original=false;try{(new PhabricatorUserProfileImageCacheType())->newValueForUsers(PhabricatorUserProfileImageCacheType::KEY_URI,[new PhabricatorUser()]);}catch(RuntimeException$e){$original=$e===$GLOBALS['producer_error'];}echo json_encode(['producer_calls'=>$GLOBALS['called'],'original_error'=>$original,'SQL_executed'=>false]);'''
  r=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True,timeout=10);self.assertEqual(r.returncode,0,r.stderr);self.assertEqual(json.loads(r.stdout),{'producer_calls':1,'original_error':True,'SQL_executed':False})
 def test_actual_entry_registers_owned_file_before_every_partial_editor_and_keeps_cleanup(self):
  driver=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text();branch=driver.split("}elseif($phase==='recovery-partial-composition'){",1)[1].split('}elseif(',1)[0]
  self.assertLess(branch.index("governance_completion_start_gorge($fixture,'file',$check)"),branch.index('governance_recovery_partial_composition_focused($fixture,$check)'))
  self.assertIn("'gorge.file.uri'=>$file_service['uri']",branch);self.assertIn("'gorge.file.token'=>$file_service['token']",branch)
  self.assertIn('$file_service_cleanup=governance_completion_stop_gorge($file_service)',driver)
  self.assertEqual(gate.PREPARATION,{'completion-real-gorge-file-ready-loopback'});self.assertEqual(len(gate.SQL),21)
  fixture=(ROOT/'support/governance/recovery/restore-review-cases.php').read_text()
  for forbidden in ('needProfileImage(false)','setDefaultProfileImagePHID','setProfileImagePHID','fakeSession','fakeAvatar'):
   self.assertNotIn(forbidden,fixture)
 def test_binary_same_FD_chmod_hardlink_and_replacement_refused(self):
  from unittest.mock import patch
  for fault in ('chmod','hardlink','replace'):
   with self.subTest(fault=fault),tempfile.TemporaryDirectory() as tmp:
    root=Path(tmp).resolve();binary=root/'binary';binary.write_bytes(b'synthetic-actual-binary');binary.chmod(0o700);read=os.read;called=[]
    def mutate(fd,count):
     data=read(fd,count)
     if not called:
      called.append(True)
      if fault=='chmod':os.fchmod(fd,0o644)
      elif fault=='hardlink':os.link(binary,root/'hard')
      else:binary.unlink();binary.write_bytes(b'synthetic-actual-binary');binary.chmod(0o700)
     return data
    with patch.object(gate.os,'read',mutate):
     with self.assertRaises(ValueError):gate.read_checked(binary,binary=True)
