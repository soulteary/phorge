import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('permission_audit', ROOT / 'scripts/permissions/audit.py')
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)
U, V = 'PHID-USER-aaaaaaaaaaaaaaaaaaaa', 'PHID-USER-bbbbbbbbbbbbbbbbbbbb'


def project(phid, parent=None, **extra):
    return dict({'id': 1, 'phid': phid, 'parentProjectPHID': parent,
                 'milestoneNumber': None, 'hasSubprojects': 0, 'isMembershipLocked': 0,
                 'spacePHID': None, 'viewPolicy': 'users', 'editPolicy': 'admin',
                 'joinPolicy': 'users'}, **extra)


def fixture():
    data = {'schemaVersion': 1, 'complete': True, 'restoredSnapshot': True,
            'snapshotSHA256': 'a' * 64, 'sourceCommit': 'b' * 40,
            'snapshotWatermark': '2026-11-01T00:00:00Z', 'allowPublic': True,
            'spaceCount': 0, 'projects': [project('p'), project('c', 'p')],
            'users': [{'phid': U, 'isAdmin': 1, 'isDisabled': 0, 'isApproved': 1},
                      {'phid': V, 'isAdmin': 0, 'isDisabled': 0, 'isApproved': 1}],
            'edges': []}
    # Unit-only oracle. Integration runs the actual native PHP rule adapter.
    data['nativePolicyTable'] = {}
    for user, logged, admin in [(U, True, True), (V, True, False), ('anonymous', False, False), ('nonmember', True, False)]:
        data['nativePolicyTable'][user] = {policy: {cap: value for cap in ('view', 'edit', 'join')} for policy, value in [('users', logged), ('admin', admin), ('no-one', False), (U, user == U), (V, user == V)]}
        data['nativePolicyTable'][user]['public'] = {'view': True, 'edit': logged, 'join': logged}
    return data


def comparison(data=None):
    return audit.Comparison(data or fixture(), b'x' * 32)


class PermissionDifferenceTest(unittest.TestCase):
    def test_independent_view_reports_new_access_through_hidden_parent(self):
        f = fixture(); f['projects'][0]['viewPolicy'] = 'no-one'
        f['projects'][1]['viewPolicy'] = 'public'
        c = comparison(f)
        self.assertFalse(c.evaluate('c', V, 'native-v1', 'view'))
        self.assertTrue(c.evaluate('c', V, 'independent-view-v1', 'view'))
        self.assertGreater(c.run('independent-view-v1')[0]['counts']['view']['newly-granted'], 0)

    def test_explicit_edit_removes_parent_grant_but_keeps_child_grant(self):
        f = fixture(); f['projects'][0]['editPolicy'] = 'users'
        f['projects'][1]['editPolicy'] = 'no-one'
        c = comparison(f)
        self.assertTrue(c.evaluate('c', V, 'native-v1', 'edit'))
        self.assertFalse(c.evaluate('c', V, 'explicit-edit-v1', 'edit'))
        f['projects'][1]['editPolicy'] = V
        self.assertTrue(comparison(f).evaluate('c', V, 'explicit-edit-v1', 'edit'))

    def test_m07_parent_direct_member_absent_from_materialized_is_new(self):
        f = fixture(); f['edges'] = [{'src': 'p', 'type': 13, 'dst': V}]
        result, manifest = comparison(f).run('self-descendants-v1')
        self.assertGreater(result['counts']['member-index']['newly-granted'], 0)
        self.assertEqual(result['ambiguousHistoricalDirectEdges'], 1)
        self.assertEqual(len(manifest['ambiguities']), 1)
        self.assertEqual(f['edges'][0]['dst'], V)

    def test_direct_only_can_remove_descendant_aggregation(self):
        f = fixture(); f['edges'] = [{'src': 'p', 'type': 60, 'dst': V}, {'src': 'c', 'type': 13, 'dst': V}]
        c = comparison(f)
        self.assertTrue(c.evaluate('p', V, 'native-v1', 'member-index'))
        self.assertFalse(c.evaluate('p', V, 'direct-members-v1', 'member-index'))
        self.assertTrue(c.evaluate('p', V, 'self-descendants-v1', 'member-index'))

    def test_milestone_inherits_parent_in_all_models(self):
        f = fixture(); f['projects'].append(project('m', 'c', milestoneNumber=1))
        c = comparison(f)
        for model in audit.MODELS:
            for cap in ('view', 'edit', 'join', 'member-index'):
                self.assertEqual(c.evaluate('c', V, model, cap), c.evaluate('m', V, model, cap))
            self.assertFalse(c.evaluate('m', V, model, 'leave'))

    def test_ancestry_cycle_and_missing_parent_are_unknown(self):
        for parent in ('c', 'missing'):
            f = fixture(); f['projects'][0]['parentProjectPHID'] = parent
            summary, _ = comparison(f).run('combined-v1')
            self.assertFalse(summary['evaluationComplete'])
            self.assertTrue(summary['integrityIssues'])

    def test_depth_budget(self):
        f = fixture(); f['projects'] = [project(str(i), str(i - 1) if i else None) for i in range(66)]
        c = comparison(f)
        self.assertIsNone(c.evaluate('65', U, 'native-v1', 'view'))

    def test_orphan_edges_block_complete(self):
        for src, dst in [('missing', V), ('p', 'missing')]:
            f = fixture(); f['edges'] = [{'src': src, 'type': 13, 'dst': dst}]
            self.assertFalse(comparison(f).run('combined-v1')[0]['evaluationComplete'])

    def test_disabled_and_unapproved_are_denied_without_creator_promotion(self):
        for field in ('isDisabled', 'isApproved'):
            f = fixture(); f['users'][0][field] = int(field == 'isDisabled')
            c = comparison(f)
            self.assertFalse(c.evaluate('c', U, 'combined-v1', 'edit'))
            self.assertFalse(c.evaluate('c', U, 'combined-v1', 'view'))

    def test_disabled_index_presence_is_data_not_an_access_grant(self):
        f = fixture(); f['users'][1]['isDisabled'] = 1
        f['edges'] = [{'src': 'p', 'type': 60, 'dst': V}]
        c = comparison(f)
        self.assertTrue(c.evaluate('p', V, 'native-v1', 'member-index'))
        self.assertFalse(c.evaluate('p', V, 'native-v1', 'view'))

    def test_unknown_named_custom_project_rules_are_not_denied_as_known(self):
        for policy in ('PHID-PLCY-secret', 'PHID-NPLC-secret', 'PHID-PROJ-p', 'obj.project.members'):
            f = fixture(); f['projects'][1]['viewPolicy'] = policy
            c = comparison(f)
            self.assertIsNone(c.evaluate('c', V, 'native-v1', 'view'))
            self.assertFalse(c.run('combined-v1')[0]['evaluationComplete'])

    def test_space_and_default_space_are_unknown(self):
        for use_default in (True, False):
            f = fixture()
            if use_default: f['spaceCount'] = 1
            else: f['projects'][1]['spacePHID'] = 'PHID-SPCE-private'
            self.assertIsNone(comparison(f).evaluate('c', V, 'native-v1', 'view'))

    def test_empty_snapshot_is_not_a_zero_difference_clearance(self):
        f = fixture(); f['projects'] = []
        summary, _ = comparison(f).run('combined-v1')
        self.assertFalse(summary['evaluationComplete'])
        self.assertEqual(summary['integrityIssues']['no-comparable-project-data'], 1)
        self.assertFalse(summary['migrationReady'])

    def test_unknown_or_native_model_is_refused(self):
        for model in ('unknown', 'native-v1'):
            with self.assertRaises(audit.AuditError): comparison().run(model)

    def test_cell_and_wall_clock_budgets(self):
        c = comparison(); c.max_cells = 1
        with self.assertRaises(audit.AuditError): c.run('combined-v1')
        c = comparison(); c.deadline = 0
        with self.assertRaises(audit.AuditError): c.run('combined-v1')

    def test_repeatability_and_row_order(self):
        f = fixture(); f['edges'] = [{'src': 'p', 'type': 13, 'dst': V}]
        first = comparison(f).run('combined-v1')
        f['projects'].reverse(); f['users'].reverse(); f['edges'].reverse()
        self.assertEqual(first, comparison(f).run('combined-v1'))

    def test_subject_domain_and_routes_are_explicit(self):
        summary, _ = comparison().run('combined-v1')
        self.assertEqual(summary['subjects'], 4)
        self.assertTrue(all(v == 'not-verified' for v in summary['routeVerification'].values()))
        self.assertFalse(summary['migrationReady'])

    def test_no_identifiers_or_secret_values_in_shareable_output(self):
        f = fixture(); f['projects'][0]['name'] = 'PRIVATE-email-password-host'
        summary, manifest = comparison(f).run('combined-v1')
        text = json.dumps([summary, manifest])
        for secret in ('PRIVATE-email-password-host', U, V, 'PHID-'):
            self.assertNotIn(secret, text)
        different = audit.Comparison(f, b'y' * 32).run('combined-v1')
        f['edges'] = [{'src': 'p', 'type': 13, 'dst': V}]
        self.assertNotEqual(comparison(f).run('combined-v1')[1], audit.Comparison(f, b'y' * 32).run('combined-v1')[1])

    def test_invalid_snapshot_identity_watermark_and_completeness(self):
        for key, value in [('schemaVersion', 9), ('complete', False), ('restoredSnapshot', False), ('sourceCommit', 'main'), ('snapshotSHA256', 'bad'), ('snapshotWatermark', 'secret-host'), ('allowPublic', None)]:
            f = fixture(); f[key] = value
            with self.assertRaises(audit.AuditError): comparison(f)

    def test_duplicate_projects_users_edges_refused(self):
        for key in ('projects', 'users'):
            f = fixture(); f[key].append(copy.deepcopy(f[key][0]))
            with self.assertRaises(audit.AuditError): comparison(f)
        f = fixture(); e = {'src': 'p', 'type': 13, 'dst': V}; f['edges'] = [e, e]
        with self.assertRaises(audit.AuditError): comparison(f)

    def test_unknown_values_cannot_be_truthy_strings(self):
        f = fixture(); f['nativePolicyTable'][V]['users']['view'] = 'false'
        with self.assertRaises(audit.AuditError): comparison(f).evaluate('p', V, 'native-v1', 'view')

    def test_output_exclusive_and_private_input_guards(self):
        with tempfile.TemporaryDirectory() as d:
            path = Path(d) / 'input.json'; path.write_text('{}'); path.chmod(0o644)
            with self.assertRaises(audit.AuditError): audit.private_json(path)
            path.chmod(0o600); self.assertEqual(audit.private_json(path), {})
            link = Path(d) / 'link'; link.symlink_to(path)
            with self.assertRaises(OSError): audit.private_json(link)
            output = Path(d) / 'output'; audit.write_new(output, {'ok': True})
            self.assertEqual(output.stat().st_mode & 0o777, 0o600)
            with self.assertRaises(FileExistsError): audit.write_new(output, {'overwrite': True})
