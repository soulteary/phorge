#!/usr/bin/env python3
"""Build offline ASCII PSL rules; idna is a maintainer-only build dependency."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import idna

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--upstream-directory', type=Path, required=True)
args = parser.parse_args()
if idna.__version__ != '3.19':
    raise RuntimeError('This reproducible snapshot requires maintainer-only idna 3.19.')
root = Path(__file__).resolve().parents[2]
upstream = args.upstream_directory
revision = json.loads((upstream / 'revision.json').read_text())['revision']
assert re.fullmatch('[a-f0-9]{40}', revision)
source = (upstream / 'public_suffix_list.official.dat').read_bytes()
reference = (upstream / 'public_suffix_list.dat').read_bytes()

def ascii_domain(value):
    return idna.encode(value, uts46=True, std3_rules=True,
                       transitional=False).decode('ascii').lower()

def normalize(raw):
    rules = set()
    for line in raw.decode('utf-8').splitlines():
        line = line.strip()
        if not line or line.startswith('//'):
            continue
        prefix = '!' if line.startswith('!') else '*.' if line.startswith('*.') else ''
        value = ascii_domain(line[len(prefix):])
        assert len(value) <= 253 and re.fullmatch('[a-z0-9.-]+', value)
        rules.add(prefix + value)
    return sorted(rules)

rules = normalize(source)
assert rules == normalize(reference), 'Canonical list differs from the pinned upstream revision.'
assert len(rules) > 5000 and '*.ck' in rules and '!www.ck' in rules and 'github.io' in rules
target = root / 'resources/governance/publicsuffix'
target.mkdir(parents=True, exist_ok=True)
data = {'schema': 1, 'source': 'https://publicsuffix.org/list/public_suffix_list.dat',
        'upstream_revision': revision, 'source_sha256': hashlib.sha256(source).hexdigest(),
        'include_private': True, 'idna_build_version': idna.__version__, 'rules': rules}
payload = (json.dumps(data, ensure_ascii=True, indent=2) + '\n').encode()
(target / 'rules.json').write_bytes(payload)
# Normalize trailing whitespace only; preserve the complete upstream license.
(target / 'LICENSE').write_text('\n'.join(
    line.rstrip() for line in (upstream / 'LICENSE').read_text().splitlines()) + '\n')

cases = []
for match in re.finditer(r"^checkPublicSuffix\((null|'[^']*'), (null|'[^']*')\);", (upstream / 'test_psl.txt').read_text(), re.M):
    pair = []
    for literal in match.groups():
        value = None if literal == 'null' else literal[1:-1]
        if value and not value.startswith('.'):
            value = ascii_domain(value)
        pair.append(value)
    cases.append(pair)
assert len(cases) >= 50
(target / 'test-cases.json').write_text(json.dumps({
    'license': 'CC0-1.0', 'upstream_revision': revision,
    'source_sha256': hashlib.sha256((upstream / 'test_psl.txt').read_bytes()).hexdigest(),
    'cases': cases}, indent=2) + '\n')
print(json.dumps({'rules': len(rules), 'upstream_cases': len(cases),
                  'rules_file_sha256': hashlib.sha256(payload).hexdigest()}))
