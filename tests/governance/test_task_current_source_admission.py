# Actual physical files and native SQL parser/admission, never live SQL evidence.
import hashlib,importlib.util,json,os,subprocess,sys,tempfile,unittest
from pathlib import Path
from unittest import mock
from test_project_description_execution import php
ROOT=Path(__file__).resolve().parents[2]
HERE=ROOT/'support/governance/task-content-grants-qualification'
def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
window=load('task_source_window_test',HERE/'source_gate.py')
bridge=load('task_source_bridge_test',ROOT/'scripts/governance/task_content_grant_source_delta.py')
runner=load('task_runner_test',HERE/'qualify.py')
def manifest(root,path):
    actual=window.snapshot(root);data={'schema':'N05-Task-exact-source-window-v1','parent_revision':window.PARENT_REVISION,'parent_tree':window.PARENT_TREE,'entries':actual['entries']};raw=(json.dumps(data,sort_keys=True)+'\n').encode();path.write_bytes(raw);path.chmod(0o600);return hashlib.sha256(raw).hexdigest()
class PhysicalSourceWindow(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.home=Path(self.tmp.name).resolve();self.root=self.home/'source';self.root.mkdir(mode=0o700);self.file=self.root/'native.php';self.file.write_text('<?php // exact owned test\n');self.file.chmod(0o644);self.m=self.home/'manifest.private.json';self.pin=manifest(self.root,self.m)
    def verify(self):return window.verify(self.root,self.m,self.pin)
    def test_exact_files_verified_without_claiming_revision_or_SQL_authority(self):
        d=self.verify();self.assertTrue(d['current_physical_source_verified']);self.assertFalse(d['committed_source_proved']);self.assertFalse(d['runtime_qualified']);self.assertFalse(d['SQL_executed']);self.assertFalse(d['execution_authorized'])
    def test_changed_native_bytes_are_rejected(self):
        self.file.write_text('<?php // replaced\n')
        with self.assertRaises(ValueError):self.verify()
    def test_same_bytes_with_changed_executable_mode_are_rejected(self):
        self.file.chmod(0o755)
        with self.assertRaises(ValueError):self.verify()
    def test_unknown_payload_file_is_rejected(self):
        (self.root/'unknown.php').write_text('x')
        with self.assertRaises(ValueError):self.verify()
    def test_missing_file_is_not_silently_ignored(self):
        self.file.unlink()
        with self.assertRaises(ValueError):self.verify()
    def test_symlink_substitution_cannot_reuse_file_hash(self):
        copy=self.home/'foreign.php';copy.write_bytes(self.file.read_bytes());self.file.unlink();self.file.symlink_to(copy)
        with self.assertRaises(ValueError):self.verify()
    def test_exact_native_relative_symlink_and_target_are_both_required(self):
        (self.root/'bin').mkdir();(self.root/'bin/storage').symlink_to('../native.php');self.pin=manifest(self.root,self.m);self.assertEqual(self.verify()['source_files'],2)
        (self.root/'bin/storage').unlink();(self.root/'bin/storage').symlink_to('../absent.php')
        with self.assertRaises(ValueError):self.verify()
    def test_hardlink_alias_is_rejected_even_with_identical_bytes(self):
        os.link(self.file,self.home/'hardlink')
        with self.assertRaises(ValueError):self.verify()
    def test_group_writable_directory_is_rejected(self):
        self.root.chmod(0o770)
        with self.assertRaises(ValueError):self.verify()
    def test_tracked_systemd_template_filename_is_not_treated_as_path_escape(self):
        p=self.root/'phorge-phd@.service';p.write_text('[Unit]');p.chmod(0o644);self.pin=manifest(self.root,self.m);self.assertEqual(self.verify()['source_files'],2)
    def test_tracked_empty_file_is_accepted_and_still_hashed(self):
        (self.root/'empty').write_bytes(b'');(self.root/'empty').chmod(0o644);self.pin=manifest(self.root,self.m);self.assertEqual(self.verify()['source_files'],2)
    def test_duplicate_manifest_key_refuses_before_source_read(self):
        raw=self.m.read_bytes().replace(b'"schema":',b'"schema":"duplicate","schema":',1);self.m.write_bytes(raw);self.pin=hashlib.sha256(raw).hexdigest()
        with self.assertRaises(ValueError):self.verify()
    def test_wrong_manifest_hash_cannot_be_self_reported(self):
        self.pin='0'*64
        with self.assertRaises(ValueError):self.verify()
    def test_foreign_parent_revision_is_not_admitted(self):
        d=json.loads(self.m.read_bytes());d['parent_revision']='0'*40;raw=json.dumps(d).encode();self.m.write_bytes(raw);self.pin=hashlib.sha256(raw).hexdigest()
        with self.assertRaises(ValueError):self.verify()
    def test_manifest_alias_is_refused(self):
        alias=self.home/'alias';alias.symlink_to(self.m)
        with self.assertRaises(ValueError):window.verify(self.root,alias,self.pin)
    def test_private_config_is_explicitly_bound_not_blanket_ignored(self):
        d=self.root/'conf/local';d.mkdir(parents=True);c=d/'local.json';c.write_text('{"owned":"synthetic"}');c.chmod(0o600);h=hashlib.sha256(c.read_bytes()).hexdigest()
        with self.assertRaises(ValueError):self.verify()
        self.assertTrue(window.verify(self.root,self.m,self.pin,h)['private_configuration_bound'])
        c.write_text('{"owned":"changed"}')
        with self.assertRaises(ValueError):window.verify(self.root,self.m,self.pin,h)
    def test_private_config_must_remain_private(self):
        d=self.root/'conf/local';d.mkdir(parents=True);c=d/'local.json';c.write_text('{}');c.chmod(0o644)
        with self.assertRaises(ValueError):window.verify(self.root,self.m,self.pin,hashlib.sha256(c.read_bytes()).hexdigest())
    def test_Git_metadata_is_not_claimed_as_revision_proof(self):
        (self.root/'.git').write_text('gitdir: /not/used/by/verifier\n');d=self.verify();self.assertFalse(d['git_metadata_separately_checked_by_Root']);self.assertFalse(d['committed_source_proved'])
    def test_untrusted_Git_symlink_is_rejected(self):
        (self.root/'.git').symlink_to(self.home/'elsewhere')
        with self.assertRaises(ValueError):self.verify()
    def test_unknown_relative_path_and_traversal_not_allowed_in_manifest(self):
        for name in ['../native.php','/native.php','src/../native.php','src/.git/config']:
            d=json.loads(self.m.read_bytes());d['entries'][name]=next(iter(d['entries'].values()));raw=json.dumps(d).encode();self.m.write_bytes(raw);self.pin=hashlib.sha256(raw).hexdigest()
            with self.assertRaises(ValueError):self.verify()

class ExactHistoricalBridge(unittest.TestCase):
    def test_all_four_modified_bodies_restore_exact5903_parent(self):
        for relative,pin in bridge.CURRENT_SHARED.items():
            with self.subTest(relative=relative):
                raw=(ROOT/relative).read_bytes();old=bridge.reduce_selected(relative,raw);self.assertEqual(hashlib.sha256(old).hexdigest(),pin['parent_sha256']);self.assertEqual(len(old),pin['parent_bytes'])
    def test_tamper_is_refused_before_inverse(self):
        for relative in bridge.CURRENT_SHARED:
            with self.subTest(relative=relative),self.assertRaises(ValueError):bridge.reduce_selected(relative,(ROOT/relative).read_bytes()+b'\n')
    def test_exact_new_Task_files_removed_only_after_full_pin_check(self):
        self.assertEqual(len(bridge.NEW_TASK_SOURCE),18)
        for relative in bridge.NEW_TASK_SOURCE:
            raw=(ROOT/relative).read_bytes();self.assertIsNone(bridge.reduce_selected(relative,raw))
            with self.assertRaises(ValueError):bridge.reduce_selected(relative,raw+b'x')
    def test_unrelated_current_source_is_not_downgraded(self):
        for relative in ['src/infrastructure/governance/execution/PhorgeProjectNativeExecutionScope.php','src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php']:
            raw=(ROOT/relative).read_bytes();self.assertEqual(bridge.reduce_selected(relative,raw),raw)

class CapturedProgramAdmission(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.home=Path(self.tmp.name).resolve();self.root=self.home/'source';self.root.mkdir(mode=0o700)
        self.paths={'template':'support/governance/task-content-grants-qualification/native.php','helper':'support/governance/project-content-grants-qualification/native.php'}
        for role,name in self.paths.items():
            p=self.root/name;p.parent.mkdir(mode=0o755,parents=True,exist_ok=True);p.write_text('<?php // '+role+' original\n');p.chmod(0o644)
        self.m=self.home/'manifest.private.json';self.pin=manifest(self.root,self.m);self.input=self.home/'input.private.json';self.input.write_text('{}');self.input.chmod(0o600)
    def test_expected_captured_programs_match_externally_pinned_manifest(self):
        with mock.patch.object(runner,'ROOT_SOURCE_MANIFEST_SHA256',self.pin):captured=runner.capture_programs(self.root,window,self.m)
        for role,name in self.paths.items():self.assertEqual(captured[role]['raw'],(self.root/name).read_bytes());self.assertEqual(captured[role]['expected_sha256'],hashlib.sha256(captured[role]['raw']).hexdigest())
    def drift_after_verified_window(self,role):
        def admitted(*args):
            before=window.verify(self.root,self.m,self.pin)
            (self.root/self.paths[role]).write_text('<?php // changed after verify\n')
            return {},self.root,window,before
        with mock.patch.object(runner,'ROOT_SOURCE_MANIFEST_SHA256',self.pin),mock.patch.object(runner,'bind',side_effect=admitted),mock.patch.object(runner.subprocess,'Popen')as popen:
            with self.assertRaises(ValueError):runner.run(self.home/'unused-profile',self.home/'unused-review',self.m,'schema',self.input,'user',self.home/'output')
            popen.assert_not_called();self.assertFalse((self.home/'output').exists())
    def test_template_drift_after_window_refuses_before_any_process_launch(self):self.drift_after_verified_window('template')
    def test_helper_drift_after_window_refuses_before_any_process_launch(self):self.drift_after_verified_window('helper')

class ActualNativeTaskAdmission(unittest.TestCase):
    def test_live_Task_patch_SchemaSpec_defaultOFF_registration_without_SQL(self):
        d=php("define('N05_TASK_QUALIFICATION_LIBRARY_ONLY',true);require 'support/governance/task-content-grants-qualification/native.php';echo json_encode([tqdiscover(),(new ReflectionProperty(LiskDAO::class,'connections'))->getValue()]);")
        self.assertTrue(d[0]['native_patch_discovered']);self.assertTrue(d[0]['native_schema_spec_registered']);self.assertFalse(d[0]['default_enabled']);self.assertFalse(d[0]['SQL_executed']);self.assertEqual(d[1],[])
    def test_actual_configuration_rejects_dynamic_libraries_remote_DB_and_wrong_fixture(self):
        d=php(r"define('N05_TASK_QUALIFICATION_LIBRARY_ONLY',true);require 'support/governance/task-content-grants-qualification/native.php';$p=['namespace'=>'governance_native_'.str_repeat('a',16)];$c=['load-libraries'=>[],'cluster.databases'=>[],'cluster.search'=>[],'notification.servers'=>[],'cluster.instance'=>null,'gorge.db.uri'=>null,'mysql.host'=>'127.0.0.1','mysql.port'=>3306,'mysql.user'=>'owned','mysql.pass'=>'synthetic','storage.default-namespace'=>$p['namespace'],'governance.task-content-grants.enabled'=>true,'governance.organization.enabled'=>true,'security.require-multi-factor-auth'=>false];$good=tqconfiguration($p,$c);$n=0;foreach([['load-libraries',['foreign']],['cluster.databases',['foreign']],['mysql.host','remote.invalid'],['mysql.port','3306'],['storage.default-namespace','foreign'],['governance.task-content-grants.enabled',1],['security.require-multi-factor-auth',true]]as$x){$changed=$c;$changed[$x[0]]=$x[1];try{tqconfiguration($p,$changed);}catch(RuntimeException$e){$n++;}}echo json_encode([$good,$n,(new ReflectionProperty(LiskDAO::class,'connections'))->getValue()]);")
        self.assertEqual(d,[{'local_configuration_checked':True,'configuration_execution_authority':False},7,[]])
    def test_fixture_exact_Task_migration_admitted_unknowns_still_refused_before_SQL(self):
        d=php(r"""require 'support/governance/completion/fixture.php';$fixture=(new ReflectionClass(PhorgeGovernanceCompletionFixture::class))->newInstanceWithoutConstructor();(new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'root'))->setValue($fixture,getcwd());$patches=PhabricatorSQLPatchList::buildAllPatches();$stack=(new PhabricatorConfigStackSource())->pushSource(new PhabricatorConfigDefaultSource());(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);$m=new ReflectionMethod(PhorgeGovernanceCompletionFixture::class,'applyPendingPatches');$out=[];foreach(['phabricator:20261112.task.contentgrants.sql','phabricator:20261112.task.contentgrants.sql.extra','other:20261112.task.contentgrants.sql','phabricator:20990101.task.contentgrants.sql']as$k){try{$m->invoke($fixture,$patches,[],[$k]);throw new Exception('accepted');}catch(RuntimeException$e){$out[]=$e->getMessage();}}echo json_encode([$out,(new ReflectionProperty(LiskDAO::class,'connections'))->getValue()]);""")
        self.assertEqual(d,[['Inherited migration context refused.']+['Unreviewed current-source migration refused.']*3,[]])
    def test_native_negative_approval_requires_distinct_state_and_exact_subject(self):
        d=php(r"define('N05_TASK_QUALIFICATION_LIBRARY_ONLY',true);require 'support/governance/task-content-grants-qualification/native.php';$p=['subjects'=>['user'=>'u','business-team'=>'t']];$s=['subject_kind'=>'user','subject_id'=>'u','approval_state'=>'submitted','approver_present'=>false,'applied_at_present'=>false];$a=$s;$a['approval_state']='applied';$a['approver_present']=true;$a['applied_at_present']=true;$ok=0;foreach([['deny-self',$s],['approve',$s],['deny-replay',$a]]as$x){tqapprovalPrecondition($p,$x[0],'user',$x[1]);$ok++;}$bad=0;foreach([['deny-self','user',$a],['deny-replay','user',$s],['deny-self','business-team',$s],['deny-self','user',array_replace($s,['subject_id'=>'foreign'])],['deny-self','user',array_replace($s,['approver_present'=>true])],['deny-replay','user',array_replace($a,['applied_at_present'=>false])]]as$x){try{tqapprovalPrecondition($p,$x[0],$x[1],$x[2]);}catch(RuntimeException$e){$bad++;}}echo json_encode([$ok,$bad,(new ReflectionProperty(LiskDAO::class,'connections'))->getValue()]);")
        self.assertEqual(d,[3,6,[]])
    def test_direct_Task_planning_defaultOFF_refuses_before_any_native_connection(self):
        d=php(r"$reason=null;try{PhorgeTaskContentGrantStore::planning(str_repeat('a',64));}catch(RuntimeException$e){$reason=$e->getMessage();}echo json_encode([$reason,(new ReflectionProperty(LiskDAO::class,'connections'))->getValue()]);")
        self.assertEqual(d,['task_content_grant_disabled',[]])
    def test_unbound_native_runner_refuses_before_loading_any_configuration(self):
        q=subprocess.run(['php',str(HERE/'native.php'),'/not/a/source','/not/a/profile','schema','/not/an/input','user'],capture_output=True,text=True,timeout=10)
        self.assertEqual(q.returncode,1);self.assertEqual(q.stdout,'');self.assertEqual(q.stderr,'task_qualification_unbound\n')
    def test_captured_native_library_bytes_load_then_missing_profile_refuses_before_bootstrap(self):
        raw=(HERE/'native.php').read_bytes();helper=(ROOT/'support/governance/project-content-grants-qualification/native.php').read_bytes()
        with tempfile.TemporaryDirectory()as td:
            home=Path(td).resolve();copy=home/'captured.php';copy.write_bytes(helper);copy.chmod(0o600)
            bindings={'N05_TASK_PROFILE_SHA256':'a'*64,'N05_TASK_CONFIGURATION_SHA256':'b'*64,'N05_TASK_SOURCE_WINDOW_SHA256':'c'*64,'N05_TASK_HELPER_ROOT':str(ROOT),'N05_TASK_HELPER_SHA256':hashlib.sha256(helper).hexdigest(),'N05_TASK_CAPTURED_HELPER_PATH':str(copy)}
            for key,value in bindings.items():raw=raw.replace(('const '+key+'=null;').encode(),('const '+key+"='"+value+"';").encode())
            native=home/'native.php';native.write_bytes(raw);native.chmod(0o600)
            q=subprocess.run(['php',str(native),str(ROOT),str(home/'missing-profile'),'schema',str(home/'missing-input'),'user'],capture_output=True,text=True,timeout=10)
            self.assertEqual(q.returncode,1);self.assertEqual(json.loads(q.stdout)['phase'],'profile');self.assertEqual(q.stderr,'task_qualification_refused_retain_no_retry\n')
            copy.write_bytes(helper+b'\n// drift\n')
            q=subprocess.run(['php',str(native),str(ROOT),str(home/'missing-profile'),'schema',str(home/'missing-input'),'user'],capture_output=True,text=True,timeout=10)
            self.assertEqual(q.returncode,1);self.assertEqual(q.stdout,'');self.assertEqual(q.stderr,'task_qualification_helper_refused\n')
    def test_unbound_Python_runner_refuses_before_file_or_process_access(self):
        with self.assertRaises(ValueError):runner.bind(Path('/not/profile'),Path('/not/review'),Path('/not/manifest'))
    def test_forged_Row_or_actor_payload_does_not_create_Task_execution(self):
        d=php(r"$n=0;foreach([[],['actor'=>'x'],['CAN_EDIT'=>true]]as$p){try{PhorgeTaskContentGrantService::call((new ReflectionClass(PhorgeGovernanceNativeActorContext::class))->newInstanceWithoutConstructor(),'submit',$p);}catch(RuntimeException$e){if($e->getMessage()==='task_content_grant_disabled')$n++;}}echo json_encode([$n,PhorgeTaskContentGrantService::executionStatus(),(new ReflectionProperty(LiskDAO::class,'connections'))->getValue()]);")
        self.assertEqual(d[0],3);self.assertFalse(d[1]['can_execute']);self.assertFalse(d[1]['HTTP_implemented']);self.assertEqual(d[2],[])
if __name__=='__main__':unittest.main()
