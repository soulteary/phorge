"""Static inventory mutation tests, not native authorization acceptance."""
import copy
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
    def setUp(self):
        self.data = CHECKER.load(ROOT / 'docs/governance/native-directory-callers.json')

    def reject(self, data, reason):
        with self.assertRaisesRegex(ValueError, reason):
            CHECKER.validate(data, ROOT)

    def fixture(self, directory):
        root = Path(directory)
        paths = set(CHECKER.EXPECTED_RUNTIME_FILES)
        paths.update(row['path'] for row in self.data['sources'])
        for raw in paths:
            path = root / raw
            path.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / raw, path)
        return root

    def test_inventory_is_partial_not_authority_or_activation(self):
        result = CHECKER.validate(self.data, ROOT)
        self.assertTrue(result['passed'])
        self.assertFalse(result['runtime_certified'])
        self.assertFalse(result['authority_proof'])
        self.assertTrue(result['activation_blocked'])

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
