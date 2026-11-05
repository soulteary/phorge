"""Synthetic Space source gate controls, never SQL or native policy proof."""
import copy, importlib.util,json,subprocess,sys,tempfile,unittest,re,os,stat
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_recovery_spaces_source as gate
spec=importlib.util.spec_from_file_location('project_native_template',ROOT/'tests/governance/test_native_organization.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
def stable_stamp(path):
 st=path.lstat()
 # Reading an owned path may update atime; it is not evidence of a write.
 return (st.st_dev,st.st_ino,st.st_mode,st.st_nlink,st.st_uid,st.st_gid,st.st_size,st.st_mtime_ns,st.st_ctime_ns)
def template():
 d=module.template();d.update(batch='native-spaces-source-facts',scope='owned-native-spaces-source-facts-no-lease',
  checks=sorted(gate.BOOTSTRAP|gate.SQL|gate.CLEANUP),check_count=20,native=gate.summary('a'*40),
  file_service_cleanup=None,successful_owned_file_service_startups=0)
 d['worker_cleanup']={k:0 for k in ('private_directories_removed','owned_processes_stopped','cleanup_failures','private_directories_remaining','live_processes_after_cleanup','workers_registered')}
 return d
class SpacesSourceGate(unittest.TestCase):
 def test_control_then_every_obligation_and_authority_negative(self):
  d=template();self.assertTrue(gate.validate(d,source_revision='a'*40)['passed'])
  for name in d['checks']:
   bad=copy.deepcopy(d);bad['checks'].remove(name)
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40)
  for key,value in [('php_version','unsupported'),('raw_secret','SENTINEL'),('check_count',True),('terminal_failure_cleanup_rollbacks',False)]:
   bad=copy.deepcopy(d);bad[key]=value
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40)
  for key in ('complete','all_sources_complete','restoration_authorized','absence_or_producer_lease_proved','query_cache_is_policy_attestation','history_complete'):
   bad=copy.deepcopy(d);bad['native'][key]=True
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40)
  for key in ('native_noone_selfexclusion_rejected_without_persistence','native_nondefault_creation_preserves_existing_default'):
   bad=copy.deepcopy(d);bad['native'][key]=False
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40)
  bad=copy.deepcopy(d);bad['worker_cleanup']['workers_registered']=False
  with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40)
 def test_actual_cli_positive_duplicate_and_nonfinite(self):
  with tempfile.TemporaryDirectory()as tmp:
   p=Path(tmp)/'receipt.json';raw=json.dumps(template());p.write_text(raw)
   cmd=[sys.executable,'-B',str(ROOT/'scripts/governance/check_recovery_spaces_source.py'),'--receipt',str(p),'--source-revision','a'*40]
   result=subprocess.run(cmd,text=True,capture_output=True);self.assertEqual(0,result.returncode,result.stderr);self.assertEqual('',result.stderr)
   for mutant in [raw.replace('"complete": false','"complete": true,"complete": false'),raw.replace('"check_count": 20','"check_count": NaN')]:
    p.write_text(mutant);self.assertNotEqual(0,subprocess.run(cmd,text=True,capture_output=True).returncode)
 def test_real_entry_dependencies_and_obligation_set(self):
  source=(ROOT/'support/governance/recovery/spaces-source-focused.php').read_text()
  ids=set(re.findall(r"'(recovery-spaces-[A-Za-z-]+)'",source));self.assertEqual(gate.SQL,ids)
  for literal in ('new PhabricatorSpacesNamespaceEditor()','PhabricatorSpacesNamespaceArchiveTransaction::TRANSACTIONTYPE',
                  'PhabricatorSpacesNamespaceQuery::getViewerActiveSpaces', 'PhorgeRecoverySpacesSourceFacts::capture'):
   self.assertIn(literal,source)
  driver=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text()
  self.assertIn("'--spaces-source-facts'=>'spaces-source-facts'",driver)
  self.assertIn("$native=governance_recovery_spaces_source_focused($fixture,$check)",driver)
 def test_actual_fixture_scope_outer_refusal_and_primary_preservation(self):
  code=r'''require $argv[1].'/support/runtime/src/error/PhutilAggregateException.php';require $argv[1].'/support/governance/recovery/spaces-source-focused.php';
class SpaceSyntheticWriter {public $inside=false;public $read=false;public $write=false;public $opens=0;public $kills=0;public $failBegin=false;public $failCleanup=false;function isInsideTransaction(){return$this->inside;}function isReadLocking(){return$this->read;}function isWriteLocking(){return$this->write;}function openTransaction(){$this->opens++;$this->inside=true;if($this->failBegin){throw new RuntimeException('synthetic_begin');}}function killTransaction(){$this->kills++;if($this->failCleanup){throw new RuntimeException('synthetic_cleanup');}$this->inside=false;}}
$out=[];foreach(['inside','read','write']as$state){$w=new SpaceSyntheticWriter();$w->$state=true;try{governance_spaces_fixture_scope($w,fn()=>throw new RuntimeException('must_not_run'));exit(7);}catch(RuntimeException$e){if($e->getMessage()!=='recovery_spaces_fixture_outer_state_refused'){exit(8);}}$out[]=[$w->opens,$w->kills,$w->$state];}
foreach(['begin','body','cleanup']as$fault){$w=new SpaceSyntheticWriter();$w->failBegin=$fault==='begin';$w->failCleanup=$fault==='cleanup';$original=new RuntimeException('synthetic_body');try{governance_spaces_fixture_scope($w,fn()=>throw$original);exit(9);}catch(PhutilAggregateException$e){if($fault!=='cleanup'||$e->getExceptions()['primary']!==$original){exit(10);}}catch(RuntimeException$e){if($fault==='body'&&$e!==$original){exit(11);}}$out[]=[$w->opens,$w->kills];}echo json_encode($out);'''
  p=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True);self.assertEqual(0,p.returncode,p.stderr);self.assertEqual('',p.stderr);self.assertEqual([[0,0,True]]*3+[[1,1]]*3,json.loads(p.stdout))


 def test_actual_native_selfexclusion_validation_match_is_exact(self):
  pre=(ROOT/'support/governance/recovery/tests/spaces-editor-diagnostic-controls.php').read_text().split('function expect_diag')[0].replace('<?php','',1)
  code=pre+r"""require $root.'/support/governance/recovery/spaces-source-focused.php';function err_space($type,$value,$present=true){$x=$present?(new PhabricatorSpacesNamespaceTransaction())->setTransactionType($type)->setNewValue($value):null;return new PhabricatorApplicationTransactionValidationException([new PhabricatorApplicationTransactionValidationError($type,'SYNTHETIC_SECRET','SYNTHETIC_SECRET',$x)]);}$positive=governance_spaces_noone_validation(err_space('core:view-policy','no-one'));$neg=[governance_spaces_noone_validation(err_space('core:view-policy','admin')),governance_spaces_noone_validation(err_space('core:edit-policy','no-one')),governance_spaces_noone_validation(err_space('core:view-policy','no-one',false)),governance_spaces_noone_validation(new RuntimeException('SYNTHETIC_SECRET')),governance_spaces_noone_validation(new PhabricatorApplicationTransactionValidationException([]))];echo json_encode(['positive'=>$positive,'negative'=>$neg]);"""
  p=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True,timeout=15);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');self.assertEqual({'positive':True,'negative':[False]*5},json.loads(p.stdout))
 def test_current_twenty_checks_and_old_eighteen_are_distinct(self):
  d=template();self.assertEqual(len(gate.source_obligations(ROOT)),11);self.assertEqual(gate.validate(d,source_revision='a'*40)['checks'],20)
  old=copy.deepcopy(d);old['check_count']=18
  with self.assertRaises(ValueError):gate.validate(old,source_revision='a'*40)
  source=(ROOT/'support/governance/recovery/spaces-source-focused.php').read_text();self.assertIn('$defaultsAfter===$defaultsBefore',source);self.assertIn('governance_spaces_fixture_counts($fixture)===$beforeRejected',source);self.assertIn("unset($GLOBALS['governance_spaces_editor_failure'])",source);self.assertIn("$restricted=$create(PhabricatorPolicies::POLICY_ADMIN",source)

 def test_actual_CI_shell_exclusive_paths_and_native_failure_stop(self):
  workflow=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text();name='      - name: Verify fresh native Space source facts after Project source facts\n';step=workflow.split(name,1)[1].split('      - uses:',1)[0];script='\n'.join(line[10:] for line in step.splitlines() if line.startswith('          '));syntax=subprocess.run(['bash','-n'],input=script,text=True,capture_output=True);self.assertEqual(syntax.returncode,0,syntax.stderr)
  head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
  with tempfile.TemporaryDirectory() as temp:
   tmp=Path(temp);foreign=tmp/'foreign';foreign.mkdir();sentinel=foreign/'sentinel';sentinel.write_bytes(b'foreign-private');before=(stable_stamp(sentinel),sentinel.read_bytes());target=tmp/'governance-spaces-source-facts';env=dict(os.environ,RUNNER_TEMP=temp,GITHUB_SHA=head,GORGE_TEST_MYSQL_PASSWORD='synthetic-only')
   for kind in ('directory','symlink','file'):
    if kind=='directory':target.mkdir();(target/'sentinel').write_bytes(b'owned-elsewhere')
    elif kind=='symlink':target.symlink_to(foreign,target_is_directory=True)
    else:target.write_bytes(b'owned-elsewhere')
    identity=stable_stamp(target);p=subprocess.run(['bash','-e','-o','pipefail','-c',script],cwd=ROOT,env=env,capture_output=True,text=True,timeout=10);self.assertNotEqual(p.returncode,0);self.assertEqual(stable_stamp(target),identity);self.assertEqual((stable_stamp(sentinel),sentinel.read_bytes()),before)
    if kind=='directory':self.assertEqual((target/'sentinel').read_bytes(),b'owned-elsewhere');(target/'sentinel').unlink();target.rmdir()
    else:target.unlink()
   for directory in ('governance-batch-1','governance-native-organization'):(tmp/directory).mkdir()
   (tmp/'governance-batch-1/native.json').write_bytes(b'synthetic-reference-only');(tmp/'governance-native-organization/accepted.json').write_bytes(b'synthetic-reference-only')
   fake=tmp/'commands';fake.mkdir();php=fake/'php';php.write_text('#!/bin/sh\nexit 42\n');php.chmod(0o700);env['PATH']=str(fake)+os.pathsep+env['PATH']
   p=subprocess.run(['bash','-e','-o','pipefail','-c',script],cwd=ROOT,env=env,capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,42,p.stderr);self.assertEqual((target/'source-revision.txt').read_text(),head+'\n');self.assertFalse((target/'accepted.json').exists());self.assertEqual((target/'shared-native-pure.json').read_bytes(),b'synthetic-reference-only');self.assertEqual(stat.S_IMODE(target.stat().st_mode),0o700)
