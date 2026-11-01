"""Independent, finite native-v1 route acceptance matrix; no runtime model switch."""
import re

PROJECTS = ('visibleRoot','ordinary','milestone','privateSibling','grandparent',
            'hiddenParent','openChild','hiddenMilestone','legacyParent','legacyLeaf',
            'memberParent','memberLeaf')
SUBJECTS = ('owner','parentEditor','childEditor','member','outsider','legacy',
            'administrator','disabled','unapproved','anonymous')
PHASES = ('restored', 'revoked')
ROUTES = ('direct-url','query-list','conduit','search','feed','board')
COMMON = {'visibleRoot','ordinary','milestone','grandparent'}


def expected(role, phase):
    if role not in SUBJECTS or phase not in PHASES:
        raise ValueError('Unknown fixture identity or phase.')
    if role in ('disabled','unapproved','anonymous'):
        return {'view':set(), 'edit':set()}
    view = set(PROJECTS) if role == 'owner' else set(COMMON)
    if role == 'member':
        view |= {'memberParent','memberLeaf'}
        if phase == 'restored': view |= {'hiddenParent','openChild','hiddenMilestone'}
    edit = set()
    if role == 'owner':
        edit = set(PROJECTS) - ({'visibleRoot','ordinary','milestone'} if phase == 'restored' else set())
    elif role == 'parentEditor' and phase == 'restored':
        edit = {'visibleRoot','ordinary','milestone'}
    elif role == 'childEditor':
        edit = {'ordinary','milestone'}
    return {'view': view, 'edit': edit}


def expected_cells():
    return {(phase, role, project, route)
            for phase in PHASES for role in SUBJECTS
            for project in PROJECTS for route in ROUTES}


def validate_receipt(report):
    if report.get('baselineModel') != 'native-v1' or report.get('candidateModelsRouteVerified') != []:
        raise ValueError('Route evidence must not certify unimplemented candidates.')
    snapshots=report.get('phaseSnapshots',{})
    if set(snapshots)!=set(PHASES):
        raise ValueError('Both immutable phase snapshot identities are required.')
    for phase in PHASES:
        snapshot=snapshots[phase]
        if any(not re.fullmatch('[0-9a-f]{64}',snapshot.get(key,''))
               for key in ('snapshotSHA256','permissionStateSHA256')):
            raise ValueError('Missing snapshot or permission state identity.')
    if snapshots['restored']['snapshotSHA256']!=report.get('restoration',{}).get('snapshotSHA256'):
        raise ValueError('Restored evidence identity mismatch.')
    if snapshots['restored']['snapshotSHA256']==snapshots['revoked']['snapshotSHA256']:
        raise ValueError('Changed permission states require distinct snapshots.')
    if report.get('revocation')!={'revoked':True,'searchReindexed':False,'feedRepublished':False}:
        raise ValueError('Stale search and Feed controls were not preserved.')
    cells = report.get('matrix', [])
    identities = [(c['phase'], c['subject'], c['project'], c['route']) for c in cells]
    if len(identities) != len(set(identities)) or set(identities) != expected_cells():
        raise ValueError('Route matrix is incomplete, duplicated or invented.')
    for c in cells:
        expected_view = c['project'] in expected(c['subject'], c['phase'])['view']
        if type(c.get('status')) is not int or not 100<=c['status']<500 or (expected_view and c['status']!=200):
            raise ValueError('Invalid native HTTP outcome.')
        if c['expected'] is not expected_view or c['observed'] is not expected_view or c['passed'] is not True:
            raise ValueError('Route permission contract mismatch.')
    if report.get('migrationReady') is not False:
        raise ValueError('Route fixtures do not authorize migration.')
    return True
