import importlib.util
from pathlib import Path
import tempfile
import shutil
import unittest

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('consumer_inventory',ROOT/'scripts/permissions/inventory.py')
inventory=importlib.util.module_from_spec(spec);spec.loader.exec_module(inventory)


class ConsumerInventoryTests(unittest.TestCase):
    def test_all_literal_membership_references_have_reviewed_meaning(self):
        report=inventory.collect();self.assertGreater(report['consumerCount'],40)
        self.assertEqual(report['databaseConnectionsOpened'],0)
        self.assertFalse(report['phpBootstrapped']);self.assertFalse(report['runtimeAuthorizationCertified'])
        self.assertTrue(any(r['kind']=='mail-expansion' for r in report['consumers']))
        self.assertTrue(any(r['kind']=='calendar-policy-consumer' for r in report['consumers']))
        self.assertEqual({Path(r['path']).stem for r in report['consumers'] if r['kind']=='subject-preference-writer'},
                         {'PhabricatorProjectWatchController','PhabricatorProjectSilenceController'})
        self.assertTrue(all(r['candidateGenerationIntroduced'] is False for r in report['consumers']))

    def test_new_unreviewed_reference_fails_inventory(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);(root/'scripts/permissions').mkdir(parents=True);(root/'src').mkdir()
            (root/'scripts/permissions/consumers.json').write_text('{"patterns":"needMembers","entries":[]}')
            (root/'src/new.php').write_text('<?php $query->needMembers(true);')
            with self.assertRaisesRegex(ValueError,'references changed'):inventory.collect(root)

    def test_inactive_member_shape_references_have_separate_non_authority_reviews(self):
        report=inventory.collect()
        rows={row['path']:row for row in report['consumers']}
        expected={
            'src/infrastructure/governance/mapping/PhorgeGovernanceCapabilityMap.php':
                ('isolated-transaction-shape-classifier','classification-only'),
            'src/infrastructure/governance/mapping/__tests__/PhorgeGovernanceCapabilityMapTestCase.php':
                ('isolated-native-shape-test','unit-test-only'),
        }
        for path,(kind,operation) in expected.items():
            with self.subTest(path=path):
                row=rows[path]
                self.assertTrue(row['discovery'])
                self.assertTrue(row['matchingLines'])
                self.assertEqual(row['kind'],kind)
                self.assertEqual(row['authority'],'none')
                self.assertEqual(row['sourceOperation'],operation)
                self.assertEqual(row['reviewStatus'],'source-reviewed-not-route-certified')
                self.assertIs(row['candidateGenerationIntroduced'],False)
        self.assertFalse(report['runtimeAuthorizationCertified'])
        self.assertEqual(report['writesPerformed'],0)

    def test_governance_literal_references_cannot_be_omitted_or_hidden(self):
        import copy
        import json
        catalog=json.loads((ROOT/'scripts/permissions/consumers.json').read_text())
        paths={
            'src/infrastructure/governance/mapping/PhorgeGovernanceCapabilityMap.php',
            'src/infrastructure/governance/mapping/__tests__/PhorgeGovernanceCapabilityMapTestCase.php',
        }
        selected=[copy.deepcopy(row) for row in catalog['entries'] if row['path'] in paths]
        self.assertEqual(len(selected),2)
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);(root/'scripts/permissions').mkdir(parents=True)
            for row in selected:
                target=root/row['path'];target.parent.mkdir(parents=True,exist_ok=True)
                shutil.copyfile(ROOT/row['path'],target)
            minimal={'schemaVersion':1,'scope':'synthetic-review-regression',
                     'patterns':catalog['patterns'],'entries':selected}
            target=root/'scripts/permissions/consumers.json'
            target.write_text(json.dumps(minimal))
            report=inventory.collect(root)
            self.assertEqual(report['discoveredFiles'],2)
            for mutation in ('remove-review','disable-discovery','duplicate-review'):
                with self.subTest(mutation=mutation):
                    modified=copy.deepcopy(minimal)
                    if mutation=='remove-review':modified['entries'].pop()
                    elif mutation=='disable-discovery':modified['entries'][0]['discovery']=False
                    else:modified['entries'].append(copy.deepcopy(modified['entries'][0]))
                    target.write_text(json.dumps(modified))
                    with self.assertRaisesRegex(ValueError,'references changed|Duplicate consumer'):
                        inventory.collect(root)
            # The governance namespace is still scanned, including native tests.
            target.write_text(json.dumps(minimal))
            (root/'src/infrastructure/governance/new-unreviewed.php').write_text(
                '<?php $query->needMembers(true);')
            with self.assertRaisesRegex(ValueError,'references changed'):
                inventory.collect(root)

    def test_native_source_observer_remains_reviewed_without_grant_authority(self):
        row=next(r for r in inventory.collect()['consumers']
                 if r['path']=='src/infrastructure/governance/native/PhorgeGovernanceNativeSourceReader.php')
        self.assertTrue(row['discovery'])
        self.assertTrue(row['matchingLines'])
        self.assertEqual(row['kind'],'isolated-native-source-observer')
        self.assertEqual(row['authority'],'none')
        self.assertEqual(row['sourceOperation'],'read-only-native-source-observation')
        self.assertIs(row['candidateGenerationIntroduced'],False)
