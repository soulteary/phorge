"""Actual bounded consumer with explicit collaborators; no SQL/snapshot proof."""
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]

class FileParentPolicy(unittest.TestCase):
    def run_case(self, case):
        p = subprocess.run(['php', str(ROOT / 'support/governance/recovery/tests/file-parent-policy-query-seam.php'), str(ROOT), case], capture_output=True, text=True, timeout=10)
        self.assertEqual(p.returncode, 0, p.stderr)
        self.assertEqual(p.stderr, '')
        data = json.loads(p.stdout)
        self.assertFalse(data['SQL_executed'])
        return data

    def test_actual_queries_are_viewer_aware_and_exact_bounded(self):
        d = self.run_case('admitted')
        self.assertIsNone(d['error'])
        r = d['result']
        self.assertRegex(r['review_binding'], r'^[a-f0-9]{64}$')
        self.assertEqual(next(iter(r['observations'].values()))['state'], 'admitted')
        self.assertEqual([q[0] for q in d['queries']], ['ManiphestTaskQuery', 'PhabricatorFileQuery'] * 2)
        self.assertEqual(d['viewer_exact'], [True] * 4)
        for key in ('same_transaction_snapshot_proved', 'durable_review_compatible', 'dependency_budget_proved', 'all_object_classes_covered', 'all_sources_complete', 'complete', 'restoration_authorized'):
            self.assertIs(r[key], False, key)
        self.assertTrue(r['execution_blocked'])
        self.assertEqual(r['review_binding'], self.run_case('admitted')['result']['review_binding'])

    def test_denied_and_unsupported_are_distinct_and_remain_blocked(self):
        for case, state in [('denied', 'denied'), ('no-parent', 'unsupported'), ('profile', 'unsupported'), ('unsupported-parent', 'unsupported'), ('deleted-parent', 'denied')]:
            d = self.run_case(case)
            self.assertIsNone(d['error'])
            self.assertEqual(next(iter(d['result']['observations'].values()))['state'], state)
            self.assertTrue(d['result']['execution_blocked'])
            if case == 'no-parent':
                self.assertEqual(d['queries'], [])
                self.assertFalse(d['result']['native_policy_queries_executed'])
        self.assertNotEqual(self.run_case('denied')['result']['review_binding'], self.run_case('admitted')['result']['review_binding'])

    def test_file_author_observation_does_not_claim_parent_admission(self):
        r = self.run_case('parent-denied')['result']
        item = next(iter(r['observations'].values()))
        self.assertEqual(item['state'], 'admitted')
        self.assertEqual(list(item['Task_parents'].values()), ['denied'])
        self.assertFalse(r['durable_review_compatible'])

    def test_account_admission_precedes_native_automatic_author_grants(self):
        for case in ('anonymous', 'omnipotent', 'disabled'):
            d = self.run_case(case)
            self.assertEqual(d['error'], 'recovery_file_parent_viewer_refused')
            self.assertIsNone(d['result'])
            self.assertEqual(d['queries'], [])

    def test_topology_and_invisible_parent_policy_mutations_stale_capture(self):
        for case in ('topology-mutation', 'policy-mutation'):
            d = self.run_case(case)
            self.assertEqual(d['error'], 'recovery_file_parent_review_stale')
            self.assertIsNone(d['result'])

    def test_query_errors_and_foreign_duplicate_stale_native_rows_fail_closed(self):
        for case, reason in [('duplicate', 'recovery_file_parent_query_result_unknown'), ('foreign', 'recovery_file_parent_query_identity_changed'), ('stale-native', 'recovery_file_parent_native_rows_changed'), ('query-error', 'original-query-error')]:
            d = self.run_case(case)
            self.assertEqual(d['error'], reason)
            self.assertIsNone(d['result'])
            self.assertEqual(d['original'], case == 'query-error')

    def test_real_native_result_decoder_accepts_only_exact_models(self):
        # Load actual native model implementations; no query/SQL substitutes.
        code = r'''ob_start();require $argv[1].'/support/governance/recovery/tests/file-source-native-controls.php';ob_end_clean();$m=new ReflectionMethod(PhorgeRecoveryFileSourceFacts::class,'exactPolicyResult');$p='PHID-TASK-'.str_repeat('t',20);$t=(new ManiphestTask())->setPHID($p);$ok=$m->invoke(null,[$t],ManiphestTask::class,$p)===$t&&$m->invoke(null,[],ManiphestTask::class,$p)===null;$refused=0;foreach([[$t,$t],[(new ManiphestTask())->setPHID('PHID-TASK-'.str_repeat('x',20))],[new PhabricatorFile()],[new stdClass()],[null]]as$rows){try{$m->invoke(null,$rows,ManiphestTask::class,$p);}catch(RuntimeException $e){$refused++;}}echo json_encode(['ok'=>$ok,'refused'=>$refused]);'''
        p = subprocess.run(['php', '-r', code, str(ROOT)], capture_output=True, text=True, timeout=10)
        self.assertEqual(p.returncode, 0, p.stderr)
        self.assertEqual(p.stderr, '')
        self.assertEqual(json.loads(p.stdout), {'ok': True, 'refused': 5})

class FileParentWireSourceRoles(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        import sys
        sys.path.insert(0, str(ROOT / 'scripts/governance'))
        import check_recovery_file_parent_policy
        cls.gate = check_recovery_file_parent_policy

    def copied_sources(self, target):
        import shutil
        for name in self.gate.SOURCE_INPUTS:
            path = target / name
            path.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(ROOT / name, path)
            path.chmod(0o644)

    def receipt(self):
        import test_recovery_partial_composition_gate as predecessor
        gate = self.gate
        data = predecessor.template()
        checks = sorted(gate.source_obligations(ROOT))
        data.update(batch='native-File-parent-policy-endpoints',
            scope='owned-native-File-parent-policy-endpoints-no-permit',
            checks=checks, check_count=len(checks), native=gate.summary('a' * 40, root=ROOT))
        data['file_service_cleanup']['binary_sha256'] = 'b' * 64
        return data

    def test_current_raw_wire_and_historical_obligation_hashes_are_distinct_roles(self):
        import hashlib
        gate = self.gate
        driver = 'support/governance/native-organization/test-native-organization-mysql.php'
        old = dict(gate.SOURCE_INPUTS)
        raw = gate.read_source_checked(ROOT / driver)
        prior = gate.admitted_native_driver_source(ROOT, raw)
        self.assertNotEqual(raw, prior)
        self.assertEqual(hashlib.sha256(prior).hexdigest(), old[driver])
        summary = gate.summary('a' * 40, root=ROOT)
        expected = dict(old)
        expected[driver] = hashlib.sha256(raw).hexdigest()
        self.assertEqual(summary['source_inputs_sha256'], expected)
        self.assertEqual(gate.SOURCE_INPUTS, old)
        self.assertEqual(len(gate.source_obligations(ROOT)), 16)

    def test_real_historical_driver_root_keeps_original_pin_and_explicit_root(self):
        import hashlib, tempfile
        gate = self.gate
        driver = 'support/governance/native-organization/test-native-organization-mysql.php'
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory).resolve()
            self.copied_sources(root)
            historical = subprocess.check_output(['git', '--no-replace-objects', '-C', str(ROOT),
                'show', '20b138b4cd9a2cf61522613b4a8e5e21eca7341b:' + driver])
            self.assertEqual(hashlib.sha256(historical).hexdigest(), gate.SOURCE_INPUTS[driver])
            (root / driver).write_bytes(historical)
            observed = gate.summary('b' * 40, root=root)
            self.assertEqual(observed['source_inputs_sha256'], gate.SOURCE_INPUTS)
            self.assertEqual(len(gate.source_obligations(root)), 16)
            current = self.receipt()
            # This rejects at native wire equality, before missing restore inputs.
            with self.assertRaisesRegex(ValueError, '^Fileparent evidence refused$'):
                gate.validate(current, root=root, source_revision='a' * 40, file_digest='b' * 64)

    def test_raw_wire_receipt_accepts_current_but_never_normalized_or_self_reported_digest(self):
        import copy
        gate = self.gate
        data = self.receipt()
        self.assertTrue(gate.validate(data, root=ROOT, source_revision='a' * 40,
            file_digest='b' * 64)['passed'])
        driver = 'support/governance/native-organization/test-native-organization-mysql.php'
        for digest in (gate.SOURCE_INPUTS[driver], '0' * 64):
            bad = copy.deepcopy(data)
            bad['native']['source_inputs_sha256'][driver] = digest
            with self.assertRaisesRegex(ValueError, '^Fileparent evidence refused$'):
                gate.validate(bad, root=ROOT, source_revision='a' * 40, file_digest='b' * 64)

    def test_unknown_driver_other_source_and_unsafe_mode_refuse_before_wire_summary(self):
        import tempfile
        gate = self.gate
        driver = 'support/governance/native-organization/test-native-organization-mysql.php'
        other = 'src/applications/people/storage/PhabricatorUser.php'
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory).resolve()
            self.copied_sources(root)
            for name in (driver, other):
                path = root / name
                before = path.read_bytes()
                path.write_bytes(before + b'\nunknown unreviewed source')
                try:
                    with self.subTest(path=name), self.assertRaises(ValueError):
                        gate.summary('c' * 40, root=root)
                finally:
                    path.write_bytes(before)
            path = root / driver
            path.chmod(0o666)
            try:
                with self.assertRaises(ValueError):
                    gate.summary('c' * 40, root=root)
            finally:
                path.chmod(0o644)


class NativeDriverSourceViews(unittest.TestCase):
    """Current-role controls; historical checking bytes never grant runtime authority."""
    def module(self):
        import sys
        folder=str(ROOT/'scripts/governance')
        if folder not in sys.path:sys.path.insert(0,folder)
        import check_recovery_file_parent_policy
        return check_recovery_file_parent_policy
    def test_fixed_prior_is_actual20b_whole_PHP_not_JSON_or_executable_replay(self):
        import hashlib
        module=self.module();name=module.NATIVE_DRIVER_PATH
        raw=subprocess.check_output(['/usr/bin/git','-C',str(ROOT),'show','20b138b4cd9a2cf61522613b4a8e5e21eca7341b:'+name])
        self.assertTrue(raw.startswith(b'<?php'))
        self.assertEqual(module._native_driver_prior(raw),raw)
        self.assertEqual(module.SOURCE_INPUTS[name],hashlib.sha256(raw).hexdigest())
        for bad in (b'{}',raw+b'unknown',raw[:-1],bytearray(raw),'PHP source'):
            with self.subTest(kind=type(bad).__name__),self.assertRaises(ValueError):module._native_driver_prior(bad)
    def test_actual_current_fixture_requires_exact_forward_role(self):
        module=self.module();name=module.NATIVE_DRIVER_PATH;actual=(ROOT/name).read_bytes()
        old=subprocess.check_output(['/usr/bin/git','-C',str(ROOT),'show','20b138b4cd9a2cf61522613b4a8e5e21eca7341b:'+name])
        self.assertNotEqual(actual,old)
        self.assertEqual(module.admitted_native_driver_source(ROOT,actual),old)
        for raw in (old,actual+b'unknown'):
            with self.subTest(length=len(raw)),self.assertRaises(ValueError):module.admitted_native_driver_source(ROOT,raw)
    def test_source_parent_alias_and_untrusted_current_root_are_refused(self):
        import tempfile
        module=self.module()
        with tempfile.TemporaryDirectory()as td:
            root=Path(td).resolve();alias=root/'alias';alias.symlink_to(ROOT,target_is_directory=True)
            with self.assertRaises(ValueError):module.admitted_native_driver_source(alias,(ROOT/module.NATIVE_DRIVER_PATH).read_bytes())
            with self.assertRaises((ValueError,OSError)):module.admitted_native_driver_source(root,b'unknown')
            source=root/'driver.php';source.write_bytes(b'<?php source');source.chmod(0o644);link=root/'link.php';link.symlink_to(source)
            with self.assertRaises(OSError):module.read_source_checked(link)
    def test_all_old_literal_source_domains_and_flags_are_unchanged(self):
        import ast
        for name in ['check_recovery_file_parent_policy.py','check_private_calendar_reminders.py','check_recovery_oauth_authorization_producer_v1.py']:
            path='scripts/governance/'+name;old=ast.parse(subprocess.check_output(['/usr/bin/git','-C',str(ROOT),'show','HEAD:'+path]));new=ast.parse((ROOT/path).read_bytes())
            def literals(tree):return {ast.dump(n.targets[0],include_attributes=False):ast.dump(n.value,include_attributes=False)for n in tree.body if isinstance(n,ast.Assign)}
            a,b=literals(old),literals(new)
            for key,value in a.items():self.assertEqual(value,b[key],name)
    def test_current_forward_gate_precedes_projection_and_Auth_prior_precedes_old_chain(self):
        import ast
        source=ast.parse((ROOT/'scripts/governance/check_recovery_file_parent_policy.py').read_bytes())
        function=next(n for n in source.body if isinstance(n,ast.FunctionDef)and n.name=='admitted_native_driver_source')
        text=ast.unparse(function)
        self.assertLess(text.index('current.assert_current_recipe(root)'),text.index('current._normalize_metadata'))
        self.assertLess(text.index('current._exact(raw, pin)'),text.index('current._normalize_metadata'))
        self.assertGreater(text.rindex('current.assert_current_recipe(root)'),text.index('current._normalize_metadata'))
        source=ast.parse((ROOT/'scripts/governance/check_recovery_oauth_authorization_producer_v1.py').read_bytes());function=next(n for n in source.body if isinstance(n,ast.FunctionDef)and n.name=='SQL_obligations');text=ast.unparse(function)
        self.assertLess(text.index('prior[driver_path]'),text.index('lifecycle_delta.assert_cohort'))
        self.assertIn('else:\n        assert_historical_source(root, current)',text)


class StrictNativeDriverFD(unittest.TestCase):
    """Real owned filesystem schedules only; no SQL or source admission mocks."""
    def setUp(self):
        import sys
        if str(ROOT/'scripts/governance') not in sys.path:sys.path.insert(0,str(ROOT/'scripts/governance'))
        import check_recovery_file_parent_policy
        self.gate=check_recovery_file_parent_policy

    def fixture(self):
        import tempfile
        owner=tempfile.TemporaryDirectory();root=Path(owner.name).resolve();path=root/'driver.php'
        path.write_bytes(b'<?php owned bounded source;');path.chmod(0o644)
        return owner,root,path

    def scheduled_refusal(self,action,when='read'):
        import os
        from unittest.mock import patch
        owner,root,path=self.fixture();real_open=os.open;real_read=os.read;opened=[];fired=[]
        def open_owned(name,flags,*args,**kwargs):
            selected=Path(name)==path
            if selected and when=='before-open' and not fired:fired.append(True);action(root,path)
            fd=real_open(name,flags,*args,**kwargs)
            if selected:
                opened.append(fd)
                if when=='after-open' and not fired:fired.append(True);action(root,path)
            return fd
        def read_owned(fd,count):
            if fd in opened and when=='before-read' and not fired:fired.append(True);action(root,path)
            raw=real_read(fd,count)
            if fd in opened and when=='read' and not fired:fired.append(True);action(root,path)
            return raw
        try:
            with patch.object(self.gate.os,'open',open_owned),patch.object(self.gate.os,'read',read_owned):
                with self.assertRaises((ValueError,OSError)):self.gate.read_native_driver_checked(path)
            self.assertTrue(fired)
            for fd in opened:
                with self.assertRaises(OSError):os.fstat(fd)
        finally:
            root.chmod(0o700);owner.cleanup()

    def test_steady_owned_0644_returns_exact_bytes(self):
        owner,root,path=self.fixture()
        try:self.assertEqual(self.gate.read_native_driver_checked(path),path.read_bytes())
        finally:owner.cleanup()

    def test_0600_driver_refuses_but_original_generic_reader_still_accepts(self):
        owner,root,path=self.fixture()
        try:
            path.chmod(0o600)
            with self.assertRaises(ValueError):self.gate.read_native_driver_checked(path)
            self.assertEqual(self.gate.read_source_checked(path),path.read_bytes())
        finally:owner.cleanup()

    def test_real_0644_to_0600_before_fd_open_refuses(self):
        self.scheduled_refusal(lambda root,path:path.chmod(0o600),'before-open')

    def test_real_0644_to_0600_after_fd_open_refuses(self):
        self.scheduled_refusal(lambda root,path:path.chmod(0o600),'after-open')

    def test_real_mode_change_during_read_refuses_and_closes_fd(self):
        self.scheduled_refusal(lambda root,path:path.chmod(0o600))

    def test_real_mode_restore_during_read_still_refuses_changed_stamp(self):
        def action(root,path):path.chmod(0o600);path.chmod(0o644)
        self.scheduled_refusal(action)

    def test_real_same_length_byte_mutation_refuses(self):
        self.scheduled_refusal(lambda root,path:path.write_bytes(b'x'*len(path.read_bytes())))

    def test_real_truncation_before_read_refuses_short_read(self):
        self.scheduled_refusal(lambda root,path:path.write_bytes(b''),'before-read')

    def test_real_target_inode_replacement_refuses(self):
        import os
        def action(root,path):
            replacement=root/'replacement';replacement.write_bytes(path.read_bytes());replacement.chmod(0o644);os.replace(replacement,path)
        self.scheduled_refusal(action)

    def test_real_parent_mode_change_refuses(self):
        self.scheduled_refusal(lambda root,path:root.chmod(0o750))

    def test_source_alias_hardlink_and_fifo_refuse_without_blocking(self):
        import os
        owner,root,path=self.fixture()
        try:
            alias=root/'alias';alias.symlink_to(path)
            with self.assertRaises(OSError):self.gate.read_native_driver_checked(alias)
            hard=root/'hard';os.link(path,hard)
            with self.assertRaises(ValueError):self.gate.read_native_driver_checked(path)
            hard.unlink();fifo=root/'fifo';os.mkfifo(fifo,0o644)
            with self.assertRaises(ValueError):self.gate.read_native_driver_checked(fifo)
        finally:owner.cleanup()

    def test_empty_and_oversized_sources_refuse(self):
        owner,root,path=self.fixture()
        try:
            for raw in (b'',b'x'*(1048576+1)):
                path.write_bytes(raw)
                with self.assertRaises(ValueError):self.gate.read_native_driver_checked(path)
        finally:owner.cleanup()
