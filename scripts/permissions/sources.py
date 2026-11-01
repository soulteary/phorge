"""Read-only membership structure inventory; structure never proves historical intent."""
from collections import Counter


def inventory(comparison):
    structure = Counter({'direct-only': 0, 'materialized-only': 0, 'both': 0})
    lifecycle = Counter({'disabled': 0, 'unapproved': 0, 'active-approved': 0})
    restricted = []
    for project in sorted(comparison.projects):
        direct = comparison.direct[project]
        material = comparison.materialized[project]
        for subject in sorted(direct | material):
            comparison.budget()
            kind = 'both' if subject in direct and subject in material else 'direct-only' if subject in direct else 'materialized-only'
            structure[kind] += 1
            user = comparison.users[subject]
            # Lifecycle dimensions overlap: a disabled subject may also be unapproved.
            if user['isDisabled']: lifecycle['disabled'] += 1
            if not user['isApproved']: lifecycle['unapproved'] += 1
            if not user['isDisabled'] and user['isApproved']: lifecycle['active-approved'] += 1
            restricted.append({'project': comparison.pseudonym('project', project),
                               'subject': comparison.pseudonym('subject', subject),
                               'structure': kind, 'directEdgeType': 13 if subject in direct else None,
                               'materializedEdgeType': 60 if subject in material else None,
                               'isDisabled': bool(user['isDisabled']), 'isApproved': bool(user['isApproved']),
                               'historicalOrigin': 'unknown', 'action': 'preserve-review-only'})
    return {'scope': 'Observed stored relations only; neither historical provenance nor effective access.',
            'relations': sum(structure.values()), 'structure': dict(structure),
            'lifecycleRelations': dict(lifecycle),
            'unresolvedReferences': comparison.issues['orphan-membership-edge'],
            'historicalOriginsEstablished': 0, 'writesPerformed': 0}, restricted
