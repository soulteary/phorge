import copy
import unittest
from boundaries import validate_coverage


def receipt():
    return {'boundarySchemaVersion':1,'boundaryChecks':[{'passed':True}],
            'boundaryCoverage':{
                'restored':{'canonicalMemberSlugCells':340,'attachmentSubjects':10,'deniedWrites':2,'authorizedWrites':2},
                'revoked':{'canonicalMemberSlugCells':340,'attachmentSubjects':10,'deniedWrites':3,'authorizedWrites':2},
                'cross-object':{'taskCells':60,'projectCells':140,'feedListCells':40}}}


class BoundaryEvidenceTests(unittest.TestCase):
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
