"""Actual-current private graph/capability controls; no SQL or producer evidence."""
import asyncio
import ast
import base64
import copy
import json
import subprocess
import zlib
from contextlib import contextmanager
import hashlib
import os
import stat
from pathlib import Path
import shutil
import sys
import tempfile
import threading
from types import SimpleNamespace
import unittest

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))

def canonical_temp_base():
    return Path(tempfile.gettempdir()).resolve(strict=True)


def policy_functions(path=None):
    # Compile only actual pure-policy definitions. No admission patch, fake op or issued capability.
    path=ROOT/'scripts/governance/source_operation_snapshot.py' if path is None else Path(path)
    tree=ast.parse(path.read_bytes())
    functions={'_need','_stamp','_read','_registry_object','_relative_source_path','_source_pin',
               '_authenticate_registry','_baseline_metadata','_check_historical_targets',
               '_new_path_policy','_successor_policy','_regular_new_source',
               '_held_media_current_pins','_read_held_media'}
    constants={'REGISTRY','BASE_ADMISSION_BLOB','BASE_ADMISSION_SHA256','BASE_ADMISSION_BYTES','BASE_ADMISSION_ZLIB_BASE64','BASE_INVENTORY_SHA256','MEDIA_STAGE_PREDECESSOR','MEDIA_STAGE_PREDECESSOR_SHA256'}
    nodes=[n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name in functions
           or isinstance(n,ast.Assign)and len(n.targets)==1 and isinstance(n.targets[0],ast.Name)and n.targets[0].id in constants]
    namespace={'Path':Path,'ast':ast,'os':os,'stat':stat,'json':json,'hashlib':hashlib,'subprocess':subprocess,'base64':base64,'zlib':zlib}
    exec(compile(ast.Module(body=nodes,type_ignores=[]),str(path),'exec'),namespace)
    return namespace

class NewPathPolicy(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.policy=policy_functions()
        cls.baseline_metadata=cls.policy['_baseline_metadata']()
        actual=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'cat-file','blob',cls.policy['BASE_ADMISSION_BLOB']])
        assert zlib.decompress(base64.b64decode(cls.policy['BASE_ADMISSION_ZLIB_BASE64']))==actual
        raw=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'ls-tree','-rz','20b138b4cd9a2cf61522613b4a8e5e21eca7341b'])
        cls.baseline={}
        for row in raw.split(b'\0'):
            if row:
                header,path=row.split(b'\t',1);mode,kind,oid=header.decode().split()
                cls.baseline[path.decode()]=mode
        registry=json.loads((ROOT/'support/governance/source-operation/registry-v1.json').read_bytes())
        inventory=zlib.decompress(base64.b64decode(registry['inventory_zlib_base64']))
        assert hashlib.sha256(inventory).hexdigest()==cls.policy['BASE_INVENTORY_SHA256']
        assert json.loads(inventory)==cls.baseline
    def setUp(self):self.metadata=copy.deepcopy(self.baseline_metadata);self.production={}
    def pin(self,raw=b'pure-policy fixture source'):
        return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
    def call(self,paths):
        return self.policy['_new_path_policy'](paths,self.baseline,self.metadata,self.production,self.baseline_metadata)
    def add(self,path):self.metadata[path]=self.pin();return path
    def test_embedded_old_metadata_works_in_physical_archive_without_git(self):
        from unittest.mock import patch
        with tempfile.TemporaryDirectory(dir=canonical_temp_base()) as d:
            root=Path(d)/'archive';helper=root/'scripts/governance/source_operation_snapshot.py';helper.parent.mkdir(parents=True)
            shutil.copyfile(ROOT/'scripts/governance/source_operation_snapshot.py',helper)
            self.assertFalse((root/'.git').exists())
            policy=policy_functions(helper)
            with patch.object(subprocess,'run',side_effect=AssertionError('No runtime Git fallback')):
                self.assertEqual(policy['_baseline_metadata'](),self.baseline_metadata)
    def test_embedded_old_metadata_corruption_size_and_trailing_data_refuse(self):
        original={key:self.policy[key] for key in ('BASE_ADMISSION_ZLIB_BASE64','BASE_ADMISSION_SHA256','BASE_ADMISSION_BYTES')}
        packed=base64.b64decode(original['BASE_ADMISSION_ZLIB_BASE64'])
        changes=[('BASE_ADMISSION_ZLIB_BASE64','not base64'),
                 ('BASE_ADMISSION_ZLIB_BASE64',base64.b64encode(packed[:-1]).decode()),
                 ('BASE_ADMISSION_ZLIB_BASE64',base64.b64encode(packed+b'trailing').decode()),
                 ('BASE_ADMISSION_SHA256','0'*64),('BASE_ADMISSION_BYTES',1048577),
                 ('BASE_ADMISSION_BYTES',original['BASE_ADMISSION_BYTES']+1)]
        for key,value in changes:
            try:
                self.policy[key]=value
                with self.subTest(key=key),self.assertRaises(ValueError):self.policy['_baseline_metadata']()
            finally:self.policy.update(original)
    def test_real_baseline_and_four_future_library_paths_are_data_not_fixed_six(self):
        paths=[self.add(p)for p in ['support/governance/project-description-q2-sql/cases.php',
             'support/governance/project-description-q2-sql/fault-observer.php',
             'support/governance/project-description-q2-sql/README.md','tests/governance/test_project_q2_sql_library.py']]
        self.assertEqual(set(self.call(paths)),set(paths))
        self.assertEqual(len(self.baseline),9303)
    def test_dual_metadata_production_pin_must_agree(self):
        path=self.add('tests/policy-new.py');self.production[path]=self.pin()
        self.assertEqual(self.call([path])[path],self.pin())
        self.production[path]=self.pin(b'conflicting')
        with self.assertRaises(ValueError):self.call([path])
    def test_missing_unknown_duplicate_and_baseline_as_new_refused(self):
        path=self.add('tests/policy-new.py')
        for paths in ([],[path,path],[path,'tests/unpinned.py'],[next(iter(self.baseline)),path]):
            with self.subTest(paths=paths),self.assertRaises(ValueError):self.call(paths)
    def test_newpath_prefix_collisions_refused(self):
        for paths in [('tests/policy-new','tests/policy-new/child.py'),('.arcconfig/child.py',),('support',)]:
            self.metadata=copy.deepcopy(self.baseline_metadata)
            for path in paths:self.add(path)
            with self.assertRaises(ValueError):self.call(list(paths))
    def test_noncanonical_and_control_paths_refused(self):
        for path in ['/absolute.py','../escape.py','tests//x.py','tests/./x.py','.git/new','tests/.GIT/new','C:x.py','tests\\x.py','tests/line\nname.py']:
            self.metadata=copy.deepcopy(self.baseline_metadata);self.add(path)
            with self.subTest(path=path),self.assertRaises(ValueError):self.call([path])
    def test_malformed_pin_types_empty_oversize_and_digest_refused(self):
        path=self.add('tests/policy-new.py')
        for row in [{'bytes':True,'sha256':'a'*64},{'bytes':0,'sha256':'a'*64},
                    {'bytes':1048577,'sha256':'a'*64},{'bytes':1,'sha256':'A'*64},{}]:
            self.metadata[path]=row
            with self.subTest(row=row),self.assertRaises(ValueError):self.call([path])
    def test_existing_historical_targets_and_rows_cannot_be_rewritten(self):
        path=self.add('tests/policy-new.py');old=next(iter(self.baseline_metadata))
        self.metadata[old]['target_sha256']='0'*64
        with self.assertRaises(ValueError):self.call([path])
        del self.metadata[old]
        with self.assertRaises(ValueError):self.call([path])
    def test_registry_self_is_authenticated_only_by_external_current_pin(self):
        raw=b'{"new_paths":[]}';name=self.policy['REGISTRY'];self.metadata[name]=self.pin(raw)
        self.assertEqual(self.policy['_authenticate_registry'](raw,self.metadata),self.pin(raw))
        with self.assertRaises(ValueError):self.policy['_authenticate_registry'](raw+b' ',self.metadata)
        with self.assertRaises(ValueError):self.policy['_authenticate_registry'](b'{"self_hash":"forged"}',self.metadata)
        del self.metadata[name]
        with self.assertRaises(ValueError):self.policy['_authenticate_registry'](raw,self.metadata)
    def test_registry_duplicate_fields_refused(self):
        with self.assertRaises(ValueError):json.loads('{"new_paths":[],"new_paths":[]}',object_pairs_hook=self.policy['_registry_object'])
    def test_real_regular_new_leaf_exact_mode_bytes_and_parent_alias(self):
        raw=b'pure-policy file';pin=self.pin(raw)
        with tempfile.TemporaryDirectory(dir=canonical_temp_base())as d:
            root=Path(d);(root/'real').mkdir();path=root/'real/new.py';path.write_bytes(raw);path.chmod(0o644)
            self.assertEqual(self.policy['_regular_new_source'](root,'real/new.py',pin)[0],raw)
            (root/'alias').symlink_to(root/'real',target_is_directory=True)
            with self.assertRaises(ValueError):self.policy['_regular_new_source'](root,'alias/new.py',pin)
            for mode in (0o755,0o1644,0o2644,0o4644):
                path.chmod(mode);actual=stat.S_IMODE(path.lstat().st_mode)
                if actual==mode:
                    with self.subTest(mode=mode),self.assertRaises(ValueError):self.policy['_regular_new_source'](root,'real/new.py',pin)
                else:
                    # Some hosts strip setid. Do not claim nonexistent bits were rejected.
                    self.assertIn(mode,(0o2644,0o4644));self.assertEqual(actual,0o644)
                    self.assertEqual(self.policy['_regular_new_source'](root,'real/new.py',pin)[0],raw)
            path.chmod(0o644);path.write_bytes(raw+b'unknown')
            with self.assertRaises(ValueError):self.policy['_regular_new_source'](root,'real/new.py',pin)
    def test_real_symlink_hardlink_directory_and_fifo_refused(self):
        raw=b'pure-policy file';pin=self.pin(raw)
        with tempfile.TemporaryDirectory(dir=canonical_temp_base())as d:
            root=Path(d);sourcefile=root/'original';sourcefile.write_bytes(raw);sourcefile.chmod(0o644)
            (root/'alias').symlink_to(sourcefile);os.link(sourcefile,root/'hard');(root/'dir').mkdir();os.mkfifo(root/'fifo')
            for path in ('alias','hard','dir','fifo'):
                with self.subTest(path=path),self.assertRaises(ValueError):self.policy['_regular_new_source'](root,path,pin)
    def test_setid_stat_faults_refused_without_claiming_kernel_preserved_bits(self):
        from unittest.mock import patch
        raw=b'pure-policy file';pin=self.pin(raw)
        with tempfile.TemporaryDirectory(dir=canonical_temp_base()) as d:
            root=Path(d);path=root/'new.py';path.write_bytes(raw);path.chmod(0o644);original=os.lstat
            for mode in (0o2644,0o4644):
                def fault(target,*args,**kwargs):
                    info=original(target,*args,**kwargs)
                    if Path(target)==path:
                        values=list(info);values[0]=stat.S_IFREG|mode;return os.stat_result(values)
                    return info
                with patch.object(os,'lstat',side_effect=fault):
                    with self.subTest(mode=mode),self.assertRaises(ValueError):self.policy['_regular_new_source'](root,'new.py',pin)
    def test_existing_successor_needs_exact_known_predecessor(self):
        name='.arcconfig';old=self.pin(b'old');current=self.pin(b'new')
        function=self.policy['_successor_policy']
        self.assertEqual(function({name:{'baseline':old,'current':current}},{name:old},self.baseline,{})[name],current)
        for entries,baseline in [({},None),({name:old},None),({name:old},self.pin(b'wrong'))]:
            with self.subTest(entries=entries),self.assertRaises(ValueError):function({name:{'baseline':baseline,'current':current}},entries,self.baseline,{})
    def test_unknown_successor_or_registry_recursive_selfpin_refused(self):
        function=self.policy['_successor_policy'];name='tests/new.py';pin=self.pin()
        for path in (name,self.policy['REGISTRY']):
            with self.assertRaises(ValueError):function({path:{'baseline':None,'current':pin}},{},self.baseline,{})
        self.assertEqual(function({name:{'baseline':None,'current':pin}},{},self.baseline,{name:pin})[name],pin)
    def test_entry_orders_real_full_guard_before_authenticated_registry_and_policy(self):
        tree=ast.parse((ROOT/'scripts/governance/source_operation_snapshot.py').read_bytes())
        cls=next(n for n in tree.body if isinstance(n,ast.ClassDef)and n.name=='SourceOperation')
        entry=next(n for n in cls.body if isinstance(n,ast.FunctionDef)and n.name=='__enter__')
        calls={}
        for n in ast.walk(entry):
            if isinstance(n,ast.Call):calls.setdefault(ast.unparse(n.func),[]).append(n.lineno)
        self.assertLess(min(calls['admission.assert_current_recipe']),min(calls['_authenticate_registry']))
        self.assertLess(min(calls['_authenticate_registry']),min(calls['_new_path_policy']))

class CurrentSourceOperation(unittest.TestCase):
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
        with source.open_source_operation(self.producer) as operation:
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
                with source.open_source_operation(self.producer) as operation:
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
                with source.open_source_operation(self.producer) as operation:
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
                with source.open_source_operation(self.producer) as operation:
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
                operation=source.open_source_operation(self.producer);operation.__enter__()
                def fault(path,*args,**kwargs):
                    info=original(path,*args,**kwargs)
                    if Path(path)==parent:
                        values=list(info);values[field]+=1;return os.stat_result(values)
                    return info
                with patch.object(source.os,'lstat',side_effect=fault):
                    with self.assertRaises(ValueError):operation.__exit__(None,None,None)
                with source.open_source_operation(self.producer) as fresh:fresh.require(self.producer)

    def test_benign_parent_activity_is_not_directory_timestamp_identity(self):
        sibling=self.producer.parent/'benign-unrelated-file'
        try:
            with source.open_source_operation(self.producer) as operation:
                sibling.write_bytes(b'non-source sibling activity')
                self.assertEqual(len(access.SQL_obligations(self.producer,_source_operation=operation)),8)
        finally:
            if sibling.exists():sibling.unlink()

    def test_actual_current_graph_two_families_and_closed_scope(self):
        with source.open_source_operation() as operation:
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
        with source.open_source_operation() as operation:
            for name in ('code_root','producer_root','coordinator','worker_terminal','calendar_delta'):
                with self.assertRaises(AttributeError):setattr(operation,name,True)
            operation.require(ROOT)

    def test_root_roles_are_distinct_and_explicit(self):
        with source.open_source_operation(self.producer) as operation:
            self.assertEqual(operation.code_root,ROOT)
            self.assertEqual(operation.producer_root,self.producer)
            self.assertEqual(len(access.SQL_obligations(self.producer,_source_operation=operation)),8)
            self.assertEqual(len(assessment.summary('a'*40,self.producer,_source_operation=operation)['executed_case_obligations']),8)
            with self.assertRaises(ValueError):access.SQL_obligations(ROOT,_source_operation=operation)
            with self.assertRaises(ValueError):source.require_operation(operation,self.producer.parent)

    def test_unknown_source_reader_and_stage_refused(self):
        with source.open_source_operation() as operation:
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
            with source.open_source_operation() as operation:
                self.assertEqual(len(access.SQL_obligations(_source_operation=operation)),8)

    def test_module_constants_immutable_and_replacement_refused_at_exit(self):
        with source.open_source_operation() as operation:
            module=operation._modules['recovery_oauth_code_source_delta']
            with self.assertRaises(TypeError):module.SOURCE_INPUTS['unknown']='x'
        with self.assertRaises(ValueError):
            with source.open_source_operation() as operation:
                operation._modules['recovery_oauth_code_source_delta'].SOURCE_INPUTS={}

    def test_new_eligible_leaf_refused_before_entry(self):
        target=self.producer/'unknown.py';target.write_bytes(b'unknown')
        try:
            with self.assertRaises(ValueError):
                with source.open_source_operation(self.producer):pass
        finally:target.unlink()

    def test_new_eligible_leaf_mid_operation_refuses_exit(self):
        target=self.producer/'unknown.py'
        try:
            with self.assertRaises(ValueError):
                with source.open_source_operation(self.producer):target.write_bytes(b'unknown')
        finally:
            if target.exists():target.unlink()

    def test_actual_source_mutation_refuses_exit(self):
        name='support/governance/recovery/oauth-access-cases.php';target=self.producer/name;old=target.read_bytes()
        try:
            with self.assertRaises(ValueError):
                with source.open_source_operation(self.producer) as operation:
                    self.assertEqual(len(access.SQL_obligations(self.producer,_source_operation=operation)),8)
                    target.write_bytes(old+b'\n// unreviewed\n')
        finally:target.write_bytes(old)

    def test_unknown_metadata_and_mixed_production_refused(self):
        name='support/governance/project-description-ci/metadata-lock.json'
        with self.changed(name,(self.producer/name).read_bytes()+b'\nunknown'):
            with self.assertRaises(ValueError):
                with source.open_source_operation(self.producer):pass
        snapshot=admission.description.current_snapshot(self.producer)
        prior=admission.description.reconstruct_main(snapshot)
        name='src/__phutil_library_map__.php'
        with self.changed(name,prior[name]):
            with self.assertRaises(ValueError):
                with source.open_source_operation(self.producer):pass

    def test_symlink_and_mode_change_refused(self):
        target=self.producer/'support/governance/recovery/oauth-access-cases.php';old=target.read_bytes();mode=target.stat().st_mode
        try:
            with self.assertRaises(ValueError):
                with source.open_source_operation(self.producer):target.chmod(0o666)
        finally:target.chmod(mode & 0o777)
        backup=Path(self.temp.name)/'original.php';target.rename(backup)
        try:
            target.symlink_to(backup)
            with self.assertRaises(ValueError):
                with source.open_source_operation(self.producer):pass
        finally:target.unlink();backup.rename(target)
        self.assertEqual(target.read_bytes(),old)

    def test_thread_and_task_inherited_scope_refused(self):
        with source.open_source_operation() as operation:
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
        with source.open_source_operation() as operation:
            outcomes=[]
            def other_thread():
                try:operation.__exit__(None,None,None)
                except ValueError:outcomes.append(True)
            thread=threading.Thread(target=other_thread);thread.start();thread.join()
            self.assertEqual(outcomes,[True])
            self.assertEqual(len(access.SQL_obligations(_source_operation=operation)),8)
        with self.assertRaises(ValueError):operation.__exit__(None,None,None)
        with source.open_source_operation() as fresh:fresh.require()

    def test_public_nested_operation_refused_private_nested_allowed(self):
        with source.open_source_operation() as operation:
            self.assertEqual(len(access.summary('a'*40,_source_operation=operation)['executed_case_obligations']),8)
            with self.assertRaises(ValueError):
                with source.open_source_operation():pass

    def test_exception_preserved_and_next_operation_fresh(self):
        with self.assertRaisesRegex(RuntimeError,'synthetic-primary'):
            with source.open_source_operation(self.producer):raise RuntimeError('synthetic-primary')
        with source.open_source_operation(self.producer) as operation:operation.require(self.producer)
        name='support/governance/recovery/oauth-access-cases.php'
        with self.changed(name,(self.producer/name).read_bytes()+b'\nunknown'):
            with self.assertRaises(ValueError):
                with source.open_source_operation(self.producer):pass

    def test_exception_plus_source_mutation_keeps_fixed_exit_note(self):
        target=self.producer/'support/governance/recovery/oauth-access-cases.php';old=target.read_bytes()
        try:
            with self.assertRaisesRegex(RuntimeError,'synthetic-primary') as caught:
                with source.open_source_operation(self.producer):
                    target.write_bytes(old+b'unknown');raise RuntimeError('synthetic-primary')
            self.assertEqual(caught.exception.__notes__,['source_operation_exit_refused'])
        finally:target.write_bytes(old)



class AssessmentTopLevelOperation(unittest.TestCase):
    setUpClass=classmethod(CurrentSourceOperation.setUpClass.__func__)
    tearDownClass=classmethod(CurrentSourceOperation.tearDownClass.__func__)
    # Parent setUpClass supplies a real full source copy; no admission monkeypatch.
    def test_default_current_labels_match_explicit_and_each_call_issues_fresh_operation(self):
        from unittest.mock import patch
        original=source.open_source_operation;issued=[]
        def observed(root=ROOT):
            operation=original(root);issued.append(operation);return operation
        with patch.object(source,'open_source_operation',side_effect=observed):
            first=assessment.SQL_obligations();second=assessment.SQL_obligations()
        self.assertEqual(first,second);self.assertEqual(len(first),8)
        self.assertEqual(len(issued),2);self.assertIsNot(issued[0],issued[1])
        self.assertEqual([o._state for o in issued],['closed','closed'])
        with original(ROOT)as operation:
            self.assertEqual(first,assessment.SQL_obligations(_source_operation=operation))
    def test_distinct_fixture_and_fake_global_ROOT_keep_strict_path(self):
        from unittest.mock import patch
        # A distinct complete producer remains the legacy default reader path.
        with patch.object(source,'open_source_operation',side_effect=AssertionError('unexpected fixture operation')):
            self.assertEqual(len(assessment.SQL_obligations(self.producer)),8)
            with patch.object(assessment,'ROOT',self.producer):
                self.assertEqual(len(assessment.SQL_obligations(self.producer)),8)
        # Default argument stays physical code root despite mutable public ROOT.
        with patch.object(assessment,'ROOT',self.producer):self.assertEqual(len(assessment.SQL_obligations()),8)
    def test_default_nested_refuses_but_explicit_operation_still_works(self):
        with source.open_source_operation(ROOT)as operation:
            with self.assertRaises(ValueError):assessment.SQL_obligations()
            self.assertEqual(len(assessment.SQL_obligations(_source_operation=operation)),8)
    def test_code_root_entry_failure_propagates_without_public_strict_fallback(self):
        from unittest.mock import patch
        path=ROOT/'support/governance/recovery/assessment-credential-cases.php'
        raw=path.read_bytes()
        try:
            path.write_bytes(raw+b'\n# unreviewed')
            with patch.object(assessment.code_coord,'assert_cohort',side_effect=AssertionError('fallback'))as fallback:
                with self.assertRaises(ValueError):assessment.SQL_obligations()
                fallback.assert_not_called()
        finally:path.write_bytes(raw)
    def test_exit_source_tamper_refuses_before_labels_return(self):
        from unittest.mock import patch
        original=source.SourceOperation.__exit__
        path=ROOT/'support/governance/recovery/assessment-credential-cases.php';raw=path.read_bytes()
        def corrupt_before_exit(operation,*args):
            path.write_bytes(raw+b'\n# changed at exit')
            try:return original(operation,*args)
            finally:path.write_bytes(raw)
        with patch.object(source.SourceOperation,'__exit__',corrupt_before_exit):
            with self.assertRaises(ValueError):assessment.SQL_obligations()
    def test_summary_and_obligations_keep_exact_values_without_nested_operation(self):
        labels=assessment.SQL_obligations()
        self.assertEqual(assessment.obligations(),assessment.shared.BOOTSTRAP|assessment.shared.PREPARATION|assessment.shared.CLEANUP|labels)
        plain=assessment.summary('a'*40)
        with source.open_source_operation(ROOT)as operation:
            explicit=assessment.summary('a'*40,_source_operation=operation)
        self.assertEqual(plain,explicit)

    def test_real_independent_clone_derives_its_own_code_recipe_root(self):
        import subprocess
        script='import sys;from pathlib import Path;root=Path(sys.argv[1]);sys.path.insert(0,str(root/"scripts/governance"));import check_recovery_assessment_credentials_v2 as gate;assert gate._CODE_RECIPE_ROOT==root;assert len(gate.SQL_obligations())==8;print("clone_current_pass")'
        result=subprocess.run([sys.executable,'-B','-c',script,str(self.producer)],capture_output=True,text=True,timeout=10)
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertEqual(result.stdout,'clone_current_pass\n')
    def test_minimal_fixture_preserves_original_strict_reader_and_corruption_refusal(self):
        from unittest.mock import patch
        from recovery_oauth_code_test_cohort import complete_source_cohort
        with tempfile.TemporaryDirectory(dir=canonical_temp_base())as directory:
            fixture=Path(directory)
            for name in set(assessment.SOURCE_INPUTS)|set(assessment.ADAPTER_SOURCE_INPUTS):
                target=fixture/name;target.parent.mkdir(parents=True,exist_ok=True)
                target.write_bytes((ROOT/name).read_bytes());target.chmod(0o600)
            complete_source_cohort(fixture)
            with patch.object(source,'open_source_operation',side_effect=AssertionError('unexpected minimal fixture operation')):
                self.assertEqual(len(assessment.SQL_obligations(fixture)),8)
                target=fixture/'support/governance/recovery/assessment-credential-cases.php'
                target.write_bytes(target.read_bytes()+b'\n# unknown')
                with self.assertRaises(ValueError):assessment.SQL_obligations(fixture)

if __name__=='__main__':unittest.main()

class HeldMediaPolicy(unittest.TestCase):
    """Pure fixed7/source-reader policy only; not an issued source capability."""
    @classmethod
    def setUpClass(cls):cls.policy=policy_functions()
    def fixture(self):
        import copy
        descriptor=self.policy['MEDIA_STAGE_PREDECESSOR']
        entries={p:{'bytes':v['bytes'],'sha256':v['sha256']}for p,v in descriptor['pins'].items()}
        metadata=copy.deepcopy(self.policy['_baseline_metadata']())
        return entries,metadata
    def test_exact7_native_stage_is_actual_b9_Git_and_effective_registry(self):
        import subprocess,json
        descriptor=self.policy['MEDIA_STAGE_PREDECESSOR'];ref=descriptor['revision']
        self.assertEqual(ref,'b9f16952adb7f23f5966422645a7b4598114ae44')
        self.assertEqual(subprocess.check_output(['git','-C',str(ROOT),'rev-parse',ref+'^{tree}'],text=True).strip(),descriptor['tree'])
        registry=json.loads(subprocess.check_output(['git','-C',str(ROOT),'show',ref+':'+self.policy['REGISTRY']]))
        self.assertEqual(len(descriptor['pins']),7)
        for path,pin in descriptor['pins'].items():
            raw=subprocess.check_output(['git','-C',str(ROOT),'show',ref+':'+path])
            mode,kind,blob=subprocess.check_output(['git','-C',str(ROOT),'ls-tree',ref,'--',path],text=True).split('\t',1)[0].split()
            self.assertEqual(pin,{'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'git_mode':mode,'git_blob':blob})
            effective=registry['successor_entries'][path]['current']if path in registry['successor_entries']else registry['entries'][path]
            self.assertEqual(effective,{'bytes':pin['bytes'],'sha256':pin['sha256']})
    def test_derived_baseline_and_successor_predecessors_both_allow_current_pin_capture(self):
        import copy
        entries,metadata=self.fixture();before=copy.deepcopy(entries)
        for path in self.policy['MEDIA_STAGE_PREDECESSOR']['pins']:metadata[path].update(bytes=19,sha256=hashlib.sha256(b'new reviewed source').hexdigest())
        actual=self.policy['_held_media_current_pins'](entries,metadata)
        self.assertEqual(set(actual),set(self.policy['MEDIA_STAGE_PREDECESSOR']['pins']));self.assertEqual(entries,before)
        for path,pin in actual.items():self.assertEqual(pin,{'bytes':19,'sha256':hashlib.sha256(b'new reviewed source').hexdigest()})
        # Shape is derived effective entries, independent of baseline/successor placement.
        placed={p:{'baseline':dict(v),'current':dict(v)}for p,v in entries.items()}
        self.assertEqual(actual,self.policy['_held_media_current_pins']({p:r['current']for p,r in placed.items()},metadata))
    def test_missing_changed_or_malformed_native_predecessor_refuses(self):
        import copy
        entries,metadata=self.fixture();path=next(iter(entries))
        for kind in ['missing','new-current','extra-key']:
            bad=copy.deepcopy(entries)
            if kind=='missing':bad.pop(path)
            elif kind=='new-current':bad[path]={'bytes':1,'sha256':'a'*64}
            else:bad[path]['ignored']=True
            with self.subTest(kind=kind),self.assertRaises(ValueError):self.policy['_held_media_current_pins'](bad,metadata)
    def test_missing_bad_current_rows_refuse_without_using_old_hash(self):
        import copy
        entries,metadata=self.fixture();path=next(iter(entries))
        for key,value in [('bytes',True),('bytes',0),('bytes',1048577),('sha256','bad'),('unknown',True)]:
            bad=copy.deepcopy(metadata);bad[path][key]=value
            with self.subTest(key=key,value=value),self.assertRaises(ValueError):self.policy['_held_media_current_pins'](entries,bad)
        metadata.pop(path)
        with self.assertRaises(ValueError):self.policy['_held_media_current_pins'](entries,metadata)
    def test_descriptor_revision_tree_pin_or_eighth_leaf_tamper_refuses(self):
        import copy
        from unittest.mock import patch
        entries,metadata=self.fixture()
        for field in ['revision','tree','pin','eighth']:
            changed=copy.deepcopy(self.policy['MEDIA_STAGE_PREDECESSOR'])
            if field in ['revision','tree']:changed[field]='0'*40
            elif field=='pin':changed['pins'][next(iter(changed['pins']))]['sha256']='0'*64
            else:changed['pins']['support/governance/media-session-ci/eighth.py']=dict(next(iter(changed['pins'].values())))
            with patch.dict(self.policy,{'MEDIA_STAGE_PREDECESSOR':changed}),self.subTest(field=field),self.assertRaises(ValueError):self.policy['_held_media_current_pins'](entries,metadata)
    def test_extra_media_prefix_leaf_is_never_held_and_reader_never_reads_it(self):
        from unittest.mock import patch
        entries,metadata=self.fixture();unknown='support/governance/media-session-ci/eighth.py';metadata[unknown]=dict(metadata[next(iter(entries))])
        self.assertNotIn(unknown,self.policy['_held_media_current_pins'](entries,metadata))
        with patch.object(os,'lstat',side_effect=AssertionError('unknown held path read'))as read:
            with self.assertRaises(ValueError):self.policy['_read_held_media'](ROOT,unknown,{'bytes':1,'sha256':'a'*64})
            read.assert_not_called()
    def test_real_current_bytes_use_current_pin_and_old_predecessor_cannot_validate_them(self):
        entries,metadata=self.fixture();path=next(iter(entries));raw=b'current reviewed media fixture'
        with tempfile.TemporaryDirectory(dir=canonical_temp_base())as directory:
            root=Path(directory);file=root/path;file.parent.mkdir(parents=True);file.write_bytes(raw);file.chmod(0o644)
            metadata[path].update(bytes=len(raw),sha256=hashlib.sha256(raw).hexdigest());pin=self.policy['_held_media_current_pins'](entries,metadata)[path]
            self.assertEqual(self.policy['_read_held_media'](root,path,pin)[0],raw)
            with self.assertRaises(ValueError):self.policy['_read_held_media'](root,path,entries[path])
            with self.assertRaises(ValueError):self.policy['_read_held_media'](root,path,{'bytes':len(raw),'sha256':'0'*64})
            file.chmod(0o600)
            with self.assertRaises(ValueError):self.policy['_read_held_media'](root,path,pin)
    def test_returned_mode_must_still_be_regular0644_after_precheck(self):
        from unittest.mock import patch
        import stat
        path=next(iter(self.policy['MEDIA_STAGE_PREDECESSOR']['pins']));raw=b'x';pin={'bytes':1,'sha256':hashlib.sha256(raw).hexdigest()}
        with tempfile.TemporaryDirectory(dir=canonical_temp_base())as directory:
            root=Path(directory);file=root/path;file.parent.mkdir(parents=True);file.write_bytes(raw);file.chmod(0o644)
            # Explicit reader-boundary collaborator, not real chmod-race proof.
            stamp=[0]*9;stamp[4]=stat.S_IFREG|0o600
            with patch.dict(self.policy,{'_read':lambda path:(raw,tuple(stamp))}):
                with self.assertRaises(ValueError):self.policy['_read_held_media'](root,path,pin)
    def test_existing_historical_targets_and_entry_exit_contract_remain_checked(self):
        import ast,copy
        entries,metadata=self.fixture();bad=copy.deepcopy(metadata);path=next(iter(entries));bad[path]['target_sha256']='0'*64
        with self.assertRaises(ValueError):self.policy['_check_historical_targets'](bad,metadata)
        tree=ast.parse((ROOT/'scripts/governance/source_operation_snapshot.py').read_bytes())
        cls=next(n for n in tree.body if isinstance(n,ast.ClassDef)and n.name=='SourceOperation')
        entry=next(n for n in cls.body if isinstance(n,ast.FunctionDef)and n.name=='__enter__');exit=next(n for n in cls.body if isinstance(n,ast.FunctionDef)and n.name=='__exit__')
        text=ast.unparse(entry)
        self.assertLess(text.index('admission.assert_current_recipe'),text.index('_authenticate_registry'))
        self.assertLess(text.index('_successor_policy'),text.index('_held_media_current_pins'))
        held_line=next(n.lineno for n in ast.walk(entry)if isinstance(n,ast.Call)and isinstance(n.func,ast.Name)and n.func.id=='_held_media_current_pins')
        loop_line=next(n.lineno for n in ast.walk(entry)if isinstance(n,ast.For)and ast.unparse(n.iter)=='admission.CURRENT_METADATA.items()')
        self.assertLess(held_line,loop_line)
        self.assertIn('_read_held_media',ast.unparse(exit));self.assertIn('admission.assert_current_recipe',ast.unparse(exit))
