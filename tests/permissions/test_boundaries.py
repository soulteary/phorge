import copy
import unittest
from boundaries import validate_coverage, native_policy_denial, object_visible


def receipt():
    return {'boundarySchemaVersion':5,'boundaryChecks':[{'passed':True}],
            'transactionBoundaries':{'independentProcesses':2,'databaseLockWaitObserved':True,'rollbackAfterObjectSave':True,'bulkRevocationAtExecution':True,'bulkItemRollback':True,'staleObjectReload':True},
            'disclosureCoverage':{'selectorCells':36,'queryExportCases':4,'exportConfirmationRoutes':4,'exportDownloadRoutes':4},
            'boundaryCoverage':{
                'restored':{'canonicalMemberSlugCells':340,'attachmentSubjects':10,'unknownObjectRoutes':20,'deniedWrites':2,'authorizedWrites':2,'staleForms':0},
                'revoked':{'canonicalMemberSlugCells':340,'attachmentSubjects':10,'unknownObjectRoutes':20,'deniedWrites':3,'authorizedWrites':2,'staleForms':1},
                'cross-object':{'taskCells':60,'projectCells':140,'feedListCells':40,'defaultSpaceReuse':1}}}


class BoundaryEvidenceTests(unittest.TestCase):
    def test_native_policy_dialog_is_a_denial_even_with_http_200(self):
        self.assertTrue(native_policy_denial(200,'<div class="aphront-policy-rejection">No permission</div>'))
        self.assertTrue(native_policy_denial(403,'<div class="aphront-policy-rejection">没有权限</div>'))

    def test_generic_error_success_and_server_failure_are_not_policy_denial(self):
        self.assertFalse(native_policy_denial(200,'CSRF token incorrect'))
        self.assertFalse(native_policy_denial(200,'Project saved'))
        self.assertFalse(native_policy_denial(500,'aphront-policy-rejection'))

    def test_hidden_identifier_without_title_is_still_a_leak(self):
        self.assertFalse(object_visible(200,'hidden-object-phid','Secret title','hidden-object-phid',False))
        self.assertTrue(object_visible(404,'Not found','Secret title','hidden-object-phid',False))

    def test_positive_object_control_needs_title_and_success(self):
        self.assertFalse(object_visible(200,'object-phid','Visible title','object-phid',True))
        self.assertFalse(object_visible(500,'Visible title','Visible title','object-phid',True))
        self.assertTrue(object_visible(200,'Visible title','Visible title','object-phid',True))

    def test_complete_controlled_boundary_receipt(self):self.assertTrue(validate_coverage(receipt()))

    def test_missing_phase_or_cross_object_cannot_certify_boundaries(self):
        for phase in receipt()['boundaryCoverage']:
            data=receipt();del data['boundaryCoverage'][phase]
            with self.assertRaises(ValueError):validate_coverage(data)

    def test_missing_membership_or_positive_write_control_refused(self):
        for key in receipt()['boundaryCoverage']['restored']:
            data=receipt();data['boundaryCoverage']['restored'][key]-=1
            with self.assertRaises(ValueError):validate_coverage(data)

    def test_false_or_empty_checks_refused(self):
        for checks in ([],[{'passed':False}],[{'passed':1}]):
            data=receipt();data['boundaryChecks']=checks
            with self.assertRaises(ValueError):validate_coverage(data)

    def test_unversioned_receipt_refused(self):
        data=receipt();del data['boundarySchemaVersion']
        with self.assertRaises(ValueError):validate_coverage(data)

    def test_transaction_receipt_requires_real_lock_wait_and_both_processes(self):
        for key in receipt()['transactionBoundaries']:
            data=receipt();del data['transactionBoundaries'][key]
            with self.assertRaises(ValueError):validate_coverage(data)

    def test_disclosure_receipt_requires_selector_count_and_export_controls(self):
        for key in receipt()['disclosureCoverage']:
            data=receipt();data['disclosureCoverage'][key]-=1
            with self.assertRaises(ValueError):validate_coverage(data)
