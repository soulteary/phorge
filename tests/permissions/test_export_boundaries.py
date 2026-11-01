import io,unittest,zipfile
from export_boundaries import validate,workbook


def receipt():
    return {'boundarySchemaVersion':5,'exportCoverage':{'httpWorkbooks':2,'httpAssertions':15,'midExportRows':1001,'freshExportRows':1000,'alreadyLoadedRowRetained':True,'nextPageRevokedRowOmitted':True,'completedSnapshotRetainedByAuthor':True,'hierarchyAssertions':21,'dependencyScope':'isolated-explicit-preload-only','productionConfigurationChanged':False,'httpServerStopped':True}}


class ExportBoundaryTests(unittest.TestCase):
    def test_complete_native_characterization(self):self.assertTrue(validate(receipt()))
    def test_missing_scope_or_family_refused(self):
        for key in receipt()['exportCoverage']:
            r=receipt();del r['exportCoverage'][key]
            with self.assertRaises(ValueError):validate(r)
    def test_retroactive_revocation_or_live_dependency_claim_refused(self):
        for key,value in [('alreadyLoadedRowRetained',False),('nextPageRevokedRowOmitted',False),('completedSnapshotRetainedByAuthor',False),('dependencyScope','production'),('productionConfigurationChanged',True),('httpServerStopped',False),('midExportRows',1002),('freshExportRows',1001),('hierarchyAssertions',20)]:
            r=receipt();r['exportCoverage'][key]=value
            with self.assertRaises(ValueError):validate(r)
    def test_formula_xml_is_rejected(self):
        b=io.BytesIO()
        with zipfile.ZipFile(b,'w') as z:
            z.writestr('xl/sharedStrings.xml','<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>text</t></si></sst>')
            z.writestr('xl/worksheets/sheet1.xml','<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row><c><f>1+1</f><v>2</v></c></row></sheetData></worksheet>')
        with self.assertRaises(ValueError):workbook(b.getvalue())
