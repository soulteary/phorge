import copy
import unittest
from test_audit import comparison, fixture, project, U, V


class MembershipSourceTests(unittest.TestCase):
    def test_structure_is_not_origin_or_permission(self):
        data=fixture();data['edges']=[{'src':'p','type':13,'dst':U},{'src':'p','type':60,'dst':V},
                                    {'src':'c','type':13,'dst':V},{'src':'c','type':60,'dst':V}]
        before=copy.deepcopy(data);summary,restricted=comparison(data).run('self-descendants-v1')
        self.assertEqual(summary['membershipSources']['structure'],{'direct-only':1,'materialized-only':1,'both':1})
        self.assertEqual(summary['membershipSources']['historicalOriginsEstablished'],0)
        self.assertEqual(data,before)
        for row in restricted['membershipSources']:
            self.assertEqual(row['historicalOrigin'],'unknown');self.assertEqual(row['action'],'preserve-review-only')
            self.assertNotIn(row['subject'],(U,V))

    def test_lifecycle_dimensions_overlap_without_deleting_edges(self):
        data=fixture();data['users'][0].update(isDisabled=1,isApproved=0)
        data['edges']=[{'src':'p','type':13,'dst':U}]
        summary,_=comparison(data).run('direct-members-v1');source=summary['membershipSources']
        self.assertEqual(source['relations'],1)
        self.assertEqual(source['lifecycleRelations'],{'disabled':1,'unapproved':1,'active-approved':0})
        self.assertEqual(source['structure']['direct-only'],1)

    def test_orphans_not_silently_counted_as_valid_relations(self):
        data=fixture();data['edges']=[{'src':'missing','type':13,'dst':U}]
        summary,_=comparison(data).run('combined-v1')
        self.assertFalse(summary['evaluationComplete']);self.assertEqual(summary['membershipSources']['unresolvedReferences'],1)
        self.assertEqual(summary['membershipSources']['relations'],0)

    def test_same_source_inventory_for_every_candidate(self):
        data=fixture();data['edges']=[{'src':'p','type':13,'dst':U}]
        values=[comparison(data).run(model)[0]['membershipSources'] for model in ('independent-view-v1','explicit-edit-v1','self-descendants-v1','direct-members-v1','combined-v1')]
        self.assertTrue(all(v==values[0] for v in values))
