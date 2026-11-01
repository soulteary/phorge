import copy,hashlib,unittest
from pathlib import Path
from recovery import validate


def receipt():
    return {'boundarySchemaVersion':6,'recoveryCoverage':{'xlsxWorkbooks':2,'dependencyVersion':'5.10.0','dependencyLockSHA256':hashlib.sha256((Path(__file__).parent/'xlsx/composer.lock').read_bytes()).hexdigest(),'permissionInFlight':{'member':False,'space':True},'crashBoundaries':8,'queueOwners':['native','gorge'],'gorgeTransientRetryCases':1,'serviceStopped':True,'productionConfigurationChanged':False,'legacyRunningPolicy':'manual-reconciliation-no-write','creatorReplay':'acknowledge-versioned-atomic-batch'}}


class RecoveryEvidenceTests(unittest.TestCase):
    def test_complete_scoped_evidence(self):self.assertTrue(validate(receipt()))
    def test_missing_family_or_control_refused(self):
        for key in receipt()['recoveryCoverage']:
            r=receipt();del r['recoveryCoverage'][key]
            with self.assertRaises(ValueError):validate(r)
    def test_native_or_gorge_only_is_not_dual_queue_evidence(self):
        for owner in ['native','gorge']:
            r=receipt();r['recoveryCoverage']['queueOwners']=[owner]
            with self.assertRaises(ValueError):validate(r)
    def test_changed_dependency_or_permission_boundary_refused(self):
        for key,value in [('dependencyLockSHA256','0'*64),('dependencyVersion','unlocked'),('crashBoundaries',7),('permissionInFlight',{'member':True,'space':True}),('productionConfigurationChanged',True),('serviceStopped',False)]:
            r=receipt();r['recoveryCoverage'][key]=value
            with self.assertRaises(ValueError):validate(r)
