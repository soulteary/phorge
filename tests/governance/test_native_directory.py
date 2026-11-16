"""Static inventory mutation tests, not native authorization acceptance."""
import copy
import hashlib
import json
import os
import sys
import importlib.util
from pathlib import Path
import shutil
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('native_directory_checker',
    ROOT / 'scripts/governance/check_native_directory.py')
CHECKER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CHECKER)

class NativeDirectoryInventoryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        # One real current source window first. The subsequent parent fixture
        # is DATA for the unchanged CURRENT checker, never an issued source.
        sys.path.insert(0, str(ROOT / 'scripts/governance'))
        from task_inverse_test_api_v2 import owner
        import source_operation_snapshot as source
        import check_native_directory_task_source_v2 as current_gate
        def need(value):
            if value is not True:
                raise ValueError('native_directory_parent_DATA_fixture_refused')
        with owner(ROOT) as operation:
            source.require_task_operation(operation, ROOT)
            proof = current_gate.verify_in_operation(operation, ROOT)
            need(proof['current_source_entry_verified'] is True and
                 proof['source_operation_exit_verified'] is False and
                 proof['authority_proof'] is False and
                 proof['runtime_certified'] is False and
                 proof['activation_blocked'] is True)
            context = operation._task_contexts[ROOT]
            need(Path(CHECKER.__file__).resolve() == ROOT / current_gate.GATE)
            need(hashlib.sha256(context.current(current_gate.GATE)).hexdigest() ==
                 current_gate.GATE_SHA)
            manifest_raw = context.current(current_gate.MANIFEST)
            need(hashlib.sha256(manifest_raw).hexdigest() == current_gate.MANIFEST_SHA)
            need(context.parent(current_gate.MANIFEST) == manifest_raw)
            manifest = json.loads(manifest_raw, object_pairs_hook=CHECKER.unique_object)
            paths = set(CHECKER.EXPECTED_RUNTIME_FILES)
            paths.update(row['path'] for row in manifest['sources'])
            snapshot = {}
            for name in sorted(paths):
                need(type(name) is str and not Path(name).is_absolute() and
                     '..' not in Path(name).parts and Path(name).as_posix() == name)
                current = context.current(name)
                parent = context.parent(name)
                need(type(current) is bytes and type(parent) is bytes)
                snapshot[name] = parent
            for row in manifest['sources'] + manifest['scan']['files']:
                need(hashlib.sha256(snapshot[row['path']]).hexdigest() == row['sha256'])
            snapshot[current_gate.MANIFEST] = manifest_raw
        # Nothing is materialized, and no successful exit is claimed, until the
        # genuine owner has completed its actual identity/entry/exit checks.
        proof['source_operation_exit_verified'] = True
        owned = tempfile.TemporaryDirectory(prefix='native-directory-parent-DATA-')
        cls.addClassCleanup(owned.cleanup)
        fixture_root = Path(owned.name).resolve(strict=True)
        os.chmod(fixture_root, 0o700)
        for name, raw in snapshot.items():
            destination = fixture_root / name
            destination.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            mode = 0o600 if name == current_gate.MANIFEST else 0o644
            fd = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL |
                         os.O_NOFOLLOW, mode)
            os.fchmod(fd, mode)
            with os.fdopen(fd, 'wb') as stream:
                stream.write(raw)
                stream.flush()
                os.fsync(stream.fileno())
        CHECKER.validate(CHECKER.load(fixture_root / current_gate.MANIFEST), fixture_root)
        cls._legacy_fixture_root = fixture_root
        cls._current_Task_static_proof = proof
        cls._legacy_fixture_SourceOperation_granted = False

    def setUp(self):
        self.data = CHECKER.load(self._legacy_fixture_root / 'docs/governance/native-directory-callers.json')

    def reject(self, data, reason):
        with self.assertRaisesRegex(ValueError, reason):
            CHECKER.validate(data, self._legacy_fixture_root)

    def fixture(self, directory):
        root = Path(directory)
        paths = set(CHECKER.EXPECTED_RUNTIME_FILES)
        paths.update(row['path'] for row in self.data['sources'])
        for raw in paths:
            path = root / raw
            path.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(self._legacy_fixture_root / raw, path)
        return root

    def test_inventory_is_partial_not_authority_or_activation(self):
        proof = self._current_Task_static_proof
        self.assertTrue(proof['current_source_entry_verified'])
        self.assertTrue(proof['source_operation_exit_verified'])
        self.assertTrue(proof['original_parent_manifest_inventory']['passed'])
        self.assertFalse(proof['runtime_certified'])
        self.assertFalse(proof['authority_proof'])
        self.assertTrue(proof['activation_blocked'])
        self.assertFalse(proof['additional_Task_behavior_certified'])
        self.assertFalse(self._legacy_fixture_SourceOperation_granted)
        self.assertEqual(proof['original_parent_manifest_inventory']['runtime_files'], 138)
        self.assertEqual(len(proof['additional_Task_runtime_files']), 13)
        self.assertEqual(proof['current_runtime_files'], 151)
        result = CHECKER.validate(self.data, self._legacy_fixture_root)
        self.assertTrue(result['passed'])
        self.assertFalse(result['runtime_certified'])
        self.assertFalse(result['authority_proof'])
        self.assertTrue(result['activation_blocked'])

    def test_transfer_foundation_cannot_gain_runtime_approval_or_activation(self):
        for name in ('Calendar','TransferRequest','Transfer','Handover','ProjectDisposition','Outbox'):
            identifier='NATIVE.PhorgeOrganization'+name
            data=copy.deepcopy(self.data);data['sources']=[r for r in data['sources'] if r['id']!=identifier]
            self.reject(data,'source inventory drift')
            data=copy.deepcopy(self.data)
            next(r for r in data['sources'] if r['id']==identifier)['authority_proof']=True
            self.reject(data,'non-authoritative')
        data=copy.deepcopy(self.data);data['links']=[r for r in data['links'] if r['id']!='NATIVE-LINK-29']
        self.reject(data,'link inventory drift')

    def test_top_level_activation_or_authority_is_refused(self):
        for key, value in [('coverage','complete'),('activation_blocked',False),
                           ('runtime_certified',True),('authority_proof',True)]:
            data = copy.deepcopy(self.data); data[key] = value
            self.reject(data, 'cannot authorize')

    def test_source_deletion_duplicate_and_addition_are_refused(self):
        for kind in ['delete','duplicate','add']:
            data = copy.deepcopy(self.data)
            if kind == 'delete': data['sources'].pop()
            elif kind == 'duplicate': data['sources'].append(copy.deepcopy(data['sources'][0]))
            else:
                row = copy.deepcopy(data['sources'][0]); row['id'] = 'UNKNOWN';data['sources'].append(row)
            self.reject(data, 'source (IDs refused|inventory drift)')

    def test_source_cannot_be_moved_or_claim_runtime_evidence(self):
        for key, value in [('path','../foreign.php'),('symbol','Unknown'),
                           ('authority_proof',True),('activation_blocked',False),
                           ('coverage','complete'),('evidence_status','runtime-verified')]:
            data = copy.deepcopy(self.data); data['sources'][0][key] = value
            self.reject(data, '(identity drift|non-authoritative)')

    def test_hash_and_extra_fields_are_refused(self):
        data = copy.deepcopy(self.data); data['sources'][0]['sha256'] = '0'*64
        self.reject(data, 'source hash drift')
        data = copy.deepcopy(self.data); data['caller_certified'] = True
        self.reject(data, 'manifest fields')

    def test_links_cannot_be_dangling_or_behavioral_proof(self):
        for key, value in [('to_id','UNKNOWN'),('runtime_verified',True)]:
            data = copy.deepcopy(self.data);data['links'][0][key] = value
            self.reject(data, '(topology drift|not runtime proof)')

    def test_historically_excluded_media_source_mutation_is_detected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = self.fixture(directory)
            path = root / 'src/infrastructure/governance/media/PhorgeGovernanceMediaExchangeService.php'
            path.write_text(path.read_text()+'\n// changed candidate\n')
            with self.assertRaisesRegex(ValueError,'source hash drift'):
                CHECKER.validate(self.data,root)

    def test_new_runtime_file_is_not_silently_excluded(self):
        with tempfile.TemporaryDirectory() as directory:
            root = self.fixture(directory)
            (root / 'src/infrastructure/governance/NewConsumer.php').write_text('<?php\n')
            with self.assertRaisesRegex(ValueError,'new or removed runtime source'):
                CHECKER.validate(self.data,root)

    def test_duplicate_scan_row_cannot_hide_another_file(self):
        data = copy.deepcopy(self.data); data['scan']['files'][1] = data['scan']['files'][0]
        self.reject(data,'runtime source drift')

    def test_datasource_and_authenticated_controller_are_explicit_sources(self):
        ids = ('NATIVE.PhorgeOrganizationDatasource',
               'NATIVE.PhorgeOrganizationDatasourceController')
        for identity in ids:
            data = copy.deepcopy(self.data)
            data['sources'] = [row for row in data['sources'] if row['id'] != identity]
            self.reject(data, 'source inventory drift')
        data = copy.deepcopy(self.data)
        row = next(row for row in data['sources'] if row['id'] == ids[0])
        row['kind'] = 'cached-viewer-authority'
        self.reject(data, 'source identity drift')

    def test_datasource_links_cannot_skip_authenticated_request_boundary(self):
        for identity in ('NATIVE-LINK-17','NATIVE-LINK-18','NATIVE-LINK-19'):
            data = copy.deepcopy(self.data)
            row = next(row for row in data['links'] if row['id'] == identity)
            row['to_id'] = 'NATIVE.PhorgeOrganizationService'
            self.reject(data, 'link topology drift')

    def test_restricted_history_route_and_projection_are_explicit(self):
        identity='NATIVE.PhorgeOrganizationHistoryController'
        self.assertIn(identity,CHECKER.EXPECTED_SOURCES)
        data=copy.deepcopy(self.data)
        data['sources']=[row for row in data['sources'] if row['id']!=identity]
        with self.assertRaises(ValueError):CHECKER.validate(data,self._legacy_fixture_root)
        for identity in ('NATIVE-LINK-27','NATIVE-LINK-28'):
            data=copy.deepcopy(self.data)
            data['links']=[row for row in data['links'] if row['id']!=identity]
            with self.subTest(identity=identity),self.assertRaises(ValueError):CHECKER.validate(data,self._legacy_fixture_root)

    def test_fixed_tokenizer_metadata_pipeline_is_explicit(self):
        required={'TYPEAHEAD.BASE','TOKENIZER.CONTROL','CLIENT.TOKENIZER.BEHAVIOR','CLIENT.PREFAB'}
        self.assertTrue(required <= set(CHECKER.EXPECTED_SOURCES))
        for identity in required:
            data=copy.deepcopy(self.data)
            data['sources']=[row for row in data['sources'] if row['id']!=identity]
            with self.subTest(identity=identity),self.assertRaises(ValueError):
                CHECKER.validate(data,self._legacy_fixture_root)
        for identity in ('NATIVE-LINK-22','NATIVE-LINK-23','NATIVE-LINK-24','NATIVE-LINK-25'):
            data=copy.deepcopy(self.data)
            data['links']=[row for row in data['links'] if row['id']!=identity]
            with self.subTest(identity=identity),self.assertRaises(ValueError):
                CHECKER.validate(data,self._legacy_fixture_root)

    def test_issuer_dependencies_survive_explicit_directory_link_merge(self):
        expected = {
            'NATIVE-LINK-20': ('NATIVE.PhorgeGovernanceMediaExchangeService', 'NATIVE.PhorgeGovernanceMediaIssuer'),
            'NATIVE-LINK-21': ('NATIVE.PhorgeGovernanceMediaConfigOptions', 'NATIVE.PhorgeGovernanceMediaIssuer'),
        }
        for identity, pair in expected.items():
            self.assertEqual(pair, CHECKER.EXPECTED_LINKS[identity])
            data = copy.deepcopy(self.data)
            row = next(row for row in data['links'] if row['id'] == identity)
            row['runtime_verified'] = True
            self.reject(data, 'not runtime proof')
        data = copy.deepcopy(self.data)
        data['sources'] = [row for row in data['sources'] if row['id'] != 'NATIVE.PhorgeGovernanceMediaIssuer']
        self.reject(data, 'source inventory drift')

    def test_duplicate_json_keys_are_refused(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)/'manifest.json';path.write_text('{"scope":1,"scope":2}')
            with self.assertRaisesRegex(ValueError,'duplicate JSON key'):
                CHECKER.load(path)

if __name__ == '__main__':
    unittest.main()
