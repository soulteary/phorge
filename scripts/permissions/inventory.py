#!/usr/bin/env python3
"""Read source only: never bootstrap PHP, open a database or follow a service URL."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess

ROOT=Path(__file__).resolve().parents[2]


def collect(root=ROOT):
    root=Path(root)
    catalog=json.loads((root/'scripts/permissions/consumers.json').read_text())
    pattern=re.compile(catalog['patterns'])
    discovered={}
    for path in sorted((root/'src').rglob('*.php')):
        if path.is_symlink(): raise ValueError('Symlinked source refused.')
        text=path.read_text()
        hits=[i for i,line in enumerate(text.splitlines(),1) if pattern.search(line)]
        if hits:discovered[str(path.relative_to(root))]=hits
    entries=catalog['entries'];paths=[row['path'] for row in entries]
    if len(paths)!=len(set(paths)):raise ValueError('Duplicate consumer definition.')
    if {r['path'] for r in entries if r['discovery']}!=set(discovered):
        raise ValueError('Membership references changed; review the consumer catalog.')
    rows=[]
    for row in entries:
        path=root/row['path']
        if not row['path'].startswith('src/') or '..' in Path(row['path']).parts or path.is_symlink():
            raise ValueError('Invalid reviewed source path.')
        for key in ('kind','meaning','authority','reviewStatus'):
            if not row.get(key):raise ValueError('Incomplete consumer review.')
        rows.append(dict(row,sha256=hashlib.sha256(path.read_bytes()).hexdigest(),
                         matchingLines=discovered.get(row['path'],[])))
    return {'schemaVersion':1,'scope':catalog['scope'],'sourceReadsOnly':True,
            'databaseConnectionsOpened':0,'phpBootstrapped':False,'runtimeAuthorizationCertified':False,
            'patterns':catalog['patterns'],'consumerCount':len(rows),'discoveredFiles':len(discovered),
            'consumers':rows,'historicalEdgeOriginsEstablished':0,'writesPerformed':0}


def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--output',type=Path,required=True)
    p.add_argument('--require-clean',action='store_true');args=p.parse_args()
    before=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
    dirty=bool(subprocess.check_output(['git','status','--porcelain','--untracked-files=all'],cwd=ROOT,text=True).strip())
    if args.require_clean and dirty:raise ValueError('Clean source required.')
    report=collect();report.update(sourceCommit=before,worktreeDirty=dirty)
    after=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
    if after!=before:raise ValueError('Source revision changed.')
    with args.output.open('x') as handle:json.dump(report,handle,indent=2);handle.write('\n')
    print('Reviewed membership consumer inventory:',report['consumerCount'])

if __name__=='__main__':main()
