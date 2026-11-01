#!/usr/bin/env python3
"""Offline, bounded B04/M07 comparison. Never opens a database or edits edges."""
import argparse
from collections import Counter
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import subprocess
import stat

import time

import importlib.util
_source_spec = importlib.util.spec_from_file_location('permission_membership_sources', Path(__file__).with_name('sources.py'))
_source_module = importlib.util.module_from_spec(_source_spec)
_source_spec.loader.exec_module(_source_module)
membership_inventory = _source_module.inventory

MODELS = {
    'native-v1': (False, False, 'materialized'),
    'independent-view-v1': (True, False, 'materialized'),
    'explicit-edit-v1': (False, True, 'materialized'),
    'self-descendants-v1': (False, False, 'descendants'),
    'direct-members-v1': (False, False, 'direct'),
    'combined-v1': (True, True, 'descendants'),
}
CAPABILITIES = ('view', 'edit', 'join', 'leave', 'member-index')
ROUTES = ('direct-url', 'query-list', 'conduit', 'search', 'feed-backlink', 'board-history')


class AuditError(ValueError):
    """Messages are fixed codes; never disclose database values or exception text."""


def require(value, code):
    if not value:
        raise AuditError(code)


def private_json(path):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'r') as handle:
        info = os.fstat(handle.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_mode & 0o077 == 0, 'private-input-mode-required')
        require(info.st_size <= 32 * 1024 * 1024, 'private-input-size-cap')
        return json.load(handle)



class Comparison:
    def __init__(self, snapshot, key, max_cells=200000, seconds=30):
        require(isinstance(key, bytes) and len(key) >= 32, 'private-key-too-short')
        require(snapshot.get('schemaVersion') == 1, 'unknown-snapshot-schema')
        require(snapshot.get('complete') is True, 'incomplete-extraction')
        require(snapshot.get('restoredSnapshot') is True, 'restored-snapshot-required')
        require(re.fullmatch('[0-9a-f]{64}', snapshot.get('snapshotSHA256', '')), 'snapshot-identity-required')
        require(re.fullmatch('[0-9a-f]{40}', snapshot.get('sourceCommit', '')), 'source-identity-required')
        require(type(snapshot.get('allowPublic')) is bool, 'public-policy-setting-required')
        require(1 <= seconds <= 300 and 1 <= max_cells <= 2000000, 'invalid-comparison-budget')
        require(re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z', snapshot.get('snapshotWatermark', '')), 'watermark-required')
        self.snapshot, self.key = snapshot, key
        self.deadline = time.monotonic() + seconds
        self.max_cells = max_cells
        self.projects = self.unique(snapshot['projects'], 'phid')
        self.users = self.unique(snapshot['users'], 'phid')
        require('anonymous' not in self.users and 'nonmember' not in self.users, 'reserved-subject-id')
        self.users.update(anonymous={'phid': None, 'isDisabled': 0, 'isApproved': 1, 'isAdmin': 0},
                          nonmember={'phid': 'AUDIT-NONMEMBER', 'isDisabled': 0, 'isApproved': 1, 'isAdmin': 0})
        self.direct = {p: set() for p in self.projects}
        self.materialized = {p: set() for p in self.projects}
        self.issues = Counter()
        seen = set()
        for edge in snapshot['edges']:
            identity = (edge['src'], int(edge['type']), edge['dst'])
            require(identity not in seen, 'duplicate-edge')
            seen.add(identity)
            if identity[1] not in (13, 60):
                continue
            if edge['src'] not in self.projects or edge['dst'] not in self.users:
                self.issues['orphan-membership-edge'] += 1
                continue
            (self.direct if identity[1] == 13 else self.materialized)[edge['src']].add(edge['dst'])
        for row in self.projects.values():
            require(all(k in row for k in ('parentProjectPHID', 'milestoneNumber', 'hasSubprojects', 'isMembershipLocked', 'spacePHID', 'viewPolicy', 'editPolicy', 'joinPolicy')), 'incomplete-project-row')
            require(all(type(row[k]) is int and row[k] in (0, 1) for k in ('hasSubprojects', 'isMembershipLocked')), 'invalid-project-flags')
            require(row['parentProjectPHID'] is None or isinstance(row['parentProjectPHID'], str), 'invalid-parent')
        for row in self.users.values():
            require(all(type(row[k]) is int and row[k] >= 0 for k in ('isAdmin', 'isDisabled', 'isApproved')), 'invalid-subject-lifecycle')
        require(type(snapshot.get('spaceCount', 0)) is int and snapshot.get('spaceCount', 0) >= 0, 'invalid-space-inventory')
        self.chains = {}
        for phid in self.projects:
            chain, node = [], phid
            while node is not None:
                self.budget()
                if node not in self.projects or node in chain or len(chain) >= 64:
                    self.issues['missing-cyclic-or-deep-ancestor'] += 1
                    chain = None
                    break
                chain.append(node)
                node = self.projects[node]['parentProjectPHID']
            self.chains[phid] = chain
        self.members_cache = {}
        self.cache = {}

    @staticmethod
    def unique(rows, key):
        result = {}
        for row in rows:
            require(isinstance(row.get(key), str) and row[key] and row[key] not in result, 'duplicate-or-invalid-identity')
            result[row[key]] = row
        return result

    def pseudonym(self, kind, identity):
        return kind + '-' + hmac.new(self.key, (kind + ':' + identity).encode(), hashlib.sha256).hexdigest()[:24]

    def budget(self):
        require(time.monotonic() <= self.deadline, 'comparison-timeout')

    def members(self, phid, model):
        cache_key = (phid, model)
        if cache_key in self.members_cache:
            return self.members_cache[cache_key]
        if self.chains[phid] is None:
            return None
        project = self.projects[phid]
        parent = project['parentProjectPHID']
        if project['milestoneNumber'] is not None:
            result = self.members(parent, model) if parent else None
        elif MODELS[model][2] == 'materialized':
            result = self.materialized[phid]
        elif MODELS[model][2] == 'direct':
            result = self.direct[phid]
        else:
            result = set(self.direct[phid])
            for child, chain in self.chains.items():
                self.budget()
                if chain is None:
                    result = None
                    break
                if phid in chain and self.projects[child]['milestoneNumber'] is None:
                    result.update(self.direct[child])
        self.members_cache[cache_key] = result
        return result

    def policy(self, policy, subject, capability, phid, model):
        # Values come from the native PHP rule adapter. Missing/unsupported
        # values remain unknown; raw project/custom edges are not substitutes.
        policy = policy or 'users'
        identity = next(k for k, v in self.users.items() if v is subject)
        value = self.snapshot.get('nativePolicyTable', {}).get(identity, {}).get(policy, {}).get(capability)
        require(value is None or type(value) is bool, 'invalid-native-rule-value')
        return value

    @staticmethod
    def both(a, b):
        if a is False or b is False:
            return False
        return None if a is None or b is None else True

    def evaluate(self, phid, subject_id, model, capability):
        self.budget()
        key = (phid, subject_id, model, capability)
        if key in self.cache:
            return self.cache[key]
        subject, p = self.users[subject_id], self.projects[phid]
        if self.chains[phid] is None:
            return None
        members = self.members(phid, model)
        if capability == 'member-index':
            return None if members is None else bool(subject['phid'] and subject['phid'] in members)
        if subject['isDisabled'] or not subject['isApproved']:
            return False
        if p.get('spacePHID') or self.snapshot.get('spaceCount', 0):
            return None
        parent = p['parentProjectPHID']
        if p['milestoneNumber'] is not None:
            if not parent:
                return None
            if capability == 'leave':
                return False
            return self.evaluate(parent, subject_id, model, capability)
        if capability == 'leave':
            # Preview only; transaction/editor and HTTP/session guards remain separate.
            support = not bool(p['hasSubprojects'])
            result = self.both(self.evaluate(phid, subject_id, model, 'view'),
                               self.evaluate(phid, subject_id, model, 'member-index'))
            result = self.both(result, support)
            if p['isMembershipLocked']:
                result = self.both(result, self.evaluate(phid, subject_id, model, 'edit'))
        else:
            automatic = False
            if capability == 'view':
                automatic = None if members is None else bool(subject['phid'] and subject['phid'] in members)
            elif capability == 'edit' and parent and not MODELS[model][1]:
                automatic = self.evaluate(parent, subject_id, model, 'edit')
            elif capability == 'join':
                automatic = self.evaluate(phid, subject_id, model, 'edit')
            base = self.policy(p[capability + 'Policy'], subject, capability, phid, model)
            result = True if automatic is True or base is True else None if automatic is None or base is None else False
            if capability == 'view' and parent and not MODELS[model][0]:
                result = self.both(result, self.evaluate(parent, subject_id, model, 'view'))
        self.cache[key] = result
        return result

    def run(self, candidate):
        require(candidate in MODELS and candidate != 'native-v1', 'unknown-candidate-model')
        cells = len(self.projects) * len(self.users) * len(CAPABILITIES)
        require(cells <= self.max_cells, 'comparison-cell-cap')
        changes, counts = [], {c: Counter() for c in CAPABILITIES}
        ambiguous = []
        for project in sorted(self.projects):
            for user in sorted(self.direct[project]):
                ambiguous.append({'project': self.pseudonym('project', project),
                                  'subject': self.pseudonym('subject', user),
                                  'reason': 'historical-direct-edge-origin-unknown-preserve'})
            for user in sorted(self.users):
                for capability in CAPABILITIES:
                    before = self.evaluate(project, user, 'native-v1', capability)
                    after = self.evaluate(project, user, candidate, capability)
                    status = 'unknown' if before is None or after is None else 'unchanged' if before == after else 'newly-granted' if after else 'newly-denied'
                    counts[capability][status] += 1
                    if status != 'unchanged':
                        changes.append({'project': self.pseudonym('project', project),
                                        'subject': self.pseudonym('subject', user),
                                        'capability': capability, 'before': before, 'after': after,
                                        'classification': status})
        unknown = sum(c['unknown'] for c in counts.values())
        if not self.projects:
            self.issues['no-comparable-project-data'] += 1
        complete = not unknown and not self.issues
        summary = {'schemaVersion': 1, 'baselineModel': 'native-v1', 'candidateModel': candidate,
                   'sourceCommit': self.snapshot['sourceCommit'], 'snapshotSHA256': self.snapshot['snapshotSHA256'],
                   'snapshotWatermark': self.snapshot.get('snapshotWatermark', 'unspecified'),
                   'dataExtractionComplete': True, 'evaluationComplete': complete,
                   'scope': 'Project policy-layer and membership previews, not effective route authorization.',
                   'projects': len(self.projects),
                   'ordinaryChildProjects': sum(p['parentProjectPHID'] is not None and p['milestoneNumber'] is None for p in self.projects.values()),
                   'milestones': sum(p['milestoneNumber'] is not None for p in self.projects.values()),
                   'subjects': len(self.users), 'comparisonCells': cells,
                   'counts': {cap: {kind: count[kind] for kind in ('newly-granted', 'newly-denied', 'unchanged', 'unknown')} for cap, count in counts.items()},
                   'integrityIssues': dict(sorted(self.issues.items())), 'ambiguousHistoricalDirectEdges': len(ambiguous),
                   'routeVerification': {route: 'not-verified' for route in ROUTES},
                   'migrationReady': False, 'recommendation': 'retain-native-pending-owner-and-route-review',
                   'writesPerformed': 0}
        source_summary, source_rows = membership_inventory(self)
        summary['membershipSources'] = source_summary
        return summary, {'schemaVersion': 1, 'deltas': changes, 'ambiguities': ambiguous,
                         'membershipSources': source_rows}


def write_new(path, value):
    # No symlink traversal / overwrite of another report or secret.
    data = (json.dumps(value, sort_keys=True, ensure_ascii=False, indent=2) + '\n').encode()
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as handle:
        handle.write(data)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--snapshot', required=True)
    parser.add_argument('--key-file', required=True)
    parser.add_argument('--candidate', required=True, choices=tuple(m for m in MODELS if m != 'native-v1'))
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--max-cells', type=int, default=200000)
    parser.add_argument('--seconds', type=int, default=30)
    args = parser.parse_args()
    # Refuse a pre-existing output directory before reading private inputs.
    args.output.mkdir(mode=0o700, parents=False, exist_ok=False)
    try:
        key = private_json(args.key_file)
        require(set(key) == {'hmacKey'} and re.fullmatch('[0-9a-f]{64}', key['hmacKey']), 'invalid-key-file')
        snapshot = private_json(args.snapshot)
        native = subprocess.run(['php', str(Path(__file__).with_name('native_rules.php'))],
                                input=json.dumps(dict(snapshot, maxCells=args.max_cells)),
                                text=True, capture_output=True, timeout=args.seconds)
        require(native.returncode == 0, 'native-rule-evaluation-failed')
        snapshot['nativePolicyTable'] = json.loads(native.stdout)
        comparison = Comparison(snapshot, bytes.fromhex(key['hmacKey']), args.max_cells, args.seconds)
        summary, restricted = comparison.run(args.candidate)
        write_new(args.output / 'summary.json', summary)
        write_new(args.output / 'restricted-review.json', restricted)
        return 0 if summary['evaluationComplete'] else 2
    except (AuditError, KeyError, TypeError, ValueError, OSError, RecursionError, subprocess.SubprocessError):
        write_new(args.output / 'failure.json', {'complete': False, 'migrationReady': False,
                                               'reason': 'audit-refused-or-incomplete', 'writesPerformed': 0})
        return 2


if __name__ == '__main__':
    raise SystemExit(main())
