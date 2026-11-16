"""Explicit current Task counterpart; legacy default remains unchanged/refusing."""
import asyncio,ast,base64,copy,json,subprocess,zlib,hashlib,os,stat,shutil,sys,tempfile,threading,unittest,re
from contextlib import contextmanager
from pathlib import Path
from types import SimpleNamespace
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import source_operation_snapshot as source
CHECKPOINT_ENV='PHORGE_TASK_TEST_CHECKPOINT'
def checkpoint():
 value=os.environ.get(CHECKPOINT_ENV)
 if type(value)is not str or re.fullmatch('[a-f0-9]{40}',value)is None or value=='0'*40:raise ValueError('Task_checkpoint_required')
 return value
def open_current_Task_v2(root=ROOT):
 return source.open_task_source_operation(root,expected_checkpoint=checkpoint())
def canonical_temp_base():return Path(tempfile.gettempdir()).resolve(strict=True)

class CurrentTaskSourceOperationV2(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        global admission,source,access,assessment
        import project_description_admission_delta as admission
        import source_operation_snapshot as source
        import check_recovery_oauth_access_producer_v3 as access
        import check_recovery_assessment_credentials_v2 as assessment
        cls.temp=tempfile.TemporaryDirectory(dir=canonical_temp_base())
        cls.producer=Path(cls.temp.name)/'producer'
        shutil.copytree(ROOT,cls.producer,symlinks=True,ignore=shutil.ignore_patterns('.git','__pycache__'))
    @classmethod
    def tearDownClass(cls):cls.temp.cleanup()
    @contextmanager
    def changed(self,path,raw):
        target=self.producer/path;old=target.read_bytes()
        try:target.write_bytes(raw);yield target
        finally:target.write_bytes(old)

    def test_portable_temp_base_uses_existing_tmp_layout_and_resolves_real_alias(self):
        # Real owned host directories; no Linux OS or system directory is emulated.
        from unittest.mock import patch
        self.assertTrue(canonical_temp_base().is_dir())
        with tempfile.TemporaryDirectory(dir=canonical_temp_base()) as directory:
            parent=Path(directory);ordinary=parent/'tmp';ordinary.mkdir()
            with patch.object(tempfile,'gettempdir',return_value=str(ordinary)):
                self.assertEqual(canonical_temp_base(),ordinary)
                with tempfile.TemporaryDirectory(dir=canonical_temp_base()) as child:
                    self.assertEqual(Path(child).parent,ordinary)
            alias=parent/'tmp-alias';alias.symlink_to(ordinary,target_is_directory=True)
            with patch.object(tempfile,'gettempdir',return_value=str(alias)):
                self.assertEqual(canonical_temp_base(),ordinary)
                with tempfile.TemporaryDirectory(dir=canonical_temp_base()) as child:
                    self.assertEqual(Path(child).parent,ordinary)

    def test_both_roots_and_all_ancestors_are_identity_bound(self):
        with open_current_Task_v2(self.producer) as operation:
            expected={p for root in (ROOT,self.producer) for p in (root,*root.parents)}
            self.assertEqual(set(operation._ancestor_identities),expected)
            for path,identity in operation._ancestor_identities.items():
                info=path.lstat()
                self.assertEqual(identity,(info.st_dev,info.st_ino,info.st_uid,info.st_gid,info.st_mode))

    def test_real_parent_inode_replacement_same_subtree_is_refused(self):
        parent=self.producer.parent;previous=parent.with_name(parent.name+'-previous')
        old_mode=stat.S_IMODE(parent.stat().st_mode)
        file=self.producer/'support/governance/recovery/oauth-access-cases.php'
        before_root=(self.producer.stat().st_dev,self.producer.stat().st_ino)
        before_file=source._stamp(file.stat());before_bytes=file.read_bytes()
        moved=False
        try:
            with self.assertRaises(ValueError):
                with open_current_Task_v2(self.producer) as operation:
                    before_parent=(parent.stat().st_dev,parent.stat().st_ino)
                    parent.rename(previous);parent.mkdir(mode=old_mode)
                    (previous/'producer').rename(self.producer);moved=True
                    self.assertNotEqual((parent.stat().st_dev,parent.stat().st_ino),before_parent)
                    self.assertEqual((self.producer.stat().st_dev,self.producer.stat().st_ino),before_root)
                    self.assertEqual(source._stamp(file.stat()),before_file)
                    self.assertEqual(file.read_bytes(),before_bytes)
                    self.assertEqual(len(access.SQL_obligations(self.producer,_source_operation=operation)),8)
        finally:
            if moved:self.producer.rename(previous/'producer');parent.rmdir();previous.rename(parent)
            elif previous.exists():
                if parent.exists():parent.rmdir()
                previous.rename(parent)

    def test_real_parent_mode_change_refused(self):
        parent=self.producer.parent;mode=stat.S_IMODE(parent.stat().st_mode)
        try:
            with self.assertRaises(ValueError):
                with open_current_Task_v2(self.producer) as operation:
                    parent.chmod(mode ^ 0o010)
                    self.assertEqual(len(access.SQL_obligations(self.producer,_source_operation=operation)),8)
        finally:parent.chmod(mode)

    def test_real_parent_group_ownership_change_refused(self):
        parent=self.producer.parent;before=parent.stat();groups=[g for g in os.getgroups() if g!=before.st_gid]
        if not groups:self.skipTest('No second authorized group for real group-ownership fault')
        group=groups[0]
        try:
            os.chown(parent,-1,group);os.chown(parent,-1,before.st_gid)
        except PermissionError:
            self.skipTest('Host does not permit group change on owned synthetic parent')
        try:
            with self.assertRaises(ValueError):
                with open_current_Task_v2(self.producer) as operation:
                    os.chown(parent,-1,group)
                    self.assertEqual(parent.stat().st_uid,before.st_uid)
                    self.assertEqual(parent.stat().st_gid,group)
                    self.assertEqual(len(access.SQL_obligations(self.producer,_source_operation=operation)),8)
        finally:os.chown(parent,-1,before.st_gid)

    def test_lstat_uid_gid_faults_refuse_exit_without_privileged_chown(self):
        # Explicit filesystem-stat fault injection; this is not real chown or actor evidence.
        from unittest.mock import patch
        parent=self.producer.parent;original=source.os.lstat
        for field in (4,5):
            with self.subTest(field='uid' if field==4 else 'gid'):
                operation=open_current_Task_v2(self.producer);operation.__enter__()
                def fault(path,*args,**kwargs):
                    info=original(path,*args,**kwargs)
                    if Path(path)==parent:
                        values=list(info);values[field]+=1;return os.stat_result(values)
                    return info
                with patch.object(source.os,'lstat',side_effect=fault):
                    with self.assertRaises(ValueError):operation.__exit__(None,None,None)
                with open_current_Task_v2(self.producer) as fresh:fresh.require(self.producer)

    def test_benign_parent_activity_is_not_directory_timestamp_identity(self):
        sibling=self.producer.parent/'benign-unrelated-file'
        try:
            with open_current_Task_v2(self.producer) as operation:
                sibling.write_bytes(b'non-source sibling activity')
                self.assertEqual(len(access.SQL_obligations(self.producer,_source_operation=operation)),8)
        finally:
            if sibling.exists():sibling.unlink()

    def test_actual_current_graph_two_families_and_closed_scope(self):
        with open_current_Task_v2() as operation:
            self.assertEqual(len(access.SQL_obligations(_source_operation=operation)),8)
            self.assertEqual(len(assessment.SQL_obligations(_source_operation=operation)),8)
            self.assertEqual(len(operation._modules),len(source.GRAPH))
            for name,module in operation._modules.items():
                self.assertEqual(Path(module.__file__),ROOT/'scripts/governance'/(name+'.py'))
                self.assertIsNot(module,sys.modules.get(name))
        with self.assertRaises(ValueError):source.require_operation(operation,ROOT)

    def test_fake_capabilities_and_constructor_key_refused(self):
        for value in [True,False,None,SimpleNamespace(require=lambda root:True),object.__new__(source.SourceOperation)]:
            with self.assertRaises(ValueError):source.require_operation(value,ROOT)
        with self.assertRaises(ValueError):source.SourceOperation(object(),ROOT)

    def test_fake_helper_capability_and_public_role_rebinding_refused(self):
        fake=SimpleNamespace(require=lambda:True)
        with self.assertRaises(ValueError):assessment.source_view('unknown',b'unknown','x',_source_operation=fake)
        with open_current_Task_v2() as operation:
            for name in ('code_root','producer_root','coordinator','worker_terminal','calendar_delta'):
                with self.assertRaises(AttributeError):setattr(operation,name,True)
            operation.require(ROOT)

    def test_root_roles_are_distinct_and_explicit(self):
        with open_current_Task_v2(self.producer) as operation:
            self.assertEqual(operation.code_root,ROOT)
            self.assertEqual(operation.producer_root,self.producer)
            self.assertEqual(len(access.SQL_obligations(self.producer,_source_operation=operation)),8)
            self.assertEqual(len(assessment.summary('a'*40,self.producer,_source_operation=operation)['executed_case_obligations']),8)
            with self.assertRaises(ValueError):access.SQL_obligations(ROOT,_source_operation=operation)
            with self.assertRaises(ValueError):source.require_operation(operation,self.producer.parent)

    def test_unknown_source_reader_and_stage_refused(self):
        with open_current_Task_v2() as operation:
            with self.assertRaises(ValueError):operation.read_producer(ROOT/'unknown.py')
            with self.assertRaises(ValueError):operation.coordinator.assert_cohort(ROOT,lambda path:operation.read_producer(path))
            path='src/__phutil_library_map__.php';raw=operation.read_producer(ROOT/path)
            old=operation.coordinator.source_view(path,raw)
            self.assertNotEqual(old,raw)
            with self.assertRaises(ValueError):operation.coordinator.source_view(path,old)
            with self.assertRaises(ValueError):operation.coordinator.source_view(path,raw+b'unknown')

    def test_private_graph_does_not_call_public_inverse(self):
        import recovery_oauth_authorization_source_delta as public
        from unittest.mock import patch
        with patch.object(public,'reduce_if_selected',side_effect=AssertionError('public inverse graph used')):
            with open_current_Task_v2() as operation:
                self.assertEqual(len(access.SQL_obligations(_source_operation=operation)),8)

    def test_module_constants_immutable_and_replacement_refused_at_exit(self):
        with open_current_Task_v2() as operation:
            module=operation._modules['recovery_oauth_code_source_delta']
            with self.assertRaises(TypeError):module.SOURCE_INPUTS['unknown']='x'
        with self.assertRaises(ValueError):
            with open_current_Task_v2() as operation:
                operation._modules['recovery_oauth_code_source_delta'].SOURCE_INPUTS={}

    def test_new_eligible_leaf_refused_before_entry(self):
        target=self.producer/'unknown.py';target.write_bytes(b'unknown')
        try:
            with self.assertRaises(ValueError):
                with open_current_Task_v2(self.producer):pass
        finally:target.unlink()

    def test_new_eligible_leaf_mid_operation_refuses_exit(self):
        target=self.producer/'unknown.py'
        try:
            with self.assertRaises(ValueError):
                with open_current_Task_v2(self.producer):target.write_bytes(b'unknown')
        finally:
            if target.exists():target.unlink()

    def test_actual_source_mutation_refuses_exit(self):
        name='support/governance/recovery/oauth-access-cases.php';target=self.producer/name;old=target.read_bytes()
        try:
            with self.assertRaises(ValueError):
                with open_current_Task_v2(self.producer) as operation:
                    self.assertEqual(len(access.SQL_obligations(self.producer,_source_operation=operation)),8)
                    target.write_bytes(old+b'\n// unreviewed\n')
        finally:target.write_bytes(old)

    def test_unknown_metadata_and_mixed_production_refused(self):
        name='support/governance/project-description-ci/metadata-lock.json'
        with self.changed(name,(self.producer/name).read_bytes()+b'\nunknown'):
            with self.assertRaises(ValueError):
                with open_current_Task_v2(self.producer):pass
        # Capture authenticated parent DATA under a real current Task owner;
        # exit that owner before mutating the producer for the original negative.
        with open_current_Task_v2(self.producer) as operation:
            source.require_task_operation(operation,self.producer)
            description=operation._modules['project_description_admission_delta'].description
            snapshot=description.current_snapshot(self.producer)
            prior=description.reconstruct_main(snapshot)
        name='src/__phutil_library_map__.php'
        with self.changed(name,prior[name]):
            with self.assertRaises(ValueError):
                with open_current_Task_v2(self.producer):pass

    def test_symlink_and_mode_change_refused(self):
        target=self.producer/'support/governance/recovery/oauth-access-cases.php';old=target.read_bytes();mode=target.stat().st_mode
        try:
            with self.assertRaises(ValueError):
                with open_current_Task_v2(self.producer):target.chmod(0o666)
        finally:target.chmod(mode & 0o777)
        backup=Path(self.temp.name)/'original.php';target.rename(backup)
        try:
            target.symlink_to(backup)
            with self.assertRaises(ValueError):
                with open_current_Task_v2(self.producer):pass
        finally:target.unlink();backup.rename(target)
        self.assertEqual(target.read_bytes(),old)

    def test_thread_and_task_inherited_scope_refused(self):
        with open_current_Task_v2() as operation:
            outcomes=[]
            def other_thread():
                try:operation.require()
                except ValueError:outcomes.append(True)
            thread=threading.Thread(target=other_thread);thread.start();thread.join()
            self.assertEqual(outcomes,[True])
            async def child():
                with self.assertRaises(ValueError):operation.require()
            asyncio.run(child())

    def test_foreign_and_repeated_close_do_not_poison_owner(self):
        with open_current_Task_v2() as operation:
            outcomes=[]
            def other_thread():
                try:operation.__exit__(None,None,None)
                except ValueError:outcomes.append(True)
            thread=threading.Thread(target=other_thread);thread.start();thread.join()
            self.assertEqual(outcomes,[True])
            self.assertEqual(len(access.SQL_obligations(_source_operation=operation)),8)
        with self.assertRaises(ValueError):operation.__exit__(None,None,None)
        with open_current_Task_v2() as fresh:fresh.require()

    def test_public_nested_operation_refused_private_nested_allowed(self):
        with open_current_Task_v2() as operation:
            self.assertEqual(len(access.summary('a'*40,_source_operation=operation)['executed_case_obligations']),8)
            with self.assertRaises(ValueError):
                with open_current_Task_v2():pass

    def test_exception_preserved_and_next_operation_fresh(self):
        with self.assertRaisesRegex(RuntimeError,'synthetic-primary'):
            with open_current_Task_v2(self.producer):raise RuntimeError('synthetic-primary')
        with open_current_Task_v2(self.producer) as operation:operation.require(self.producer)
        name='support/governance/recovery/oauth-access-cases.php'
        with self.changed(name,(self.producer/name).read_bytes()+b'\nunknown'):
            with self.assertRaises(ValueError):
                with open_current_Task_v2(self.producer):pass

    def test_exception_plus_source_mutation_keeps_fixed_exit_note(self):
        target=self.producer/'support/governance/recovery/oauth-access-cases.php';old=target.read_bytes()
        try:
            with self.assertRaisesRegex(RuntimeError,'synthetic-primary') as caught:
                with open_current_Task_v2(self.producer):
                    target.write_bytes(old+b'unknown');raise RuntimeError('synthetic-primary')
            self.assertEqual(caught.exception.__notes__,['source_operation_exit_refused'])
        finally:target.write_bytes(old)

class LegacyDefaultBoundaryV2(unittest.TestCase):
 def test_legacy_default_does_not_silently_adopt_Task(self):
  with self.assertRaises(ValueError):
   with source.open_source_operation(ROOT):pass
 def test_Task_checkpoint_is_explicit_not_HEAD_auto_adopted(self):
  self.assertEqual(source.ROOT,ROOT)
  self.assertEqual(len(checkpoint()),40)
