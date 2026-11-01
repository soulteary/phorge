import copy
import unittest
from queue_boundaries import validate


def receipt():
    return {'boundarySchemaVersion':6,
        'queueWebCoverage':{'additionalSelectorCells':36,'formatAssertions':25,'largeExportRows':1003,'largeExportAssertions':10,'httpAssertions':3},
        'queueWorkerCoverage':{'ancestorCases':2,'tokenControls':4,'spaceSynchronizationControls':1,'retryAssertions':24,'queueOwner':'native-sql','genericDaemonsStarted':0,'inFlightAncestorSemantics':'ordinary-loaded-parent/milestone-sync-waits-child;fresh-denies'}}


class QueueEvidenceTests(unittest.TestCase):
    def test_complete_native_scope(self):self.assertTrue(validate(receipt()))

    def test_missing_execution_family_cannot_certify(self):
        for family in ('queueWebCoverage','queueWorkerCoverage'):
            for key in receipt()[family]:
                r=receipt();del r[family][key]
                with self.assertRaises(ValueError):validate(r)

    def test_reduced_cases_or_changed_queue_owner_cannot_certify(self):
        for family,key,value in [('queueWebCoverage','largeExportRows',1000),('queueWebCoverage','additionalSelectorCells',35),('queueWorkerCoverage','retryAssertions',23),('queueWorkerCoverage','queueOwner','gorge'),('queueWorkerCoverage','genericDaemonsStarted',1)]:
            r=receipt();r[family][key]=value
            with self.assertRaises(ValueError):validate(r)

    def test_old_receipt_is_not_new_queue_evidence(self):
        r=receipt();r['boundarySchemaVersion']=2
        with self.assertRaises(ValueError):validate(r)
