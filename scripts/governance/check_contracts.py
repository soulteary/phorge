"""Check isolated contract bookkeeping, never certify runtime authorization."""
import argparse
import json
from pathlib import Path, PurePosixPath
import subprocess

BASELINE = '59dd12120d518d33add72cfbf4d4a8316e29cec3'
EXPECTED_RULES = {
    'V01': ('A', 'high_risk_independent_approval'),
    'V02': ('A', 'new_content_only_native_edit_preserved'),
    'V03': ('A', 'incomplete_recovery_blocks_enable'),
    'V04': ('B', 'directory_scoped_admins_only'),
    'V05': ('A', 'history_system_admins_only'),
    'V06': ('A', 'media_parent_session_nonrevival'),
    'V07': ('A', 'team_admin_requires_active_member'),
    'V08': ('A', 'native_join_unchanged'),
    'V09': ('A', 'handover_14_30_days_manual_disposition'),
    'V10': ('A', 'audit_365_days_directory_8_soft_levels'),
}
EXPECTED_AXES = dict.fromkeys((
    'subproject_visibility', 'edit_inheritance',
    'member_aggregation', 'historical_edges'), 'native')
EXPECTED_CONSTRAINTS = dict.fromkeys((
    'optional_features_enabled', 'production_permission_migration',
    'production_registration', 'new_public_domains', 'd15_selected',
    'new_revocation_sla'), False)
EXPECTED_PACKAGES = {
    'N00': ('N00.COVERAGE.v1', []),
    'N01': ('N01.ORG-SCHEMA.v1', ['N00']),
    'N02': ('N02.DIRECTORY-VIEW.v1', ['N01']),
    'N05-A': ('N05-A.CONTENT-CONTRACT.v1', ['N00']),
    'N07-A': ('N07-A.APPROVAL-CONTRACT.v1', ['N00']),
}
# This is an explicit reviewed path inventory, not a source-code safety check.
EXPECTED_SOURCES = {'PROJECT.EDIT.WEB': 'src/applications/project/controller/PhabricatorProjectEditController.php', 'PROJECT.EDIT.ENGINE': 'src/applications/project/engine/PhabricatorProjectEditEngine.php', 'PROJECT.MEMBER.ADD': 'src/applications/project/controller/PhabricatorProjectMembersAddController.php', 'PROJECT.MEMBER.REMOVE': 'src/applications/project/controller/PhabricatorProjectMembersRemoveController.php', 'PROJECT.MEMBER.EDITOR': 'src/applications/project/editor/PhabricatorProjectTransactionEditor.php', 'PROJECT.MEMBER.INVERSE': 'src/infrastructure/edges/editor/PhabricatorEdgeEditor.php', 'PROJECT.EDIT.CONDUIT': 'src/applications/project/conduit/ProjectEditConduitAPIMethod.php', 'PROJECT.CREATE.CONDUIT': 'src/applications/project/conduit/ProjectCreateConduitAPIMethod.php', 'PROJECT.POLICY.SOURCE': 'src/applications/project/storage/PhabricatorProject.php', 'PROJECT.QUERY.CONSUMER': 'src/applications/project/query/PhabricatorProjectQuery.php', 'POLICY.FILTER': 'src/applications/policy/filter/PhabricatorPolicyFilter.php', 'ACCOUNT.ENABLE.WEB': 'src/applications/people/controller/PhabricatorPeopleDisableController.php', 'ACCOUNT.ENABLE.API': 'src/applications/people/conduit/UserEditConduitAPIMethod.php', 'ACCOUNT.DISABLE.LEGACY_API': 'src/applications/people/conduit/UserDisableConduitAPIMethod.php', 'ACCOUNT.ENABLE.CLI': 'src/applications/people/management/PhabricatorPeopleManagementEnableWorkflow.php', 'ACCOUNT.ENABLE.ENGINE': 'src/applications/people/editor/PhabricatorUserEditEngine.php', 'ACCOUNT.ENABLE.TRANSACTION': 'src/applications/people/xaction/PhabricatorUserDisableTransaction.php', 'ACCOUNT.SESSION': 'src/applications/auth/engine/PhabricatorAuthSessionEngine.php', 'FILE.DATA': 'src/applications/files/controller/PhabricatorFileDataController.php', 'FILE.TRANSFORM': 'src/applications/files/controller/PhabricatorFileTransformController.php', 'FILE.ROUTES': 'src/applications/files/application/PhabricatorFilesApplication.php', 'MAIL.QUEUE': 'src/applications/metamta/PhabricatorMetaMTAWorker.php', 'MAIL.MODEL': 'src/applications/metamta/storage/PhabricatorMetaMTAMail.php', 'MAIL.ENGINE': 'src/applications/metamta/engine/PhabricatorMailEmailEngine.php', 'MAIL.ADAPTER': 'src/applications/metamta/adapter/PhabricatorMailGorgeAdapter.php', 'MAIL.HERALD': 'src/applications/metamta/herald/PhabricatorMailOutboundMailHeraldAdapter.php', 'CONTENT.TASK': 'src/applications/maniphest/editor/ManiphestEditEngine.php', 'CONTENT.WIKI': 'src/applications/phriction/editor/PhrictionTransactionEditor.php'}
EXPECTED_UNSUPPORTED = {'U%02d' % i for i in range(1, 9)}


def _require(condition, message):
    if not condition:
        raise ValueError(message)


def _by_id(rows, label):
    _require(isinstance(rows, list), label + ' must be a list')
    result = {}
    for row in rows:
        _require(isinstance(row, dict), label + ' entries must be objects')
        key = row.get('id')
        _require(isinstance(key, str) and key, label + ' ID required')
        _require(key not in result, label + ' duplicate ID: ' + key)
        result[key] = row
    return result


def _source_file(root, raw):
    _require(isinstance(raw, str), 'source path must be a string')
    path = PurePosixPath(raw)
    _require(not path.is_absolute() and '..' not in path.parts,
             'source path must be repository relative')
    _require(str(path) == raw and raw, 'source path must be normalized')
    resolved = (root / raw).resolve()
    _require(resolved.is_relative_to(root.resolve()), 'source path escapes root')
    _require(resolved.is_file(), 'missing source file: ' + raw)


def validate(data, root):
    """Validate declarative scope only; static references remain partial."""
    _require(isinstance(data, dict), 'contract must be an object')
    _require(type(data.get('schema_version')) is int and
             data['schema_version'] == 1, 'schema version drift')
    _require(data.get('baseline_revision') == BASELINE, 'baseline revision drift')
    _require(data.get('native_axes') == EXPECTED_AXES, 'native axes drift')
    constraints = data.get('constraints')
    _require(isinstance(constraints, dict) and
             set(constraints) == set(EXPECTED_CONSTRAINTS) and
             all(value is False for value in constraints.values()),
             'optional activation or production constraint drift')
    _require(data.get('production_registrations') == [],
             'production registration is prohibited')
    _require(data.get('evidence_status') ==
             'structural-check-only-not-behavioral-pass',
             'structural checks must not become behavioral passes')
    rules = _by_id(data.get('rules'), 'rules')
    _require(set(rules) == set(EXPECTED_RULES), 'selected rule inventory drift')
    for key, (selected, effect) in EXPECTED_RULES.items():
        row = rules[key]
        _require((row.get('selected'), row.get('effect')) == (selected, effect),
                 'selected branch or effect drift: ' + key)
        _require(row.get('authority') == 'user-selected-design',
                 'rule authority drift: ' + key)
    packages = _by_id(data.get('packages'), 'packages')
    _require(set(packages) == set(EXPECTED_PACKAGES), 'batch package drift')
    interfaces = []
    for key, (interface, dependencies) in EXPECTED_PACKAGES.items():
        row = packages[key]
        _require(row.get('interface_id') == interface and
                 row.get('depends_on') == dependencies,
                 'interface or dependency drift: ' + key)
        interfaces.append(row['interface_id'])
        _require(row.get('active') is False and
                 row.get('production_registration') is False,
                 'package activation or registration forbidden: ' + key)
        _require(row.get('status') == 'isolated-foundation' and
                 row.get('coverage') == 'partial',
                 'unverified package cannot be pass/complete: ' + key)
    _require(len(set(interfaces)) == len(interfaces), 'duplicate interface ID')
    sources = _by_id(data.get('sources'), 'sources')
    _require(set(sources) == set(EXPECTED_SOURCES), 'source inventory drift')
    for key, path in EXPECTED_SOURCES.items():
        row = sources[key]
        _require(row.get('path') == path, 'source reference drift: ' + key)
        _source_file(Path(root), path)
        _require(isinstance(row.get('symbol'), str) and row['symbol'] and
                 isinstance(row.get('role'), str) and row['role'],
                 'source symbol and role required: ' + key)
        _require(row.get('evidence_status') == 'static-reference-only' and
                 row.get('coverage') == 'partial' and
                 row.get('activation_blocked') is True,
                 'source references are not behavioral proof: ' + key)
    unsupported = _by_id(data.get('unsupported'), 'unsupported')
    _require(set(unsupported) == EXPECTED_UNSUPPORTED,
             'unsupported scope must not disappear')
    for key, row in unsupported.items():
        _require(row.get('status') == 'pending' and
                 row.get('blocks_activation') is True,
                 'pending coverage must block activation: ' + key)
        _require(isinstance(row.get('description'), str) and row['description'],
                 'unsupported description required: ' + key)
    return {'schema_valid': True, 'sources': len(sources),
            'packages': len(packages), 'rules': len(rules),
            'activation_blocked': True, 'runtime_certified': False}


def validate_changed_paths(paths):
    """Path-only PR guard; class-map and pure-class review remain necessary."""
    for raw in paths:
        path = PurePosixPath(raw)
        _require(not path.is_absolute() and '..' not in path.parts,
                 'changed path escapes repository')
        if raw.startswith('src/'):
            _require(raw.startswith('src/infrastructure/governance/') or
                     raw == 'src/__phutil_library_map__.php',
                     'runtime source change outside isolated scope: ' + raw)
        _require(not raw.startswith(('resources/', 'conf/', 'webroot/')),
                 'production resources/configuration change: ' + raw)


def check_worktree(root):
    def git(*args):
        return subprocess.run(['git', *args], cwd=root, check=True,
                              capture_output=True).stdout
    changed = git('diff', '--name-only', '--no-renames', '-z', BASELINE, '--')
    untracked = git('ls-files', '--others', '--exclude-standard', '-z')
    paths = sorted({x.decode('utf-8') for x in
                    (changed + untracked).split(b'\0') if x})
    validate_changed_paths(paths)
    return {'changed_paths_checked': len(paths),
            'path_guard_only': True, 'runtime_certified': False}


def _unique_json_object(pairs):
    result = {}
    for key, value in pairs:
        _require(key not in result, 'duplicate JSON key: ' + key)
        result[key] = value
    return result


def load_contract(path):
    return json.loads(Path(path).read_text(encoding='utf-8'),
                      object_pairs_hook=_unique_json_object)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check-worktree', action='store_true')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[2]
    try:
        result = validate(load_contract(
            root / 'docs/governance/batch-1-contracts.json'), root)
        if args.check_worktree:
            result['worktree'] = check_worktree(root)
    except (ValueError, OSError, subprocess.CalledProcessError) as exc:
        parser.exit(1, 'Contract validation failed: %s\n' % exc)
    print(json.dumps(result, sort_keys=True))


if __name__ == '__main__':
    main()
