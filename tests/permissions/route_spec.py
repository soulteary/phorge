"""Independent, finite native-v1 route acceptance matrix; no runtime model switch."""
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
    cells = report.get('matrix', [])
    identities = [(c['phase'], c['subject'], c['project'], c['route']) for c in cells]
    if len(identities) != len(set(identities)) or set(identities) != expected_cells():
        raise ValueError('Route matrix is incomplete, duplicated or invented.')
    for c in cells:
        expected_view = c['project'] in expected(c['subject'], c['phase'])['view']
        if c['expected'] is not expected_view or c['observed'] is not expected_view or c['passed'] is not True:
            raise ValueError('Route permission contract mismatch.')
    if report.get('migrationReady') is not False:
        raise ValueError('Route fixtures do not authorize migration.')
    return True
