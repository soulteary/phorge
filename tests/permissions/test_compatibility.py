import copy
import unittest
import urllib.request
import urllib.error
import compatibility as m
from route_spec import PHASES

def receipt():
    return {'compatibilitySchemaVersion':1,'compatibilityCoverage':{p:m.expected_coverage(p) for p in PHASES},
            'compatibilityChecks':[{'name':str(i),'passed':True} for i in range(392)]}

class NativeCompatibilityReceipt(unittest.TestCase):
    def test_both_phases_and_exact_counts(self):
        self.assertTrue(m.validate(receipt()))
        self.assertEqual([m.expected_coverage(p)['warningPOST'] for p in PHASES],[5,5])
    def test_missing_failed_duplicate_or_mutated_evidence_is_rejected(self):
        for mode in ('missing','failed','duplicate','mutated'):
            d=receipt()
            if mode=='missing':d['compatibilityChecks'].pop()
            elif mode=='failed':d['compatibilityChecks'][0]['passed']=False
            elif mode=='duplicate':d['compatibilityChecks'][1]['name']='0'
            else:d['compatibilityCoverage']['restored']['stateUnchanged']=False
            with self.assertRaises(ValueError):m.validate(d)
    def test_version_phase_and_route_inventory_are_required(self):
        for mode in ('version','phase','count'):
            d=receipt()
            if mode=='version':d['compatibilitySchemaVersion']=2
            elif mode=='phase':del d['compatibilityCoverage']['revoked']
            else:d['compatibilityCoverage']['revoked']['legacyAliasGET']=39
            with self.assertRaises(ValueError):m.validate(d)
    def test_fields_and_redirect_handler_preserve_native_csrf(self):
        parser=m.Fields();parser.feed('<input name="__csrf__" value="token"><input name="disabled" disabled value="x">')
        self.assertEqual(parser.fields,{'__csrf__':'token'})
        self.assertIsNone(m.NoRedirect().redirect_request(None,None,302,'Found',{},'/local/'))
        with self.assertRaises(urllib.error.HTTPError) as raised:
            m.NoRedirect().http_error_302(urllib.request.Request('http://owned.invalid/x'),None,302,'Found',{'Location':'/local/'})
        self.assertEqual(raised.exception.code,302)
        self.assertEqual(raised.exception.headers['Location'],'/local/')
