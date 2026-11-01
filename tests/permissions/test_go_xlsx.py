import unittest
from go_xlsx import validate


def receipt():
    return {'boundarySchemaVersion':7,'goXLSXCoverage':{'engine':'go-stdlib-ooxml-v1','goVersion':'go1.27.3','workbooks':6,'assertions':48,'dataRows':[2,1,2,1,1001,1000],'thirdPartyModules':0,'softwareAcceptanceRequired':False,'productionDependenciesChanged':False}}


class GoXLSXEvidenceTests(unittest.TestCase):
    def test_complete_go_only_readability_evidence(self):self.assertTrue(validate(receipt()))
    def test_missing_family_or_scope_refused(self):
        for key in receipt()['goXLSXCoverage']:
            r=receipt();del r['goXLSXCoverage'][key]
            with self.assertRaises(ValueError):validate(r)
    def test_incomplete_rows_or_unapproved_scope_refused(self):
        for key,value in [('workbooks',5),('assertions',47),('dataRows',[2,1,2,1,1002,1000]),('softwareAcceptanceRequired',True),('thirdPartyModules',1),('productionDependenciesChanged',True)]:
            r=receipt();r['goXLSXCoverage'][key]=value
            with self.assertRaises(ValueError):validate(r)
    def test_stale_receipt_refused(self):
        r=receipt();r['boundarySchemaVersion']=6
        with self.assertRaises(ValueError):validate(r)
