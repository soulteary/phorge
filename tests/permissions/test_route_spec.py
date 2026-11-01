import copy
import unittest
from route_spec import expected, expected_cells, validate_receipt


def receipt():
    return {'baselineModel':'native-v1','candidateModelsRouteVerified':[],
            'migrationReady':False,
            'phaseSnapshots':{phase:{'snapshotSHA256':value*64,'permissionStateSHA256':value*64}
                              for phase,value in [('restored','a'),('revoked','b')]},
            'restoration':{'snapshotSHA256':'a'*64},
            'revocation':{'revoked':True,'searchReindexed':False,'feedRepublished':False},'matrix':[
                {'phase':phase,'subject':role,'project':project,'route':route,
                 'status':200,'expected':project in expected(role,phase)['view'],
                 'observed':project in expected(role,phase)['view'],'passed':True}
                for phase,role,project,route in sorted(expected_cells())]}


class RouteEvidenceTests(unittest.TestCase):
    def test_complete_contract(self):
        self.assertEqual(len(receipt()['matrix']),1440)
        self.assertTrue(validate_receipt(receipt()))

    def test_missing_route_subject_or_phase_refused(self):
        for key,value in [('route','feed'),('subject','anonymous'),('phase','revoked')]:
            r=receipt(); r['matrix']=[c for c in r['matrix'] if c[key]!=value]
            with self.assertRaises(ValueError): validate_receipt(r)

    def test_duplicate_or_invented_identity_refused(self):
        r=receipt();r['matrix'].append(copy.deepcopy(r['matrix'][0]))
        with self.assertRaises(ValueError): validate_receipt(r)
        r=receipt();r['matrix'][0]['project']='invented'
        with self.assertRaises(ValueError): validate_receipt(r)

    def test_stale_membership_observation_refused(self):
        r=receipt()
        c=next(c for c in r['matrix'] if c['phase']=='revoked' and c['subject']=='member' and c['project']=='openChild')
        c['observed']=True
        with self.assertRaises(ValueError): validate_receipt(r)

    def test_matching_wrong_expectation_refused(self):
        r=receipt(); c=r['matrix'][0];c['expected']=not c['expected'];c['observed']=c['expected']
        with self.assertRaises(ValueError): validate_receipt(r)

    def test_candidate_or_migration_claim_refused(self):
        for key,value in [('candidateModelsRouteVerified',['combined-v1']),('baselineModel','combined-v1'),('migrationReady',True)]:
            r=receipt();r[key]=value
            with self.assertRaises(ValueError): validate_receipt(r)

    def test_snapshot_and_staleness_linkage_required(self):
        for mutate in [lambda r:r['phaseSnapshots'].pop('revoked'),
                       lambda r:r['phaseSnapshots']['revoked'].update(snapshotSHA256='a'*64),
                       lambda r:r['restoration'].update(snapshotSHA256='c'*64),
                       lambda r:r['revocation'].update(searchReindexed=True)]:
            r=receipt();mutate(r)
            with self.assertRaises(ValueError): validate_receipt(r)

    def test_server_error_or_false_positive_status_refused(self):
        for status in (500,403,'200'):
            r=receipt();next(c for c in r['matrix'] if c['expected'])['status']=status
            with self.assertRaises(ValueError): validate_receipt(r)

    def test_historical_direct_edge_does_not_grant_native_view(self):
        self.assertNotIn('legacyParent',expected('legacy','restored')['view'])
        self.assertIn('legacyParent',expected('owner','restored')['view'])

    def test_inherited_edit_requires_view(self):
        self.assertNotIn('privateSibling',expected('parentEditor','restored')['view'])
        self.assertNotIn('privateSibling',expected('parentEditor','restored')['edit'])
        self.assertIn('milestone',expected('parentEditor','restored')['edit'])
        self.assertIn('milestone',expected('owner','revoked')['edit'])
        self.assertEqual(expected('parentEditor','revoked')['edit'],set())

    def test_inactive_and_admin_are_not_omnipotent(self):
        for role in ('disabled','unapproved','anonymous'):
            for phase in ('restored','revoked'): self.assertEqual(expected(role,phase),{'view':set(),'edit':set()})
        self.assertNotIn('hiddenParent',expected('administrator','restored')['view'])

if __name__=='__main__': unittest.main()
