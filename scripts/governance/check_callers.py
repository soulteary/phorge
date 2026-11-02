"""Fail closed on caller-inventory drift; never certify native permissions."""
import argparse
import hashlib
import json
from pathlib import Path
import re

BASELINE = '4f36f58a5ed658c7d39bf0a58e67f5be5ca2fb0b'
SCAN_VERSION = 1
PATTERNS = {
    'transaction_apply': r'\bapplyTransactions\s*\(',
    'edit_engine_dispatch': r'\bnewEditEngine\s*\(',
    'edge_writer': r'\b(?:addEdge|removeEdge|buildEdgeSpecs)\s*\(',
    'member_edge': r'\bPhabricatorProject(?:ProjectHasMember|MemberOfProject)EdgeType\b',
    'project_editor': r'\bPhabricatorProject(?:TransactionEditor|EditEngine)\b',
    'task_editor': r'\bManiphest(?:TransactionEditor|EditEngine)\b',
    'wiki_editor': r'\bPhriction(?:TransactionEditor|DocumentEditEngine)\b',
    'policy_transaction': r'\bTYPE_(?:VIEW|EDIT|JOIN)_POLICY\b',
    'user_enable': r'\b(?:setIsDisabled|PhabricatorUserDisableTransaction)\b',
    'modular_dispatch': r'\b(?:getModularTransactionType|newModularTransactionTypes)\s*\(',
}
RULES = {'V%02d' % i: ('B' if i == 4 else 'A') for i in range(1, 11)}
UNSUPPORTED = {'U%02d' % i for i in range(1, 9)}
SOURCE_IDS = {
    'user.account', 'project.policy', 'project.member', 'project.parent',
    'policy.named', 'space.policy', 'org.appointment', 'org.membership',
    'app.capability', 'auth.session',
}
OPERATIONS = {
    'project.description', 'task.title', 'task.description', 'wiki.title',
    'wiki.content', 'policy.replace', 'project.members', 'object.space',
    'object.relationship', 'account.enable',
}
EXPECTED_CONSUMERS = {'PROJECT.EDIT.WEB': ('src/applications/project/controller/PhabricatorProjectEditController.php',
                      'PhabricatorProjectEditController::handleRequest'),
 'PROJECT.EDIT.ENGINE': ('src/applications/project/engine/PhabricatorProjectEditEngine.php',
                         'PhabricatorProjectEditEngine'),
 'PROJECT.EDIT.CONDUIT': ('src/applications/project/conduit/ProjectEditConduitAPIMethod.php',
                          'ProjectEditConduitAPIMethod::newEditEngine'),
 'PROJECT.CREATE.CONDUIT.LEGACY': ('src/applications/project/conduit/ProjectCreateConduitAPIMethod.php',
                                   'ProjectCreateConduitAPIMethod'),
 'PROJECT.MEMBER.ADD': ('src/applications/project/controller/PhabricatorProjectMembersAddController.php',
                        'PhabricatorProjectMembersAddController::handleRequest'),
 'PROJECT.MEMBER.REMOVE': ('src/applications/project/controller/PhabricatorProjectMembersRemoveController.php',
                           'PhabricatorProjectMembersRemoveController::handleRequest'),
 'PROJECT.MEMBER.EDITOR': ('src/applications/project/editor/PhabricatorProjectTransactionEditor.php',
                           'PhabricatorProjectTransactionEditor'),
 'PROJECT.MEMBER.INVERSE': ('src/infrastructure/edges/editor/PhabricatorEdgeEditor.php',
                            'PhabricatorEdgeEditor::buildEdgeSpecs'),
 'PROJECT.MEMBER.EDGE.FORWARD': ('src/applications/project/edge/PhabricatorProjectProjectHasMemberEdgeType.php',
                                 'PhabricatorProjectProjectHasMemberEdgeType::getInverseEdgeConstant'),
 'PROJECT.MEMBER.EDGE.REVERSE': ('src/applications/project/edge/PhabricatorProjectMemberOfProjectEdgeType.php',
                                 'PhabricatorProjectMemberOfProjectEdgeType::getInverseEdgeConstant'),
 'PROJECT.MEMBER.LEGACY': ('src/applications/project/xaction/PhorgeProjectLegacyMembersTransaction.php',
                           'PhorgeProjectLegacyMembersTransaction::getTitle'),
 'PROJECT.POLICY.SOURCE': ('src/applications/project/storage/PhabricatorProject.php',
                           'PhabricatorProject'),
 'TASK.EDIT.WEB': ('src/applications/maniphest/controller/ManiphestTaskEditController.php',
                   'ManiphestTaskEditController::handleRequest'),
 'TASK.EDIT.ENGINE': ('src/applications/maniphest/editor/ManiphestEditEngine.php',
                      'ManiphestEditEngine'),
 'TASK.EDIT.EDITOR': ('src/applications/maniphest/editor/ManiphestTransactionEditor.php',
                      'ManiphestTransactionEditor'),
 'TASK.EDIT.CONDUIT': ('src/applications/maniphest/conduit/ManiphestEditConduitAPIMethod.php',
                       'ManiphestEditConduitAPIMethod::newEditEngine'),
 'TASK.EDIT.CONDUIT.LEGACY': ('src/applications/maniphest/conduit/ManiphestUpdateConduitAPIMethod.php',
                              'ManiphestUpdateConduitAPIMethod'),
 'TASK.EDIT.CONDUIT.LEGACY.BASE': ('src/applications/maniphest/conduit/ManiphestConduitAPIMethod.php',
                                   'ManiphestConduitAPIMethod'),
 'TASK.EDIT.BULK': ('src/applications/maniphest/controller/ManiphestBulkEditController.php',
                    'ManiphestBulkEditController::handleRequest'),
 'TASK.BULK.ENGINE': ('src/applications/maniphest/bulk/ManiphestTaskBulkEngine.php',
                      'ManiphestTaskBulkEngine::newEditEngine'),
 'TASK.TITLE.MODULAR': ('src/applications/maniphest/xaction/ManiphestTaskTitleTransaction.php',
                        'ManiphestTaskTitleTransaction'),
 'TASK.DESCRIPTION.MODULAR': ('src/applications/maniphest/xaction/ManiphestTaskDescriptionTransaction.php',
                              'ManiphestTaskDescriptionTransaction'),
 'WIKI.EDIT.WEB.LEGACY': ('src/applications/phriction/controller/PhrictionEditController.php',
                          'PhrictionEditController::handleRequest'),
 'WIKI.COMMENT.ENGINE': ('src/applications/phriction/controller/PhrictionEditEngineController.php',
                         'PhrictionEditEngineController::handleRequest'),
 'WIKI.EDIT.ENGINE': ('src/applications/phriction/editor/PhrictionDocumentEditEngine.php',
                      'PhrictionDocumentEditEngine'),
 'WIKI.EDIT.EDITOR': ('src/applications/phriction/editor/PhrictionTransactionEditor.php',
                      'PhrictionTransactionEditor'),
 'WIKI.EDIT.CONDUIT': ('src/applications/phriction/conduit/PhrictionDocumentEditConduitAPIMethod.php',
                       'PhrictionDocumentEditConduitAPIMethod::newEditEngine'),
 'WIKI.EDIT.CONDUIT.LEGACY': ('src/applications/phriction/conduit/PhrictionEditConduitAPIMethod.php',
                              'PhrictionEditConduitAPIMethod'),
 'WIKI.CREATE.CONDUIT.LEGACY': ('src/applications/phriction/conduit/PhrictionCreateConduitAPIMethod.php',
                                'PhrictionCreateConduitAPIMethod'),
 'WIKI.TITLE.MODULAR': ('src/applications/phriction/xaction/PhrictionDocumentTitleTransaction.php',
                        'PhrictionDocumentTitleTransaction'),
 'WIKI.CONTENT.MODULAR': ('src/applications/phriction/xaction/PhrictionDocumentContentTransaction.php',
                          'PhrictionDocumentContentTransaction'),
 'ACCOUNT.ENABLE.WEB': ('src/applications/people/controller/PhabricatorPeopleDisableController.php',
                        'PhabricatorPeopleDisableController::handleRequest'),
 'ACCOUNT.ENABLE.API': ('src/applications/people/conduit/UserEditConduitAPIMethod.php',
                        'UserEditConduitAPIMethod::newEditEngine'),
 'ACCOUNT.ENABLE.CLI': ('src/applications/people/management/PhabricatorPeopleManagementEnableWorkflow.php',
                        'PhabricatorPeopleManagementEnableWorkflow::execute'),
 'ACCOUNT.ENABLE.CLI.BASE': ('src/applications/people/management/PhabricatorPeopleManagementWorkflow.php',
                             'PhabricatorPeopleManagementWorkflow::applyTransactions'),
 'ACCOUNT.ENABLE.ENGINE': ('src/applications/people/editor/PhabricatorUserEditEngine.php',
                           'PhabricatorUserEditEngine'),
 'ACCOUNT.ENABLE.EDITOR': ('src/applications/people/editor/PhabricatorUserTransactionEditor.php',
                           'PhabricatorUserTransactionEditor'),
 'ACCOUNT.ENABLE.TRANSACTION': ('src/applications/people/xaction/PhabricatorUserDisableTransaction.php',
                                'PhabricatorUserDisableTransaction'),
 'TRANSACTION.MODULAR': ('src/applications/meta/xactions/PhabricatorApplicationTransactionType.php',
                         'PhabricatorApplicationTransactionType'),
 'TRANSACTION.LEGACY': ('src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php',
                        'PhabricatorApplicationTransactionEditor'),
 'TRANSACTION.EDIT.ENGINE': ('src/applications/transactions/editengine/PhabricatorEditEngine.php',
                             'PhabricatorEditEngine'),
 'TRANSACTION.EDIT.API': ('src/applications/transactions/editengine/PhabricatorEditEngineAPIMethod.php',
                          'PhabricatorEditEngineAPIMethod'),
 'TRANSACTION.BULK.ENGINE': ('src/applications/transactions/bulk/PhabricatorBulkEngine.php',
                             'PhabricatorBulkEngine'),
 'TRANSACTION.BULK.WORKER': ('src/infrastructure/daemon/workers/bulk/PhabricatorWorkerBulkJobTaskWorker.php',
                             'PhabricatorWorkerBulkJobTaskWorker'),
 'PROJECT.DESCRIPTION.CUSTOMFIELD': ('src/applications/project/customfield/PhabricatorProjectDescriptionField.php',
                                     'PhabricatorProjectDescriptionField::createFields'),
 'TASK.POLICY.SOURCE': ('src/applications/maniphest/storage/ManiphestTask.php',
                        'ManiphestTask'),
 'WIKI.POLICY.SOURCE': ('src/applications/phriction/storage/PhrictionDocument.php',
                        'PhrictionDocument')}
EXPECTED_LINKS = {'LINK-001': ('PROJECT.EDIT.WEB', 'PROJECT.EDIT.ENGINE', 'explicit-source-reference'),
 'LINK-002': ('PROJECT.EDIT.CONDUIT', 'PROJECT.EDIT.ENGINE', 'explicit-source-reference'),
 'LINK-003': ('PROJECT.CREATE.CONDUIT.LEGACY',
              'PROJECT.MEMBER.EDITOR',
              'explicit-source-reference'),
 'LINK-004': ('PROJECT.MEMBER.ADD', 'PROJECT.MEMBER.EDITOR', 'explicit-source-reference'),
 'LINK-005': ('PROJECT.MEMBER.REMOVE', 'PROJECT.MEMBER.EDITOR', 'explicit-source-reference'),
 'LINK-006': ('PROJECT.MEMBER.EDGE.FORWARD',
              'PROJECT.MEMBER.EDGE.REVERSE',
              'explicit-source-reference'),
 'LINK-007': ('PROJECT.MEMBER.EDGE.REVERSE',
              'PROJECT.MEMBER.EDGE.FORWARD',
              'explicit-source-reference'),
 'LINK-008': ('TASK.EDIT.WEB', 'TASK.EDIT.ENGINE', 'explicit-source-reference'),
 'LINK-009': ('TASK.EDIT.CONDUIT', 'TASK.EDIT.ENGINE', 'explicit-source-reference'),
 'LINK-010': ('TASK.EDIT.CONDUIT.LEGACY.BASE', 'TASK.EDIT.EDITOR', 'explicit-source-reference'),
 'LINK-011': ('TASK.EDIT.BULK', 'TASK.BULK.ENGINE', 'explicit-source-reference'),
 'LINK-012': ('TASK.BULK.ENGINE', 'TASK.EDIT.ENGINE', 'explicit-source-reference'),
 'LINK-013': ('WIKI.EDIT.WEB.LEGACY', 'WIKI.EDIT.EDITOR', 'explicit-source-reference'),
 'LINK-014': ('WIKI.EDIT.CONDUIT.LEGACY', 'WIKI.EDIT.EDITOR', 'explicit-source-reference'),
 'LINK-015': ('WIKI.CREATE.CONDUIT.LEGACY', 'WIKI.EDIT.EDITOR', 'explicit-source-reference'),
 'LINK-016': ('WIKI.COMMENT.ENGINE', 'WIKI.EDIT.ENGINE', 'explicit-source-reference'),
 'LINK-017': ('WIKI.EDIT.CONDUIT', 'WIKI.EDIT.ENGINE', 'explicit-source-reference'),
 'LINK-018': ('ACCOUNT.ENABLE.WEB', 'ACCOUNT.ENABLE.EDITOR', 'explicit-source-reference'),
 'LINK-019': ('ACCOUNT.ENABLE.CLI', 'ACCOUNT.ENABLE.TRANSACTION', 'explicit-source-reference'),
 'LINK-020': ('ACCOUNT.ENABLE.CLI.BASE', 'ACCOUNT.ENABLE.EDITOR', 'explicit-source-reference'),
 'LINK-021': ('ACCOUNT.ENABLE.API', 'ACCOUNT.ENABLE.ENGINE', 'explicit-source-reference'),
 'LINK-022': ('PROJECT.EDIT.ENGINE', 'TRANSACTION.EDIT.ENGINE', 'inherited-dispatch-candidate'),
 'LINK-023': ('TASK.EDIT.ENGINE', 'TRANSACTION.EDIT.ENGINE', 'inherited-dispatch-candidate'),
 'LINK-024': ('WIKI.EDIT.ENGINE', 'TRANSACTION.EDIT.ENGINE', 'inherited-dispatch-candidate'),
 'LINK-025': ('ACCOUNT.ENABLE.ENGINE',
              'TRANSACTION.EDIT.ENGINE',
              'inherited-dispatch-candidate'),
 'LINK-026': ('TASK.EDIT.CONDUIT.LEGACY',
              'TASK.EDIT.CONDUIT.LEGACY.BASE',
              'inherited-dispatch-candidate'),
 'LINK-027': ('ACCOUNT.ENABLE.CLI', 'ACCOUNT.ENABLE.CLI.BASE', 'inherited-dispatch-candidate'),
 'LINK-028': ('TASK.BULK.ENGINE', 'TRANSACTION.BULK.ENGINE', 'inherited-dispatch-candidate'),
 'LINK-029': ('TRANSACTION.BULK.ENGINE',
              'TRANSACTION.EDIT.ENGINE',
              'inherited-dispatch-candidate'),
 'LINK-030': ('PROJECT.MEMBER.EDITOR', 'TRANSACTION.LEGACY', 'shared-handler-candidate'),
 'LINK-031': ('TASK.EDIT.EDITOR', 'TRANSACTION.LEGACY', 'shared-handler-candidate'),
 'LINK-032': ('WIKI.EDIT.EDITOR', 'TRANSACTION.LEGACY', 'shared-handler-candidate'),
 'LINK-033': ('ACCOUNT.ENABLE.EDITOR', 'TRANSACTION.LEGACY', 'shared-handler-candidate'),
 'LINK-034': ('TRANSACTION.LEGACY', 'TRANSACTION.MODULAR', 'shared-handler-candidate'),
 'LINK-035': ('PROJECT.MEMBER.INVERSE',
              'PROJECT.MEMBER.EDGE.REVERSE',
              'shared-handler-candidate'),
 'LINK-036': ('TRANSACTION.BULK.WORKER', 'TRANSACTION.BULK.ENGINE', 'shared-handler-candidate'),
 'LINK-037': ('PROJECT.MEMBER.LEGACY', 'TRANSACTION.MODULAR', 'historical-interpretation-only'),
 'LINK-038': ('PROJECT.EDIT.ENGINE',
              'PROJECT.DESCRIPTION.CUSTOMFIELD',
              'explicit-source-reference'),
 'LINK-039': ('TASK.EDIT.EDITOR', 'TASK.POLICY.SOURCE', 'shared-handler-candidate'),
 'LINK-040': ('WIKI.EDIT.EDITOR', 'WIKI.POLICY.SOURCE', 'shared-handler-candidate')}
EXCLUDED = ('src/infrastructure/governance/',)
GENERATED = {'src/__phutil_library_map__.php'}


def require(condition, message):
    if not condition:
        raise ValueError(message)


def digest(value):
    payload = json.dumps(value, sort_keys=True, separators=(',', ':'),
                         ensure_ascii=False).encode('utf-8')
    return hashlib.sha256(payload).hexdigest()


def scan_sources(root):
    """Conservative textual candidates plus a frozen runtime-source snapshot.

    Regex matches include comments and declarations. No match proves a live
    call, no missing match proves absence of dynamic calls. Whole-source drift
    blocks even edits without recognized tokens until the snapshot is reviewed.
    """
    root = Path(root).resolve()
    source_root = root / 'src'
    require(source_root.is_dir(), 'missing runtime source tree')
    compiled = {key: re.compile(value) for key, value in PATTERNS.items()}
    candidates = []
    files = []
    for path in sorted(source_root.rglob('*.php')):
        relative = path.relative_to(root).as_posix()
        if (relative in GENERATED or relative.startswith(EXCLUDED) or
                '__tests__' in path.parts):
            continue
        require(path.resolve().is_relative_to(root), 'source symlink escapes root')
        payload = path.read_bytes()
        text = payload.decode('utf-8')
        file_digest = hashlib.sha256(payload).hexdigest()
        files.append([relative, file_digest])
        matches = []
        for key, pattern in compiled.items():
            hits = [text.count('\n', 0, match.start()) + 1
                    for match in pattern.finditer(text)]
            if hits:
                matches.append({'pattern': key, 'lines': hits})
        if matches:
            candidates.append({
                'id': 'CALL-' + hashlib.sha256(
                    relative.encode('utf-8')).hexdigest()[:16],
                'path': relative, 'sha256': file_digest, 'matches': matches,
            })
    return {'version': SCAN_VERSION, 'patterns': PATTERNS,
            'excluded_prefixes': list(EXCLUDED),
            'excluded_generated': sorted(GENERATED),
            'excluded_test_directory': '__tests__',
            'files_scanned': len(files), 'source_snapshot_sha256': digest(files),
            'candidates': candidates}


def by_id(rows, label):
    require(isinstance(rows, list), label + ' must be a list')
    result = {}
    for row in rows:
        require(isinstance(row, dict), label + ' object required')
        key = row.get('id')
        require(isinstance(key, str) and key, label + ' ID required')
        require(key not in result, 'duplicate ' + label + ' ID: ' + key)
        result[key] = row
    return result


def regular_reference(root, raw):
    require(isinstance(raw, str) and raw, 'source path required')
    path = Path(raw)
    require(not path.is_absolute() and '..' not in path.parts and
            path.as_posix() == raw, 'source path must be normalized relative')
    target = (Path(root) / path).resolve()
    require(target.is_relative_to(Path(root).resolve()), 'reference escapes root')
    require(target.is_file(), 'missing source path: ' + raw)
    return target


def validate(data, root, actual_scan):
    """Validate static bookkeeping; every runtime activation remains blocked."""
    require(isinstance(data, dict), 'caller inventory must be an object')
    require(type(data.get('schema_version')) is int and
            data['schema_version'] == 1, 'schema version drift')
    require(data.get('baseline_revision') == BASELINE, 'baseline drift')
    require(data.get('selected_rules') == RULES, 'selected rule drift')
    axes = data.get('native_axes')
    require(axes == dict.fromkeys((
        'subproject_visibility', 'edit_inheritance', 'member_aggregation',
        'historical_edges'), 'native'), 'native axis drift')
    require(data.get('active') is False and data.get('can_execute') is False and
            data.get('production_registration') is False,
            'runtime activation prohibited')
    require(data.get('coverage') == 'partial-static-inventory' and
            data.get('runtime_certified') is False,
            'static inventory is not behavioral certification')
    require(data.get('production_registrations') == [],
            'production registrations prohibited')
    require(data.get('source_ids') == sorted(SOURCE_IDS), 'source ID drift')
    require(data.get('operation_ids') == sorted(OPERATIONS), 'operation ID drift')
    consumers = by_id(data.get('consumers'), 'consumer')
    require(set(consumers) == set(EXPECTED_CONSUMERS), 'known consumer inventory drift')
    for key, row in consumers.items():
        require((row.get('path'), row.get('symbol')) == EXPECTED_CONSUMERS[key], 'known source/symbol drift: ' + key)
        path = regular_reference(root, row.get('path'))
        require(isinstance(row.get('symbol'), str) and row['symbol'],
                'symbol reference required: ' + key)
        require(isinstance(row.get('entry_kind'), str) and row['entry_kind'],
                'entry kind required: ' + key)
        require(set(row.get('operation_ids', [])) <= OPERATIONS and
                set(row.get('source_ids', [])) <= SOURCE_IDS,
                'unknown operation/source ID: ' + key)
        require(row.get('coverage') == 'partial' and
                row.get('activation_blocked') is True and
                row.get('evidence_status') == 'static-reference-only',
                'consumer is not behavioral proof: ' + key)
        require(row.get('sha256') == hashlib.sha256(path.read_bytes()).hexdigest(),
                'reviewed source changed: ' + key)
    links = by_id(data.get('links'), 'link')
    require(set(links) == set(EXPECTED_LINKS), 'known graph inventory drift')
    for key, row in links.items():
        require((row.get('from'), row.get('to'), row.get('kind')) == EXPECTED_LINKS[key], 'known graph topology drift: ' + key)
        require(row.get('from') in consumers and row.get('to') in consumers,
                'dangling graph link: ' + key)
        require(row.get('kind') in (
            'explicit-source-reference', 'inherited-dispatch-candidate',
            'shared-handler-candidate', 'historical-interpretation-only'),
            'unreviewed graph assertion: ' + key)
        require(row.get('runtime_verified') is False,
                'graph links must not claim runtime coverage: ' + key)
        require(isinstance(row.get('basis'), str) and row['basis'],
                'graph link basis required: ' + key)
    expected_scan = data.get('scan')
    require(expected_scan == actual_scan,
            'runtime source/candidate drift: review new, changed or removed sources')
    candidates = by_id(actual_scan['candidates'], 'scanner candidate')
    triage = by_id(data.get('triage'), 'triage')
    require(set(triage) == set(candidates),
            'each candidate needs an explicit reviewed or unsupported disposition')
    for key, row in triage.items():
        require(row.get('disposition') in ('mapped-static-only', 'unsupported'),
                'candidate cannot become complete/pass: ' + key)
        require(row.get('activation_blocked') is True,
                'triage cannot activate a caller: ' + key)
        require(isinstance(row.get('reason'), str) and row['reason'],
                'triage reason required: ' + key)
        refs = row.get('consumer_ids')
        require(isinstance(refs, list) and len(set(refs)) == len(refs) and
                set(refs) <= set(consumers), 'dangling triage consumer: ' + key)
        if row['disposition'] == 'mapped-static-only':
            require(refs and all(consumers[x]['path'] == candidates[key]['path']
                                 for x in refs), 'mapped triage path mismatch: ' + key)
        else:
            require(not refs, 'unsupported triage must not imply a mapped consumer')
    unsupported = by_id(data.get('unsupported'), 'unsupported')
    require(set(unsupported) == UNSUPPORTED, 'eight blockers must remain explicit')
    for key, row in unsupported.items():
        require(row.get('status') == 'pending' and
                row.get('blocks_activation') is True,
                'pending blocker must not become pass: ' + key)
    return {'schema_valid': True, 'consumers': len(consumers),
            'static_links': len(links), 'scanner_candidates': len(candidates),
            'unsupported_candidates': sum(x['disposition'] == 'unsupported'
                                          for x in triage.values()),
            'activation_blocked': True, 'runtime_certified': False}


def unique_object(pairs):
    obj = {}
    for key, value in pairs:
        require(key not in obj, 'duplicate JSON key: ' + key)
        obj[key] = value
    return obj


def load(path):
    return json.loads(Path(path).read_text(encoding='utf-8'),
                      object_pairs_hook=unique_object)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.parse_args()
    root = Path(__file__).resolve().parents[2]
    try:
        data = load(root / 'docs/governance/batch-2-callers.json')
        result = validate(data, root, scan_sources(root))
    except (ValueError, OSError, UnicodeError, TypeError) as error:
        parser.exit(1, 'Caller inventory validation failed: %s\n' % error)
    print(json.dumps(result, sort_keys=True))


if __name__ == '__main__':
    main()
